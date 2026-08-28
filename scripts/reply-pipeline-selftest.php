<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
  fwrite(STDERR, "CLI only\n");
  exit(1);
}

// Simuliert eine erhaltene 0.2.x-Konfiguration. Der fruehere 7200er-Wert darf
// das aktuelle Benutzerbudget nicht mehr abschneiden.
if (!defined('STU_EMBER_MAX_REPLY_CHARS')) define('STU_EMBER_MAX_REPLY_CHARS', 7200);
if (!defined('STU_EMBER_NUM_PREDICT')) define('STU_EMBER_NUM_PREDICT', 6500);
define('STU_CHAT_LIB', 1);
require dirname(__DIR__) . '/api/chat.php';

function reply_test_fail(string $message): void {
  fwrite(STDERR, "[FEHLER] {$message}\n");
  exit(1);
}

$GLOBALS['COREUI_AI_RUNTIME_SETTINGS'] = [
  'num_predict' => 6500,
  'temperature' => 1.0,
  'memory_enabled' => true,
  'memory_limit' => 16,
];

if (ember_num_predict() !== 6500 || ember_num_predict_for_model('ember-coreui:latest') !== 6500) {
  reply_test_fail('Das Benutzerbudget wird nicht unveraendert an Ollama weitergegeben.');
}

if (ember_max_reply_chars() < 52000) {
  reply_test_fail('Eine erhaltene 7200er-Konfiguration kann Antworten weiterhin kuerzen.');
}

$head = 'Dies ist ein ausreichend langer Antwortanfang mit einem eindeutig wiederholten Uebergang.';
$tail = 'einem eindeutig wiederholten Uebergang. Danach folgt der neue Abschlussteil.';
$joined = ember_join_continuation($head, $tail);
if (substr_count($joined, 'einem eindeutig wiederholten Uebergang') !== 1) {
  reply_test_fail('Fortsetzungssegmente werden am Uebergang doppelt gespeichert.');
}

$multiline = chat_clean_console_message("Erste Zeile\n  code_block()\n\nZweite Zeile");
if (!str_contains($multiline, "\n  code_block()\n\n")) {
  reply_test_fail('Der private CoreUI-Kanal verliert Zeilenumbrueche oder Code-Einrueckungen.');
}
$transport = chat_console_transport_text($multiline);
if (!str_contains($transport, "\n  code_block()\n\n")) {
  reply_test_fail('Der History-Transport verliert Zeilenumbrueche oder Code-Einrueckungen.');
}

$streamSource = (string)file_get_contents(dirname(__DIR__) . '/api/console_stream.php');
foreach (['console_stream_continue_truncated', 'continuation_segments', 'CURLOPT_TIMEOUT        => max(30, min(900'] as $needle) {
  if (!str_contains($streamSource, $needle)) {
    reply_test_fail('Stream-Fortsetzung oder 900-Sekunden-Laufzeit fehlt: ' . $needle);
  }
}
if (str_contains($streamSource, "|| !empty(\$acc['truncated']);")) {
  reply_test_fail('Ein Tokenlimit startet weiterhin die komplette Anfrage neu.');
}
if (str_contains($streamSource, "\$_GET['msg']") || !str_contains($streamSource, 'SELECT message FROM stu_chat_messages')) {
  reply_test_fail('Der SSE-Pfad transportiert lange oder vertrauliche Nachrichten weiterhin in der URL.');
}

$clientSource = (string)file_get_contents(dirname(__DIR__) . '/js/console-app.js');
if (str_contains($clientSource, "'&msg='")) {
  reply_test_fail('Der Browser schreibt die Nachricht weiterhin in die EventSource-URL.');
}
$appHtml = (string)file_get_contents(dirname(__DIR__) . '/app.html');
if (!str_contains($appHtml, 'maxlength="12000"')) {
  reply_test_fail('Das CoreUI-Eingabefeld besitzt weiterhin ein altes Kurzlimit.');
}

$chatSource = (string)file_get_contents(dirname(__DIR__) . '/api/chat.php');
foreach (['? 20000 : 12000', '? 1200 : 120', 'chat_clean_console_message', 'chat_console_transport_text'] as $needle) {
  if (!str_contains($chatSource, $needle)) {
    reply_test_fail('Private CoreUI-Arbeitsgrenze fehlt: ' . $needle);
  }
}

$migration = (string)file_get_contents(dirname(__DIR__) . '/database/migrations/002_coreui_management.sql');
if (!str_contains($migration, 'ALTER TABLE stu_chat_messages MODIFY COLUMN message MEDIUMTEXT NOT NULL')) {
  reply_test_fail('Die Nachrichtenspalte ist nicht fuer lange Antworten migriert.');
}

fwrite(STDOUT, "Reply-Pipeline-Selftest OK: Budget, Fortsetzung und MEDIUMTEXT aktiv.\n");
