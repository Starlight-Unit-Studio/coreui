<?php
declare(strict_types=1);
// ─────────────────────────────────────────────────────────────────────────────
// Ember CoreUI SSE Stream-Endpunkt mit sicherem Denkstatus
//
// Generiert Embers Antwort im Console-Channel mit Ollama stream:true. Rohe
// message.thinking-Deltas bleiben strikt serverintern. An die Console gehen nur
// deterministische Fortschrittsstufen. Die Antwort wird erst nach vollstaendiger
// Generierung und serverseitiger Pruefung als ein finales Ereignis ausgeliefert.
// Spiegelt exakt den synchronen send-Hintergrund-Flow aus chat.php - nur gestreamt.
//
// WICHTIG: rein additiv. Faellt etwas aus, generiert dieser Endpunkt die Antwort
// serverseitig trotzdem zu Ende (ignore_user_abort) und legt sie in der DB ab -
// die Console kann sie dann per normalem Poll abholen (Fallback).
//
// stream:true ist UNABHAENGIG von think:true - Modelfile-Thinking bleibt unberuehrt.
// ─────────────────────────────────────────────────────────────────────────────

// chat.php als Funktionsbibliothek einbinden (Dispatch wird per STU_CHAT_LIB uebersprungen).
define('STU_CHAT_LIB', 1);
require __DIR__ . '/chat.php';

// ── SSE-Ausgabe vorbereiten: jegliches Buffering AUS, lange Laufzeit erlauben ──
@ini_set('zlib.output_compression', '0');
@ini_set('output_buffering', '0');
@ini_set('implicit_flush', '1');
while (ob_get_level() > 0) { @ob_end_flush(); }
ignore_user_abort(true);
@set_time_limit(0);

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('X-Accel-Buffering: no'); // verhindert Proxy-/Nginx-Pufferung
header('Connection: keep-alive');

function sse_send(string $event, $data): void {
  echo 'event: ' . $event . "\n";
  echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
  @flush();
}
function sse_comment(string $c): void { echo ': ' . $c . "\n\n"; @flush(); }

// ── Auth + PDO (liest Session) ──
try {
  $uid = stu_require_user_id();
  $pdo = stu_pdo();
  stu_enforce_maintenance($pdo, (int)$uid);
} catch (Throwable $e) {
  sse_send('error', ['error' => 'auth']);
  exit;
}

// ── Turn-Referenz (EventSource => GET) ──
$character_id = (string)($_GET['character_id'] ?? '');
$afterId      = (int)($_GET['after_id'] ?? 0);
$sessionId    = coreui_console_session_normalize_id($_GET['session_id'] ?? '');
$channel      = 'console';

// Die eigentliche Nachricht liegt bereits durch den authentifizierten POST in
// der CoreUI-Datenbank. Sie wird nicht noch einmal in die EventSource-URL
// geschrieben. Dadurch bleiben lange Eingaben unter den Proxy-Headergrenzen
// und vertraulicher Text landet nicht in Apache- oder Nginx-Zugriffslogs.
if ($afterId <= 0 || trim($character_id) === '' || $sessionId === '') {
  sse_send('error', ['error' => 'invalid_turn']);
  exit;
}
try {
  coreui_console_session_require($pdo, (int)$uid, $sessionId, true);
  $stTurn = $pdo->prepare(
    "SELECT message FROM stu_chat_messages
     WHERE id = ? AND channel = 'console' AND user_id = ? AND character_id = ?
       AND session_id = ?
     LIMIT 1"
  );
  $stTurn->execute([$afterId, (int)$uid, $character_id, $sessionId]);
  $userMsgRaw = $stTurn->fetchColumn();
} catch (Throwable $eTurn) {
  sse_send('error', ['error' => 'turn_lookup_failed']);
  exit;
}
if (!is_string($userMsgRaw) || trim($userMsgRaw) === '') {
  sse_send('error', ['error' => 'turn_not_found']);
  exit;
}
$message = trim(chat_clean_console_message($userMsgRaw));

