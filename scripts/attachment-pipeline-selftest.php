<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
  fwrite(STDERR, "CLI only\n");
  exit(1);
}

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/api/helpers.php';
require_once $projectRoot . '/api/db.php';
require_once $projectRoot . '/api/console_attachment_store.php';

if (!function_exists('ember_cfg')) {
  function ember_cfg(string $name, $default = null) {
    return defined($name) ? constant($name) : $default;
  }
}
if (!function_exists('ember_debug_log')) {
  function ember_debug_log(string $type, array $meta = []): void {}
}
require_once $projectRoot . '/api/ember_attachments.php';

function attachment_test_assert(bool $condition, string $message): void {
  if (!$condition) throw new RuntimeException($message);
}

function attachment_test_pdf(array $objects): string {
  ksort($objects, SORT_NUMERIC);
  $pdf = "%PDF-1.4\n";
  $offsets = [0 => 0];
  foreach ($objects as $number => $body) {
    $offsets[(int)$number] = strlen($pdf);
    $pdf .= (int)$number . " 0 obj\n" . $body . "\nendobj\n";
  }
  $size = max(array_keys($objects)) + 1;
  $xref = strlen($pdf);
  $pdf .= "xref\n0 {$size}\n0000000000 65535 f \n";
  for ($i = 1; $i < $size; $i++) {
    if (!isset($offsets[$i])) throw new RuntimeException('PDF-Testobjekte sind nicht fortlaufend.');
    $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
  }
  return $pdf . "trailer\n<< /Size {$size} /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
}

function attachment_test_text_pdf(string $token): string {
  $content = "BT\n/F1 16 Tf\n30 100 Td\n(" . $token . ") Tj\nET\n";
  return attachment_test_pdf([
    1 => '<< /Type /Catalog /Pages 2 0 R >>',
    2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
    3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 420 160] '
      . '/Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
    4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
    5 => '<< /Length ' . strlen($content) . ">>\nstream\n" . $content . "endstream",
  ]);
}

function attachment_test_docx(string $path, string $token): void {
  $zip = new ZipArchive();
  attachment_test_assert($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 'DOCX-Testdatei konnte nicht erstellt werden.');
  $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
    . '<w:body><w:p><w:r><w:t>' . htmlspecialchars($token, ENT_XML1) . '</w:t></w:r></w:p></w:body></w:document>';
  $zip->addFromString('word/document.xml', $xml);
  $zip->close();
}

$pdo = stu_pdo();
$mediaDir = $projectRoot . '/var/console_media';
$created = [];
$exitCode = 0;

try {
  attachment_test_assert(is_dir($mediaDir) && is_writable($mediaDir), 'var/console_media ist nicht beschreibbar.');
  attachment_test_assert(coreui_console_attachment_schema_ready($pdo), 'Migration 005 fuer Anhangzuordnungen fehlt.');
  attachment_test_assert(class_exists('ZipArchive'), 'PHP-Zip fuer DOCX fehlt.');
  attachment_test_assert(ember_attach_bin('pdftotext') !== null, 'pdftotext fuer PDF fehlt.');

  $uid = (int)($pdo->query('SELECT id FROM stu_users ORDER BY id ASC LIMIT 1')->fetchColumn() ?: 0);
  attachment_test_assert($uid > 0, 'Kein Benutzer fuer den Pipeline-Test vorhanden.');
  $suffix = bin2hex(random_bytes(8));
  $sessionId = 'cs_test_' . $suffix;
  $specs = [
    ['txt', 'text/plain', 'ATTACHMENT_TXT_' . $suffix, null],
    ['py', 'text/x-python', 'ATTACHMENT_PY_' . $suffix, null],
    ['docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'ATTACHMENT_DOCX_' . $suffix, 'docx'],
    ['pdf', 'application/pdf', 'ATTACHMENT_PDF_' . $suffix, 'pdf'],
  ];
  $uuids = [];

  $pdo->beginTransaction();
  $pdo->prepare(
    'INSERT INTO stu_console_sessions (id,user_id,title,since_id,created_at,updated_at) VALUES (?, ?, ?, 0, NOW(), NOW())'
  )->execute([$sessionId, $uid, 'Attachment Pipeline Selftest']);

  foreach ($specs as [$extension, $mime, $token, $generator]) {
    $uuid = bin2hex(random_bytes(16));
    $stored = $uuid . '.' . $extension;
    $path = $mediaDir . '/' . $stored;
    if ($generator === 'docx') attachment_test_docx($path, $token);
    elseif ($generator === 'pdf') file_put_contents($path, attachment_test_text_pdf($token));
    else file_put_contents($path, $token . "\nprint('pipeline')\n");
    attachment_test_assert(is_file($path) && filesize($path) > 0, 'Testdatei wurde nicht geschrieben: ' . $extension);
    $created[] = $path;
    $pdo->prepare(
      'INSERT INTO stu_console_media '
      . '(uuid,user_id,character_id,kind,orig_name,stored_name,rel_path,public_url,mime_type,file_size,created_at) '
      . 'VALUES (?, ?, NULL, ?, ?, ?, ?, NULL, ?, ?, NOW())'
    )->execute([
      $uuid, $uid, 'document', 'pipeline.' . $extension, $stored,
      'var/console_media/' . $stored, $mime, filesize($path),
    ]);
    $uuids[] = $uuid;
  }

  $pdo->prepare(
    "INSERT INTO stu_chat_messages (channel,alliance_id,session_id,user_id,character_id,character_name,message,file_uuid,created_at) "
    . "VALUES ('console',NULL,?,?,?,?,?,?,NOW())"
  )->execute([$sessionId, $uid, 'selftest-user', 'Selftest', '', $uuids[0]]);
  $messageId = (int)$pdo->lastInsertId();
  coreui_console_attachment_store($pdo, $messageId, $uid, $uuids);

  $restored = coreui_console_attachment_ids_for_message($pdo, $messageId, $uid, $uuids[0]);
  attachment_test_assert($restored === $uuids, 'Persistente Anhangreihenfolge wurde beim Turn-Abruf verloren.');
  $modelMessage = coreui_console_attachment_marker_text($restored);
  $block = ember_attachment_block($pdo, $modelMessage, $uid);
  attachment_test_assert($modelMessage === '', 'Dateimarker wurden vor dem Modellprompt nicht entfernt.');
  foreach ($specs as $spec) {
    attachment_test_assert(str_contains($block, $spec[2]), strtoupper($spec[0]) . '-Inhalt erreicht den Modellprompt nicht.');
  }
  attachment_test_assert(!str_contains($block, '[file:'), 'Roher Dateimarker erreicht den Modellprompt.');

  $stream = (string)file_get_contents($projectRoot . '/api/console_stream.php');
  attachment_test_assert(
    str_contains($stream, 'coreui_console_attachment_ids_for_message')
      && str_contains($stream, 'coreui_console_attachment_marker_text'),
    'Der SSE-Pfad rekonstruiert die gespeicherten Anhaenge nicht.'
  );

  $pdo->rollBack();
  echo 'Attachment-Pipeline-Selftest OK: TXT, PDF, DOCX und Python erreichen Ember ohne @- oder Markerrest.' . "\n";
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  fwrite(STDERR, 'Attachment-Pipeline-Selftest FEHLER: ' . $e->getMessage() . "\n");
  $exitCode = 1;
} finally {
  foreach ($created as $path) @unlink($path);
}

exit($exitCode);
