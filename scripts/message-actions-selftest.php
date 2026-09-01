<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
  fwrite(STDERR, "CLI only\n");
  exit(1);
}

define('STU_CHAT_LIB', 1);
require dirname(__DIR__) . '/api/chat.php';
require_once dirname(__DIR__) . '/api/console_action_store.php';

function actions_test_expect(bool $condition, string $message): void {
  if (!$condition) throw new RuntimeException($message);
}

$root = dirname(__DIR__);
$migration = (string)file_get_contents($root . '/database/migrations/008_message_actions.sql');
$endpoint = (string)file_get_contents($root . '/api/console_actions.php');
$stream = (string)file_get_contents($root . '/api/console_stream.php');
$client = (string)file_get_contents($root . '/js/console-app.js');
$markdown = (string)file_get_contents($root . '/js/coreui-markdown.js');

actions_test_expect(str_contains($migration, 'stu_console_generation_requests'), 'Migration 008 besitzt keine Generierungsanforderungen.');
actions_test_expect(str_contains($migration, "ENUM('regenerate','continue')"), 'Neugenerierung und Fortsetzung sind nicht getrennt.');
actions_test_expect(str_contains($migration, 'response_floor_id'), 'Exakte Antwortgrenze fuer Wiederaufnahme fehlt.');
actions_test_expect(str_contains($migration, 'browse_job_id'), 'Exakte Browserjob-Zuordnung fehlt.');
actions_test_expect(str_contains($endpoint, 'stu_require_csrf()'), 'Schreibende Nachrichtenaktionen besitzen keinen CSRF-Schutz.');
actions_test_expect(str_contains($stream, '$generationRequestId'), 'Stream kann keine bestaetigte Nachrichtenaktion ausfuehren.');
actions_test_expect(str_contains($stream, '$excludeReplyToId = $afterId'), 'Alternative Antwort schliesst die alte Antwort nicht aus dem Kontext aus.');
actions_test_expect(str_contains($stream, 'coreui_console_generation_attach_browse'), 'Browserjob wird nicht exakt mit der Aktion verbunden.');
actions_test_expect(str_contains($client, "startGenerationAction(record, 'regenerate'"), 'Neugenerierungsaktion fehlt im Client.');
actions_test_expect(str_contains($client, "startGenerationAction(record, 'continue'"), 'Fortsetzungsaktion fehlt im Client.');
actions_test_expect(str_contains($client, "window.CoreUIMarkdown.render(bubble, text)"), 'Sichere Markdown-Darstellung ist nicht am Nachrichtenpfad aktiv.');
actions_test_expect(!str_contains($markdown, '.innerHTML'), 'Markdown-Renderer verwendet innerHTML.');

$pdo = stu_pdo();
actions_test_expect(coreui_console_actions_schema_ready($pdo), 'Migration 008 ist nicht aktiv.');

$suffix = bin2hex(random_bytes(6));
$sessionId = 'actions_' . $suffix;
$characterId = 'actions-char-' . $suffix;
$username = 'actions-' . $suffix . '@coreui.invalid';
$pdo->beginTransaction();
try {
  $pdo->prepare(
    'INSERT INTO stu_users (guest_key,username,password_hash,is_guest,permission_level,created_at) '
    . 'VALUES (?,?,?,?,?,NOW())'
  )->execute(['actions-' . $suffix, $username, password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), 0, 4]);
  $uid = (int)$pdo->lastInsertId();
  actions_test_expect($uid > 0, 'Testkonto wurde nicht angelegt.');

  $pdo->prepare(
    'INSERT INTO stu_characters (id,user_id,alliance_id,name,name_norm,world_id,portrait_index,portrait_path,created_at) '
    . 'VALUES (?,?,NULL,?,?,1,0,\'\',NOW())'
  )->execute([$characterId, $uid, 'Action Test', strtolower($characterId)]);
  $pdo->prepare(
    'INSERT INTO stu_console_sessions '
    . '(id,user_id,title,since_id,last_message_id,last_read_message_id,created_at,updated_at,archived_at) '
    . 'VALUES (?,?,\'ACTION SELFTEST\',0,NULL,NULL,NOW(),NOW(),NULL)'
  )->execute([$sessionId, $uid]);

  $pdo->prepare(
    "INSERT INTO stu_chat_messages
      (channel,alliance_id,session_id,user_id,character_id,character_name,message,created_at)
     VALUES ('console',NULL,?,?,?,?,?,NOW())"
  )->execute([$sessionId, $uid, $characterId, 'Action Test', 'Bitte antworte.']);
  $turnId = (int)$pdo->lastInsertId();
  $pdo->prepare(
    "INSERT INTO stu_chat_messages
      (channel,alliance_id,session_id,user_id,character_id,character_name,message,reply_to_id,created_at)
     VALUES ('console',NULL,?,?,?,?,?,?,NOW())"
  )->execute([$sessionId, $uid, ember_character_id(), ember_character_name(), 'Erste Antwort.', $turnId]);
  $responseId = (int)$pdo->lastInsertId();

  $isolated = false;
  try {
    coreui_console_action_message($pdo, $uid + 1, $sessionId, $responseId, true);
  } catch (Throwable $eIsolation) {
    $isolated = true;
  }
  actions_test_expect($isolated, 'Fremdes Konto konnte die Ember-Antwort aufloesen.');

  $feedback = coreui_console_feedback_toggle($pdo, $uid, $sessionId, $responseId, 'up', $characterId);
  actions_test_expect($feedback === 'up', 'Positives Feedback wurde nicht gespeichert.');
  $feedback = coreui_console_feedback_toggle($pdo, $uid, $sessionId, $responseId, 'down', $characterId);
  actions_test_expect($feedback === 'down', 'Gegenteiliges Feedback wurde nicht atomar ersetzt.');
  $map = coreui_console_feedback_map($pdo, $uid, [$responseId]);
  actions_test_expect(($map[$responseId] ?? null) === 'down', 'Feedback wird nicht kontogebunden gelesen.');

  $request = coreui_console_generation_prepare($pdo, $uid, $sessionId, $responseId, 'regenerate');
  actions_test_expect(($request['trigger_message_id'] ?? 0) === $turnId, 'Neugenerierung ist nicht an den exakten Turn gebunden.');
  actions_test_expect(($request['source_response_id'] ?? 0) === $responseId, 'Quellantwort der Neugenerierung fehlt.');
  $stRequest = $pdo->prepare('SELECT response_floor_id FROM stu_console_generation_requests WHERE id=?');
  $stRequest->execute([(string)$request['id']]);
  actions_test_expect((int)$stRequest->fetchColumn() >= $responseId, 'Wiederaufnahme besitzt keine exakte Antwortgrenze.');

  coreui_console_session_archive($pdo, $uid, $sessionId);
  $busyDeleteRejected = false;
  try {
    coreui_console_session_delete_tx($pdo, $uid, $sessionId);
  } catch (RuntimeException $eDelete) {
    $busyDeleteRejected = $eDelete->getMessage() === 'session_busy';
  }
  actions_test_expect($busyDeleteRejected, 'Laufende Nachrichtenaktion blockiert die Sitzungsloeschung nicht.');

  $pdo->rollBack();
  fwrite(STDOUT, "Nachrichtenaktionen-Selftest OK: Isolation, Feedback, Turnbindung, Wiederaufnahme und Loeschschutz geprueft.\n");
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  fwrite(STDERR, 'Nachrichtenaktionen-Selftest FEHLER: ' . $e->getMessage() . "\n");
  exit(2);
}