// v1.1.1.94: Bild-Marker aus der Nachricht ziehen. Die Console hatte bisher KEINEN
// Vision-Pfad: an ember_build_chat_prompt() und ember_generate_reply() wurde beide Male
// hart null uebergeben, und der [img:...]-Marker blieb unentfernt im Prompt stehen.
// Ember sah dadurch nur den Marker und meldete "das bild wird nicht angezeigt".
$imageUrl = null;
if (preg_match('~\[img:([^\]]{1,512})\]~i', $message, $im)) {
  $cand = trim((string)$im[1]);
  // Nur lokale Medienpfade zulassen. Eine untergeschobene fremde URL wuerde den
  // Server sonst dazu bringen, ein beliebiges Ziel abzurufen (SSRF).
  if (preg_match('~^/(?:[^/]+/)*assets/chat_media/[A-Za-z0-9._-]+$~', $cand)) {
    $imageUrl = $cand;
  }
  $message = trim(str_replace((string)$im[0], '', $message));
}

// Ein Bild ohne Begleittext ist eine gueltige Nachricht.
if ($message === '' && $imageUrl === null) { sse_send('error', ['error' => 'empty']); exit; }

// Video-Anhaenge und Scan-PDFs benoetigen den Vision-Pfad. Das muss VOR der
// Generierung feststehen, weil der Streaming-Pfad kein images-Feld sendet.
// PDFs mit Textebene werden im synchronen Pfad weiterhin direkt als Text gelesen.
$isVisionTurn = ($imageUrl !== null);
if (!$isVisionTurn && preg_match('~\[file:([a-f0-9]{32})\]~i', $message, $fm)) {
  if (function_exists('ember_attach_needs_vision_path')
      && ember_attach_needs_vision_path($pdo, strtolower((string)$fm[1]))) {
    $isVisionTurn = true;
  }
}

// ── Charakter laden (Eigentuemer-Pruefung wie im send-Handler) ──
try {
  $char = chat_require_character($pdo, (int)$uid, $character_id);
} catch (Throwable $e) {
  sse_send('error', ['error' => 'character']);
  exit;
}

// Session-Lock JETZT freigeben - der Stream laeuft minutenlang. Sonst blockiert jeder
// andere Request dieses Users (Poll/Presence/Senden) bis Stream-Ende (PHP session lock).
if (session_status() === PHP_SESSION_ACTIVE) { @session_write_close(); }

sse_comment('open');

// ── Generierung (gespiegelt vom send-Hintergrund-Flow in chat.php) ──
$start = microtime(true);

// Per-Turn-Lock (Datei-Lock, Dedup) - identisch zum synchronen Pfad.
$turnLock = ember_generation_lock_acquire($channel, $afterId, $char, $message);
if ($turnLock === false) {
  // Es laeuft bereits eine Generierung fuer diesen Turn (Doppel-Trigger) - Client pollt.
  sse_send('busy', ['note' => 'generation_in_progress']);
  exit;
}

$emberMsgId  = 0;
$replyFinal  = '';
$thinkingAll = '';
$thinkingPublic = '';
$browseJobId = 0;

