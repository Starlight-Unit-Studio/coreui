<?php
declare(strict_types=1);

// Generischer Ember CoreUI Betreiber-RAG-Import.
// CLI-only. Niemals als Webroute freigeben.
//
// Beispiel:
//   php tools/ingest_docx_knowledge.php \
//     --file="/secure/operator-handbook.docx" \
//     --source="operator_handbook" \
//     --truncate
//
// Trotz des historischen Dateinamens verarbeitet das Werkzeug alle Textformate,
// die der gemeinsame RAG-Lite-Kern unterstuetzt. Private Benutzeruploads laufen
// ueber knowledge.php und niemals ueber diese globale Betreibertabelle.

if (PHP_SAPI !== 'cli') {
  http_response_code(403);
  echo "Forbidden\n";
  exit(1);
}

require_once __DIR__ . '/../api/db.php';
require_once __DIR__ . '/../api/rag_lite.php';

function operator_rag_fail(string $message, int $code = 1): never {
  fwrite(STDERR, '[ERR] ' . $message . "\n");
  exit($code);
}

$options = getopt('', ['file:', 'source:', 'truncate', 'chunk-chars::', 'dry-run']);
$file = trim((string)($options['file'] ?? ''));
$source = strtolower(trim((string)($options['source'] ?? '')));
$truncate = array_key_exists('truncate', $options);
$dryRun = array_key_exists('dry-run', $options);
$chunkChars = max(400, min(4000, (int)($options['chunk-chars'] ?? 1400)));

if ($file === '' || !is_file($file) || !is_readable($file)) operator_rag_fail('missing or unreadable --file');
if (!preg_match('~^[a-z0-9_.-]{1,64}$~', $source)) operator_rag_fail('invalid --source');
$extension = strtolower((string)pathinfo($file, PATHINFO_EXTENSION));
$title = trim((string)pathinfo($file, PATHINFO_FILENAME));
if ($title === '') $title = $source;

try {
  $text = coreui_rag_extract($file, $extension, 8000000);
  if (mb_strlen($text, 'UTF-8') < 40) operator_rag_fail('document contains too little readable text');
  $chunks = coreui_rag_chunks($text, $chunkChars, max(80, (int)floor($chunkChars * 0.14)), 10000);
  if (!$chunks) operator_rag_fail('no chunks extracted');

  if (!$dryRun) {
    $pdo = stu_pdo();
    $pdo->query('SELECT source,chunk_text FROM ember_knowledge_chunks LIMIT 0');
    $pdo->beginTransaction();
    try {
      if ($truncate) {
        $delete = $pdo->prepare('DELETE FROM ember_knowledge_chunks WHERE source=?');
        $delete->execute([$source]);
      }
      $insert = $pdo->prepare(
        'INSERT INTO ember_knowledge_chunks (source,title,chunk_text,chunk_no) VALUES (?,?,?,?)'
      );
      foreach ($chunks as $index => $chunk) {
        $insert->execute([$source, $title, $chunk, $index]);
      }
      $pdo->commit();
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }
  }

  echo '[OK] source: ' . $source . "\n";
  echo '[OK] format: ' . ($extension !== '' ? $extension : 'unknown') . "\n";
  echo '[OK] readable chars: ' . mb_strlen($text, 'UTF-8') . "\n";
  echo '[OK] chunk_chars: ' . $chunkChars . "\n";
  echo '[OK] chunks: ' . count($chunks) . ($dryRun ? ' (dry-run)' : '') . "\n";
} catch (Throwable $e) {
  operator_rag_fail($e->getMessage());
}
