<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
  fwrite(STDERR, "CLI only\n");
  exit(1);
}

define('STU_CHAT_LIB', 1);
require dirname(__DIR__) . '/api/chat.php';

$pdo = stu_pdo();
$safe = ember_public_thinking_status('complete');

$replyRows = 0;
$scan = $pdo->prepare(
  "SELECT id, message, thinking_content FROM stu_chat_messages
   WHERE channel = 'console'
     AND (character_id = ? OR LOWER(character_name) = LOWER(?))
   ORDER BY id"
);
$scan->execute([ember_character_id(), ember_character_name()]);
$update = $pdo->prepare('UPDATE stu_chat_messages SET message = ? WHERE id = ?');
foreach ($scan->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
  $message = trim((string)($row['message'] ?? ''));
  if ($message === '') continue;
  $clean = ember_sanitize_public_reply($message, (string)($row['thinking_content'] ?? ''));
  if ($clean === $message) continue;
  if ($clean === '') {
    $clean = 'Diese ältere Ausgabe enthielt einen internen Modellentwurf und wurde ausgeblendet.';
  }
  $update->execute([$clean, (int)$row['id']]);
  $replyRows += $update->rowCount();
}

$st = $pdo->prepare(
  "UPDATE stu_chat_messages
   SET thinking_content = ?
   WHERE channel = 'console'
     AND (character_id = ? OR LOWER(character_name) = LOWER(?))
     AND thinking_content IS NOT NULL
     AND TRIM(thinking_content) <> ''
     AND thinking_content <> ?"
);
$st->execute([$safe, ember_character_id(), ember_character_name(), $safe]);
$thinkingRows = $st->rowCount();

fwrite(
  STDOUT,
  'Thinking-Bereinigung abgeschlossen: ' . $thinkingRows . ' Statuszeile(n), '
    . $replyRows . " Antwortzeile(n) bereinigt.\n"
);