try {
  // Idempotenz: Hat ein frueherer Request fuer GENAU diesen Turn schon geantwortet?
  // EventSource reconnectet bei Verbindungsabbruch automatisch -> sonst zweite Generierung
  // = doppelte Antwort. Nur eine Antwort mit derselben Sitzung und derselben
  // Turn-Referenz darf diesen Request als bereits abgeschlossen markieren.
  if ($afterId > 0) {
    try {
      $emberCid = ember_character_id();
      $stExist = $pdo->prepare(
        "SELECT id, message, thinking_content FROM stu_chat_messages
         WHERE channel = 'console' AND user_id = ? AND character_id = ?
           AND session_id = ? AND reply_to_id = ?
         ORDER BY id DESC LIMIT 1"
      );
      $stExist->execute([(int)$uid, $emberCid, $sessionId, $afterId]);
      $existing = $stExist->fetch(PDO::FETCH_ASSOC);
      if ($existing && isset($existing['id'])) {
        $existingText = ember_sanitize_public_reply((string)($existing['message'] ?? ''), '');
        if ($existingText === '') {
          $existingText = 'Diese ältere Antwort wurde vom CoreUI-Sicherheitsfilter ausgeblendet.';
        }
        sse_send('done', [
          'text'     => $existingText,
          'thinking' => ember_public_thinking_from_storage(
            isset($existing['thinking_content']) ? (string)$existing['thinking_content'] : null
          ),
          'elapsed'  => 0,
          'id'       => (int)$existing['id'],
          'session_id' => $sessionId,
          'reply_to_id' => $afterId,
        ]);
        ember_generation_lock_release($turnLock);
        exit;
      }
    } catch (Throwable $eIdem) {
      // z.B. Spalte thinking_content fehlt -> Idempotenz ueberspringen, normal generieren.
    }
  }

  // Console-AFK: ab hier wird WIRKLICH generiert (Idempotenz-Treffer sind oben schon raus).
  // -> Ember in der GLOBAL-Nickliste als abwesend (💤) zeigen, solange sie hier antwortet.
  // TTL knapp ueber dem Ollama-Cap (900s): bleibt die ganze Generierung ueber AFK und heilt
  // bei hartem Abbruch (kein finally) von selbst. Der finally-Block raeumt im Normalfall sofort auf.
  ember_console_afk_set($pdo, time() + 930);
  // Gewohnte AFK-Systemmeldung im Global-Chat (wie beim Browse-Worker). Selbst-dedupliziert:
  // bei Mehrfach-Turns in der Console wird sie nur einmal pro Cooldown gepostet -> kein Spam.
  ember_console_afk_announce($pdo);

  ember_prepare_background_runtime();
  sse_send('progress', [
    'stage' => 'request',
    'text'  => ember_public_thinking_status('request'),
  ]);

  // v1.1.1.94: Vision-Turns nehmen NICHT den Streaming-Pfad. console_stream_ollama()
  // baut den Payload ohne images-Feld, ein Bild kaeme dort gar nicht an. Stattdessen
  // faellt der Turn unten in den Sync-Pfad, der den fertigen Vision-Zweig hat.
  // Der Prompt-Aufbau wird dann ebenfalls uebersprungen: er waere ungenutzt und
  // wuerde nur eine RAG-Abfrage kosten.
  if ($isVisionTurn) {
    sse_comment('vision-sync');
    sse_send('progress', [
      'stage' => 'context',
      'text'  => ember_public_thinking_status('context'),
    ]);
    $acc = ['thinking' => '', 'content' => ''];
  } else {
    // Prompt bauen - identische Ember-Stimme wie ueberall (shared builder).
    $built = ember_build_chat_prompt($pdo, $char, $message, null, 'console', (int)$uid, $sessionId);
    $sys   = (string)($built['sys'] ?? '');
    $u     = (string)($built['u'] ?? '');
    $model = (string)($built['model'] ?? ember_model());
    sse_send('progress', [
      'stage' => 'context',
      'text'  => ember_public_thinking_status('context'),
    ]);
    sse_send('progress', [
      'stage' => 'compose',
      'text'  => ember_public_thinking_status('compose'),
    ]);

    // Streaming-Call: akkumuliert alle Modellfragmente ausschliesslich serverintern.
    // Erst die vollstaendige, validierte Antwort darf den Browser erreichen.
    $acc   = console_stream_ollama($model, $sys, $u);
  }
  $thinkingAll = (string)($acc['thinking'] ?? '');
  $contentAll  = (string)($acc['content'] ?? '');

  // Inline-<think> (aeltere Modelle) beruecksichtigen, falls message.thinking leer war.
  if ($thinkingAll === '' && (str_contains($contentAll, '<think>') || str_contains($contentAll, '</think>'))) {
    if (preg_match('~<think>(.*?)</think>~si', $contentAll, $tm)) {
      $thinkingAll = trim((string)$tm[1]);
    }
  }
  sse_send('progress', [
    'stage' => 'validate',
    'text'  => ember_public_thinking_status('validate'),
  ]);
  $reply = ember_sanitize_public_reply($contentAll, $thinkingAll);

  // Tool-Marker ([WEB:]/[BROWSE:]/[PY]) werden weiterhin vom gemeinsamen
  // Werkzeugpfad verarbeitet. Ein reines Tokenlimit setzt dagegen gezielt am
  // vorhandenen Antworttext fort und startet nicht mehr die ganze Anfrage neu.
  $hasMarker = (ember_extract_web_marker($reply) !== null)
            || (ember_extract_browse_marker($reply) !== null)
            || (function_exists('ember_py_extract_marker') && ember_py_extract_marker($reply) !== null);

  if (!$isVisionTurn
      && !$hasMarker
      && $reply !== ''
      && !empty($acc['ok'])
      && !empty($acc['done'])
      && !empty($acc['truncated'])) {
    sse_send('progress', [
      'stage' => 'compose',
      'text'  => ember_public_thinking_status('compose'),
    ]);
    $continued = console_stream_continue_truncated($model, $sys, $u, $reply, $thinkingAll, $acc);
    $reply = (string)($continued['reply'] ?? $reply);
    $thinkingAll = (string)($continued['thinking'] ?? $thinkingAll);
    $acc['ok'] = !empty($continued['ok']);
    $acc['done'] = !empty($continued['done']);
    $acc['truncated'] = !empty($continued['truncated']);
    $hasMarker = (ember_extract_web_marker($reply) !== null)
              || (ember_extract_browse_marker($reply) !== null)
              || (function_exists('ember_py_extract_marker') && ember_py_extract_marker($reply) !== null);
  }

  $streamUnsafe = empty($acc['ok'])
    || empty($acc['done']);
  if ($hasMarker || $reply === '' || $streamUnsafe) {
    sse_send('progress', [
      'stage' => 'tool',
      'text'  => ember_public_thinking_status('tool'),
    ]);
    // v1.1.1.94: $imageUrl statt hart null -> Vision funktioniert jetzt auch in der Console.
    $fallback = ember_generate_reply($pdo, $char, $message, $imageUrl, 'console', (int)$uid, $sessionId);
    $enqueued = ember_browse_consume_request($pdo, $channel, (int)$uid, $sessionId, $afterId);
    if ($enqueued) {
      // Job-ID fuer das Live-Browser-Fenster (Phase 3b) holen.
      // WICHTIG: NICHT auf status IN ('queued','running') filtern! Der Worker ist (seit .29) oft
      // schnell genug, den Job hier schon auf 'done' zu haben -> dann faende die Abfrage nichts,
      // browseJobId bliebe 0 und das Fenster ginge nie auf. Der Job wurde GERADE in diesem Turn
      // angelegt (consume_request lief eben), also nehmen wir den juengsten Job dieses Users/Channels
      // unabhaengig vom Status; der Recency-Filter schuetzt vor alten Jobs. Die SSE spielt fuer einen
      // bereits 'done'-Job die gespeicherten Steps/Frames beim Verbinden nach.
      try {
        $sjb = $pdo->prepare(
          "SELECT id FROM stu_ember_browse_jobs
           WHERE channel = 'console' AND recipient_uid = ?
             AND session_id = ? AND trigger_message_id = ?
             AND created_at >= (NOW() - INTERVAL 5 MINUTE)
           ORDER BY id DESC LIMIT 1"
        );
        $sjb->execute([(int)$uid, $sessionId, $afterId]);
        $browseJobId = (int)($sjb->fetchColumn() ?: 0);
      } catch (Throwable $eJob) {}
    }
    if (!empty($GLOBALS['STU_EMBER_LAST_THINKING'])) {
      $thinkingAll = (string)$GLOBALS['STU_EMBER_LAST_THINKING'];
    }
    $reply = is_string($fallback) ? trim($fallback) : '';
  }

  // Auch ein synchroner Werkzeug-Fallback passiert vor DB und Client dieselbe Schranke.
  if ($reply !== '') {
    $reply = ember_sanitize_public_reply($reply, $thinkingAll);
  }

  // Safety-Strip etwaiger Marker (wie im normalen Pfad).
  if ($reply !== '') {
    $reply = trim((string)preg_replace('~\[(?:WEB|BROWSE):[^\]]*\]~i', '', $reply));
    $reply = trim((string)preg_replace('~\[/?PY\]~i', '', $reply));
  }
  $replyFinal = $reply;
  $thinkingPublic = ($replyFinal !== '') ? ember_public_thinking_status('complete') : '';

  if ($replyFinal !== '') {
    $emberMsgId = ember_insert(
      $pdo,
      $replyFinal,
      $channel,
      $thinkingPublic,
      (int)$uid,
      $sessionId,
      $afterId
    );
    // Safety: lastInsertId() kann (selten) 0 liefern -> 'done' schickte id=0, die Client-Dedup
    // (streamedIds/since_id) liefe leer und der Hintergrund-Poll wuerde die Zeile doppelt rendern.
    // Daher die ID notfalls direkt nachschlagen.
    if ($emberMsgId <= 0) {
      try {
        $sid = $pdo->prepare(
          "SELECT id FROM stu_chat_messages
           WHERE channel='console' AND user_id=? AND character_id=?
             AND session_id=? AND reply_to_id=?
           ORDER BY id DESC LIMIT 1"
        );
        $sid->execute([(int)$uid, ember_character_id(), $sessionId, $afterId]);
        $emberMsgId = (int)($sid->fetchColumn() ?: 0);
      } catch (Throwable $eId) {}
    }
    if (!ember_last_call_is_guardrail()) {
      ember_after_insert_tasks($pdo, $char, $message, $replyFinal);
    }
  }

  $elapsed = (int)round(microtime(true) - $start);
  sse_send('done', [
    'text'          => $replyFinal,
    'thinking'      => $thinkingPublic,
    'elapsed'       => $elapsed,
    'id'            => $emberMsgId,
    'browse_job_id' => $browseJobId,
    'session_id'    => $sessionId,
    'reply_to_id'   => $afterId,
  ]);
} catch (Throwable $e) {
  if (function_exists('stu__log_error')) {
    stu__log_error([
      'type'    => 'console_stream_exception',
      'message' => $e->getMessage(),
      'line'    => $e->getLine(),
    ]);
  }
  sse_send('error', ['error' => 'exception']);
} finally {
  ember_generation_lock_release($turnLock ?? null);
  // Console-AFK sofort zuruecknehmen -> Ember erscheint im Global wieder online.
  // (Bei [BROWSE:] haelt der Worker ueber sein eigenes Browse-Flag unabhaengig weiter AFK.)
  if (isset($pdo) && $pdo instanceof PDO) { ember_console_afk_clear($pdo); }
}
exit;


