<?php
// STU - Ember Lore Ingest (DOCX -> ember_knowledge_chunks)
//
// CLI-only. Do NOT expose this via web.
// Usage examples:
//   php tools/ingest_docx_knowledge.php --file="docs/STU_KOMPENDIUM_V6.docx" --source="kompendium_v6" --truncate
//   php tools/ingest_docx_knowledge.php --file="docs/S.U. MASTER BIBEL v10.4.docx" --source="bibel_v10_4" --truncate
//
// Options:
//   --file=PATH          DOCX file path
//   --source=ID          short id, e.g. bibel_v10_4
//   --truncate           delete existing chunks for this source first
//   --chunk-chars=N      default 1200 (400..4000)
//   --dry-run            parse + report only, do not write

if (php_sapi_name() !== 'cli') {
  http_response_code(403);
  echo "Forbidden";
  exit;
}

require_once __DIR__ . '/../api/db.php';

// Polyfills (PHP < 8)
if (!function_exists('str_contains')) {
  function str_contains(string $haystack, string $needle): bool { return $needle === '' || strpos($haystack, $needle) !== false; }
}
if (!function_exists('str_starts_with')) {
  function str_starts_with(string $haystack, string $needle): bool { return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0; }
}

function arg(array $opts, string $k, $default = null) {
  return array_key_exists($k, $opts) ? $opts[$k] : $default;
}

function fail(string $msg, int $code = 1): void {
  fwrite(STDERR, "[ERR] $msg\n");
  exit($code);
}

function docx_paragraphs(string $file): array {
  if (!is_file($file)) fail("file not found: $file");

  $zip = new ZipArchive();
  if ($zip->open($file) !== true) fail("cannot open docx: $file");
  $xml = $zip->getFromName('word/document.xml');
  $zip->close();
  if (!is_string($xml) || $xml === '') fail("missing word/document.xml in docx");

  $dom = new DOMDocument();
  $dom->preserveWhiteSpace = false;
  $dom->formatOutput = false;
  if (!@$dom->loadXML($xml)) fail("invalid document.xml");

  $xp = new DOMXPath($dom);
  $xp->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

  $paras = $xp->query('//w:p');
  if (!$paras) return [];

  $out = [];
  foreach ($paras as $p) {
    // Detect heading style
    $style = '';
    $styleNode = $xp->query('./w:pPr/w:pStyle', $p);
    if ($styleNode && $styleNode->length > 0) {
      /** @var DOMElement $sn */
      $sn = $styleNode->item(0);
      $style = (string)$sn->getAttributeNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'val');
      if ($style === '') $style = (string)$sn->getAttribute('w:val');
      if ($style === '') $style = (string)$sn->getAttribute('val');
    }

    // Collect text runs
    $texts = $xp->query('.//w:t', $p);
    if (!$texts || $texts->length === 0) continue;

    $t = '';
    foreach ($texts as $tn) {
      $t .= $tn->nodeValue;
    }
    $t = trim(preg_replace('~\s+~u', ' ', $t));
    if ($t === '') continue;

    $isHeading = false;
    if ($style !== '') {
      $ls = strtolower($style);
      if (str_starts_with($ls, 'heading') || str_contains($ls, 'heading')) $isHeading = true;
    }

    $out[] = ['text' => $t, 'is_heading' => $isHeading];
  }

  return $out;
}

function ensure_table(PDO $pdo): void {
  $pdo->query('SELECT 1 FROM ember_knowledge_chunks LIMIT 1');
}

$opts = getopt('', ['file:', 'source:', 'truncate', 'chunk-chars::', 'dry-run']);
$file = (string)arg($opts, 'file', '');
$source = (string)arg($opts, 'source', '');
$truncate = array_key_exists('truncate', $opts);
$dry = array_key_exists('dry-run', $opts);
$chunkChars = (int)arg($opts, 'chunk-chars', 1200);
$chunkChars = max(400, min(4000, $chunkChars));

if ($file === '') fail('missing --file');
if ($source === '') fail('missing --source');

$pdo = stu_pdo();
try {
  ensure_table($pdo);
} catch (Throwable $e) {
  fail('ember_knowledge_chunks not found. Run migrations/101_ember_knowledge_chunks.sql first.');
}

$paras = docx_paragraphs($file);
if (!$paras) fail('no paragraphs extracted');

if ($truncate && !$dry) {
  $st = $pdo->prepare('DELETE FROM ember_knowledge_chunks WHERE source=?');
  $st->execute([$source]);
  echo "[OK] truncated source: $source\n";
}

$title = null;
$buf = '';
$chunkNo = 0;
$totalInserted = 0;

$ins = $pdo->prepare('INSERT INTO ember_knowledge_chunks (source, title, chunk_text, chunk_no) VALUES (?, ?, ?, ?)');

$flush = function() use (&$buf, &$chunkNo, &$totalInserted, $dry, $ins, $source, &$title) {
  $txt = trim($buf);
  if ($txt === '') { $buf = ''; return; }
  if (!$dry) {
    $ins->execute([$source, $title, $txt, $chunkNo]);
  }
  $totalInserted++;
  $chunkNo++;
  $buf = '';
};

foreach ($paras as $p) {
  $t = $p['text'];
  $isHeading = (bool)$p['is_heading'];

  if ($isHeading) {
    // If current buffer has content, flush before switching title
    if (strlen($buf) > 0) $flush();
    $title = $t;
    continue;
  }

  // Add paragraph
  if ($buf !== '') $buf .= "\n";
  $buf .= $t;

  // Flush if we hit the budget
  if (strlen($buf) >= $chunkChars) {
    $flush();
  }
}

// Final flush
$flush();

echo "[OK] extracted paragraphs: " . count($paras) . "\n";
echo "[OK] chunk_chars: $chunkChars\n";
echo "[OK] inserted chunks: $totalInserted" . ($dry ? " (dry-run)" : "") . "\n";
