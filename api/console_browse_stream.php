<?php
declare(strict_types=1);
// ─────────────────────────────────────────────────────────────────────────────
// Ember CoreUI - private Live-Browser-Agentenansicht
// Eigenstaendiger SSE-Endpunkt fuer asynchrone Browse-Jobs.
//
// Tailt die JPEG-Frames und den kurzen Status EINES Browse-Jobs. Die Frames bleiben in
// der privaten Datenbank und werden nur nach Benutzer-/Job-Pruefung ausgeliefert.
// Reiner Lese-Endpunkt - startet/aendert nichts an der Generierung.
// ─────────────────────────────────────────────────────────────────────────────

define('STU_CHAT_LIB', 1);
require __DIR__ . '/chat.php';

@ini_set('zlib.output_compression', '0');
@ini_set('output_buffering', '0');
@ini_set('implicit_flush', '1');
while (ob_get_level() > 0) { @ob_end_flush(); }
ignore_user_abort(true);
@set_time_limit(0);

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('X-Accel-Buffering: no');
header('Connection: keep-alive');

function bsse_send(string $event, $data): void {
  echo 'event: ' . $event . "\n";
  echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
  @flush();
}
function bsse_comment(string $c): void { echo ': ' . $c . "\n\n"; @flush(); }

function bsse_emit_frames(PDO $pdo, int $jobId, int &$lastFrameId): int {
  $count = 0;
  try {
    $sf = $pdo->prepare(
      "SELECT id, step, b64, cx, cy, vw, vh, cursor_click, page_url, frame_label
       FROM stu_ember_browse_frames
       WHERE job_id = ? AND id > ? ORDER BY id ASC LIMIT 25"
    );
    $sf->execute([$jobId, $lastFrameId]);
    foreach ($sf->fetchAll(PDO::FETCH_ASSOC) as $row) {
      $lastFrameId = (int)$row['id'];
      $count++;
      bsse_send('frame', [
        'id'    => $lastFrameId,
        'step'  => (int)($row['step'] ?? 0),
        'b64'   => (string)($row['b64'] ?? ''),
        'cx'    => $row['cx'] !== null ? (float)$row['cx'] : null,
        'cy'    => $row['cy'] !== null ? (float)$row['cy'] : null,
        'vw'    => $row['vw'] !== null ? (int)$row['vw'] : null,
        'vh'    => $row['vh'] !== null ? (int)$row['vh'] : null,
        'click' => ((int)($row['cursor_click'] ?? 0) === 1),
        'url'   => (string)($row['page_url'] ?? ''),
        'label' => (string)($row['frame_label'] ?? ''),
      ]);
    }
  } catch (Throwable $e) {
    // Alte Installation waehrend eines Updates: Worker legt die Tabelle selbst an.
  }
  return $count;
}

try {
  $uid = stu_require_user_id();
  $pdo = stu_pdo();
} catch (Throwable $e) {
  bsse_send('error', ['error' => 'auth']);
  exit;
}

$jobId = (int)($_GET['job_id'] ?? 0);
$sessionId = coreui_console_session_normalize_id($_GET['session_id'] ?? '');
if ($jobId <= 0 || $sessionId === '') {
  bsse_send('error', ['error' => 'invalid_job_scope']);
  exit;
}

try {
  coreui_console_session_require($pdo, (int)$uid, $sessionId, true);
} catch (Throwable $e) {
  bsse_send('error', ['error' => 'session_not_found']);
  exit;
}

// Eigentuemer-Pruefung: nur Console-Jobs dieses Users.
try {
  $st = $pdo->prepare(
    "SELECT id, status, channel, recipient_uid, trigger_user_id, session_id
     FROM stu_ember_browse_jobs WHERE id = ? LIMIT 1"
  );
  $st->execute([$jobId]);
  $job = $st->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  bsse_send('error', ['error' => 'db']);
  exit;
}
if (!$job
    || ($job['channel'] ?? '') !== 'console'
    || (string)($job['session_id'] ?? '') !== $sessionId
    || ((int)($job['recipient_uid'] ?? 0) !== (int)$uid && (int)($job['trigger_user_id'] ?? 0) !== (int)$uid)) {
  bsse_send('error', ['error' => 'forbidden']);
  exit;
}

// Session-Lock freigeben - die Schleife laeuft minutenlang.
if (session_status() === PHP_SESSION_ACTIVE) { @session_write_close(); }

bsse_comment('open');

$lastStepId = 0;
$lastFrameId = 0;
$startTs    = time();
$MAX_SECS   = 900;   // Sicherheits-Cap passend zu Nginx und PHP-FPM

while (!connection_aborted() && (time() - $startTs) < $MAX_SECS) {
  // Neue Browserbilder zuerst ausliefern. Sie ersetzen das fruehere Schrittprotokoll.
  bsse_emit_frames($pdo, $jobId, $lastFrameId);

  // Neue Schritte ausliefern.
  try {
    $ss = $pdo->prepare(
      "SELECT id, text FROM stu_ember_browse_steps
       WHERE job_id = ? AND id > ? ORDER BY id ASC LIMIT 100"
    );
    $ss->execute([$jobId, $lastStepId]);
    foreach ($ss->fetchAll(PDO::FETCH_ASSOC) as $row) {
      $lastStepId = (int)$row['id'];
      bsse_send('step', ['id' => $lastStepId, 'text' => (string)$row['text']]);
    }
  } catch (Throwable $e) { /* weiter versuchen */ }

  // Job-Status pruefen.
  try {
    $sj = $pdo->prepare("SELECT status FROM stu_ember_browse_jobs WHERE id = ? LIMIT 1");
    $sj->execute([$jobId]);
    $status = (string)($sj->fetchColumn() ?: '');
    if ($status === 'done' || $status === 'error') {
      // Alle letzten Frames nachreichen, auch wenn der Worker schneller als der Client war.
      for ($batch = 0; $batch < 20; $batch++) {
        if (bsse_emit_frames($pdo, $jobId, $lastFrameId) < 25) break;
      }
      // Letzte evtl. noch nicht gelesenen Schritte nachreichen.
      try {
        $ss2 = $pdo->prepare(
          "SELECT id, text FROM stu_ember_browse_steps
           WHERE job_id = ? AND id > ? ORDER BY id ASC LIMIT 100"
        );
        $ss2->execute([$jobId, $lastStepId]);
        foreach ($ss2->fetchAll(PDO::FETCH_ASSOC) as $row) {
          $lastStepId = (int)$row['id'];
          bsse_send('step', ['id' => $lastStepId, 'text' => (string)$row['text']]);
        }
      } catch (Throwable $e) {}
      bsse_send($status === 'done' ? 'done' : 'error', ['status' => $status]);
      exit;
    }
  } catch (Throwable $e) {}

  bsse_comment('ping'); // erkennt Client-Abbruch (connection_aborted) + haelt die Leitung warm
  usleep(1000000);      // 1s Poll-Intervall
}

// Cap erreicht / Client weg.
bsse_send('timeout', ['note' => 'stream_cap']);
exit;