// ─────────────────────────────────────────────────────────────────────────────
// Streaming-Ollama-Call: NDJSON -> SSE. Optionen spiegeln ember_call_ollama()
// (Gemma4-Defaults), damit Embers Stimme im Stream identisch zum Sync-Pfad ist.
// Setzt KEIN 'think' - Modelfile-Thinking gilt; Gemma4 liefert thinking parallel.
// ─────────────────────────────────────────────────────────────────────────────
function console_stream_ollama(string $model, string $systemPrompt, string $userPrompt): array {
  $url       = ember_url();
  $keepAlive = ember_keep_alive();

  $options = [
    'num_thread'     => ember_num_thread(),
    'temperature'    => ember_temperature(),
    'top_p'          => ember_top_p(),
    'repeat_penalty' => ember_repeat_penalty(),
    'num_predict'    => ember_num_predict_for_model($model),
    'num_ctx'        => ember_num_ctx_for_model($model),
    'stop'           => ember_stop_tokens_for_model($model),
    'seed'           => ember_seed_for_model($model),
    'top_k'          => ember_top_k(),
    'repeat_last_n'  => ember_repeat_last_n(),
  ];
  if (!defined('STU_EMBER_TEMPERATURE'))    $options['temperature']    = 0.80;
  if (!defined('STU_EMBER_TOP_P'))          $options['top_p']          = 0.95;
  if (!defined('STU_EMBER_TOP_K'))          $options['top_k']          = 64;
  if (!defined('STU_EMBER_REPEAT_PENALTY')) $options['repeat_penalty'] = 1.08;
  if (!defined('STU_EMBER_REPEAT_LAST_N'))  $options['repeat_last_n']  = 64;
  if (!defined('STU_EMBER_NUM_THREAD'))     $options['num_thread']     = 12;

  $messages = [];
  if (trim($systemPrompt) !== '') $messages[] = ['role' => 'system', 'content' => $systemPrompt];
  $messages[] = ['role' => 'user', 'content' => $userPrompt];

  $payload = [
    'model'    => $model,
    'stream'   => true,
    'messages' => $messages,
    'options'  => $options,
  ];
  if ($keepAlive !== null) $payload['keep_alive'] = $keepAlive;

  $flags = JSON_UNESCAPED_UNICODE;
  if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
  $body = json_encode($payload, $flags);

  $thinkingAcc = '';
  $contentAcc  = '';
  $buf         = '';
  $doneSeen    = false;
  $done        = false;
  $doneReason  = '';
  $evalCount   = 0;
  $promptEvalCount = 0;
  $apiError    = '';
  $lastHeartbeat = microtime(true);

  $consumeLine = static function (string $line) use (
    &$thinkingAcc,
    &$contentAcc,
    &$doneSeen,
    &$done,
    &$doneReason,
    &$evalCount,
    &$promptEvalCount,
    &$apiError
  ): void {
    $line = trim($line);
    if ($line === '') return;
    $j = json_decode($line, true);
    if (!is_array($j)) return;
    if (isset($j['error']) && is_string($j['error'])) $apiError = trim($j['error']);
    if (array_key_exists('done', $j)) {
      $doneSeen = true;
      $done = (bool)$j['done'];
    }
    if (isset($j['done_reason'])) $doneReason = trim((string)$j['done_reason']);
    if (isset($j['eval_count'])) $evalCount = max(0, (int)$j['eval_count']);
    if (isset($j['prompt_eval_count'])) $promptEvalCount = max(0, (int)$j['prompt_eval_count']);
    $msg = $j['message'] ?? null;
    if (!is_array($msg)) return;
    if (isset($msg['thinking']) && is_string($msg['thinking'])) $thinkingAcc .= $msg['thinking'];
    if (isset($msg['content']) && is_string($msg['content'])) $contentAcc .= $msg['content'];
  };

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS     => $body,
    CURLOPT_CONNECTTIMEOUT => 3,
    CURLOPT_TIMEOUT        => max(30, min(900, ember_timeout_for_model($model))),
    CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
    CURLOPT_NOSIGNAL       => true,
    CURLOPT_NOPROGRESS     => false,
    CURLOPT_XFERINFOFUNCTION => static function (
      $resource,
      $downloadTotal,
      $downloadNow,
      $uploadTotal,
      $uploadNow
    ) use (&$lastHeartbeat): int {
      $now = microtime(true);
      if (($now - $lastHeartbeat) >= 10.0) {
        $lastHeartbeat = $now;
        sse_send('heartbeat', ['ok' => true]);
      }
      return 0;
    },
    // Ollama streamt NDJSON: eine JSON-Zeile pro Token-Schritt.
    CURLOPT_WRITEFUNCTION  => static function ($ch, $chunk) use (&$buf, $consumeLine, &$lastHeartbeat) {
      $buf .= $chunk;
      while (($nl = strpos($buf, "\n")) !== false) {
        $line = substr($buf, 0, $nl);
        $buf  = substr($buf, $nl + 1);
        $consumeLine($line);
        $now = microtime(true);
        if (($now - $lastHeartbeat) >= 10.0) {
          $lastHeartbeat = $now;
          sse_send('heartbeat', ['ok' => true]);
        }
      }
      return strlen($chunk);
    },
  ]);
  $curlOk = curl_exec($ch);
  if (trim($buf) !== '') $consumeLine($buf);
  $curlErrno = curl_errno($ch);
  $curlError = curl_error($ch);
  $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  $numPredict = (int)($options['num_predict'] ?? 0);
  $reasonLower = strtolower($doneReason);
  $truncated = in_array($reasonLower, ['length', 'max_tokens', 'token_limit'], true)
    || ($numPredict > 0 && $evalCount >= $numPredict)
    || ($doneSeen && !$done);
  $ok = $curlOk !== false
    && $curlErrno === 0
    && $httpCode >= 200
    && $httpCode < 300
    && $apiError === ''
    && $doneSeen
    && $done;

  if (!$ok || $truncated) {
    ember_debug_log('console_stream_incomplete', [
      'http_code' => $httpCode,
      'curl_errno' => $curlErrno,
      'curl_error' => $curlError,
      'api_error' => $apiError,
      'done_seen' => $doneSeen ? 1 : 0,
      'done' => $done ? 1 : 0,
      'done_reason' => $doneReason,
      'eval_count' => $evalCount,
      'num_predict' => $numPredict,
    ]);
  }

  return [
    'thinking' => $thinkingAcc,
    'content' => $contentAcc,
    'ok' => $ok,
    'done' => $doneSeen && $done,
    'truncated' => $truncated,
    'done_reason' => $doneReason,
    'eval_count' => $evalCount,
    'prompt_eval_count' => $promptEvalCount,
  ];
}

function console_stream_continue_truncated(
  string $model,
  string $systemPrompt,
  string $userPrompt,
  string $partial,
  string $thinking,
  array $firstCall
): array {
  $reply = trim($partial);
  $thinkingAll = trim($thinking);
  $lastCall = $firstCall;
  $segments = 0;

  while ($reply !== '' && !empty($lastCall['truncated']) && $segments < 2) {
    $continuationPrompt = $userPrompt
      . "\n\n--- BEREITS ERZEUGTE ANTWORT ---\n"
      . $reply
      . "\n--- ENDE BEREITS ERZEUGTE ANTWORT ---\n"
      . "Setze exakt an der abgebrochenen Stelle fort. Wiederhole keinen bereits ausgegebenen Text. "
      . "Gib nur die Fortsetzung aus, ohne Analyse, Label, Einleitung oder Kommentar.";

    $next = console_stream_ollama($model, $systemPrompt, $continuationPrompt);
    $nextThinking = (string)($next['thinking'] ?? '');
    $nextReply = ember_sanitize_public_reply((string)($next['content'] ?? ''), $nextThinking);
    if (empty($next['ok']) || empty($next['done']) || $nextReply === '') {
      $lastCall = $next;
      break;
    }

    $reply = ember_join_continuation($reply, $nextReply);
    if (trim($nextThinking) !== '') {
      $thinkingAll .= ($thinkingAll !== '' ? "\n" : '') . trim($nextThinking);
    }
    $lastCall = $next;
    $segments++;
  }

  if (!empty($lastCall['truncated'])) {
    ember_debug_log('console_stream_continuation_limit', [
      'segments' => $segments,
      'done_reason' => (string)($lastCall['done_reason'] ?? ''),
      'eval_count' => (int)($lastCall['eval_count'] ?? 0),
    ]);
  }

  return [
    'reply' => $reply,
    'thinking' => $thinkingAll,
    'ok' => !empty($lastCall['ok']),
    'done' => !empty($lastCall['done']),
    'truncated' => !empty($lastCall['truncated']),
    'continuation_segments' => $segments,
  ];
}
