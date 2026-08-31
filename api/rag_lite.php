<?php
declare(strict_types=1);

/**
 * Gemeinsamer, dateibasierter RAG-Lite-Kern fuer Ember CoreUI.
 *
 * Globale Betreiberquellen und private Benutzerquellen duerfen unterschiedliche
 * Tabellen verwenden. Extraktion, Normalisierung, Chunking und Ranking laufen
 * trotzdem durch denselben Code. Dadurch bleibt die Benutzerisolation erhalten,
 * ohne zwei voneinander abweichende Dokument-Pipelines zu pflegen.
 */

function coreui_rag_find_binary(string $name): ?string {
  $name = basename($name);
  foreach (['/usr/bin/' . $name, '/usr/local/bin/' . $name, '/bin/' . $name] as $path) {
    if (is_file($path) && is_executable($path)) return $path;
  }
  return null;
}

function coreui_rag_normalize_text(string $text, int $maxChars): string {
  $text = str_replace(["\r\n", "\r"], "\n", $text);
  if (!mb_check_encoding($text, 'UTF-8')) {
    $detected = mb_detect_encoding($text, ['UTF-8', 'Windows-1252', 'ISO-8859-1'], true);
    $text = mb_convert_encoding($text, 'UTF-8', $detected ?: 'Windows-1252');
  }
  $text = (string)preg_replace('~[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]~u', '', $text);
  $text = (string)preg_replace('~[ \t]+~u', ' ', $text);
  $text = (string)preg_replace('~ *\n *~u', "\n", $text);
  $text = (string)preg_replace('~\n{3,}~u', "\n\n", $text);
  $text = trim($text);
  if (mb_strlen($text, 'UTF-8') > $maxChars) {
    $text = mb_substr($text, 0, $maxChars, 'UTF-8');
  }
  return trim($text);
}

function coreui_rag_extract_plain(string $path, int $maxChars): string {
  $raw = @file_get_contents($path, false, null, 0, 8 * 1024 * 1024);
  return coreui_rag_normalize_text(is_string($raw) ? $raw : '', $maxChars);
}

function coreui_rag_extract_docx(string $path, int $maxChars): string {
  if (!class_exists('ZipArchive')) throw new RuntimeException('docx_support_unavailable');
  $zip = new ZipArchive();
  if ($zip->open($path) !== true) throw new RuntimeException('docx_open_failed');
  $stat = $zip->statName('word/document.xml');
  if (!$stat || (int)($stat['size'] ?? 0) > 16 * 1024 * 1024) {
    $zip->close();
    throw new RuntimeException('docx_content_too_large');
  }
  $xml = $zip->getFromName('word/document.xml');
  $zip->close();
  if (!is_string($xml) || $xml === '') throw new RuntimeException('docx_content_missing');

  $dom = new DOMDocument();
  $previous = libxml_use_internal_errors(true);
  $loaded = $dom->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT);
  libxml_clear_errors();
  libxml_use_internal_errors($previous);
  if (!$loaded) throw new RuntimeException('docx_xml_invalid');

  $xpath = new DOMXPath($dom);
  $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
  $paragraphs = [];
  $nodes = $xpath->query('//w:p');
  if ($nodes) {
    foreach ($nodes as $paragraph) {
      $line = '';
      $texts = $xpath->query('.//w:t', $paragraph);
      if ($texts) foreach ($texts as $textNode) $line .= (string)$textNode->nodeValue;
      $line = trim((string)preg_replace('~\s+~u', ' ', $line));
      if ($line === '') continue;

      $style = '';
      $styleNodes = $xpath->query('./w:pPr/w:pStyle', $paragraph);
      if ($styleNodes && $styleNodes->length > 0) {
        $styleNode = $styleNodes->item(0);
        if ($styleNode instanceof DOMElement) {
          $style = (string)$styleNode->getAttributeNS(
            'http://schemas.openxmlformats.org/wordprocessingml/2006/main',
            'val'
          );
          if ($style === '') $style = (string)$styleNode->getAttribute('w:val');
          if ($style === '') $style = (string)$styleNode->getAttribute('val');
        }
      }
      $isHeading = $style !== '' && str_contains(mb_strtolower($style, 'UTF-8'), 'heading');
      $paragraphs[] = $isHeading ? ('# ' . $line) : $line;
      if (count($paragraphs) > 50000) throw new RuntimeException('docx_paragraph_limit_exceeded');
    }
  }
  return coreui_rag_normalize_text(implode("\n\n", $paragraphs), $maxChars);
}

function coreui_rag_extract_pdf(string $path, int $maxChars): string {
  if (!function_exists('shell_exec')) throw new RuntimeException('pdf_text_support_unavailable');
  $bin = coreui_rag_find_binary('pdftotext');
  if ($bin === null) throw new RuntimeException('pdf_text_support_unavailable');
  $outFile = tempnam(sys_get_temp_dir(), 'coreui_rag_');
  if (!is_string($outFile) || $outFile === '') throw new RuntimeException('temporary_file_failed');
  try {
    $timeout = coreui_rag_find_binary('timeout');
    $command = ($timeout !== null ? (escapeshellcmd($timeout) . ' 45s ') : '')
      . escapeshellcmd($bin) . ' -layout -q -enc UTF-8 '
      . escapeshellarg($path) . ' ' . escapeshellarg($outFile) . ' 2>/dev/null';
    @shell_exec($command);
    $raw = @file_get_contents($outFile, false, null, 0, 8 * 1024 * 1024);
  } finally {
    @unlink($outFile);
  }
  $text = coreui_rag_normalize_text(is_string($raw ?? null) ? $raw : '', $maxChars);
  if ($text === '') throw new RuntimeException('pdf_has_no_text_layer');
  return $text;
}

function coreui_rag_extract(string $path, string $extension, int $maxChars): string {
  $extension = strtolower(trim($extension));
  if ($extension === 'pdf') return coreui_rag_extract_pdf($path, $maxChars);
  if ($extension === 'docx') return coreui_rag_extract_docx($path, $maxChars);
  if (in_array($extension, [
    'txt', 'md', 'py', 'csv', 'json', 'log', 'xml', 'yml', 'yaml',
    'ini', 'php', 'js', 'html', 'css', 'sql',
  ], true)) return coreui_rag_extract_plain($path, $maxChars);
  throw new InvalidArgumentException('knowledge_format_not_allowed');
}

function coreui_rag_chunks(string $text, int $target, int $overlap, int $maxChunks): array {
  $length = mb_strlen($text, 'UTF-8');
  if ($length === 0) return [];
  $chunks = [];
  $position = 0;
  while ($position < $length && count($chunks) < $maxChunks) {
    $remaining = $length - $position;
    $take = min($target, $remaining);
    $slice = mb_substr($text, $position, $take, 'UTF-8');
    $actual = mb_strlen($slice, 'UTF-8');
    if ($remaining > $target) {
      foreach (["\n\n# ", "\n\n", ". ", "! ", "? ", "\n"] as $needle) {
        $candidate = mb_strrpos($slice, $needle, 0, 'UTF-8');
        if ($candidate !== false && $candidate >= (int)floor($target * 0.62)) {
          $actual = $candidate + mb_strlen($needle, 'UTF-8');
          $slice = mb_substr($slice, 0, $actual, 'UTF-8');
          break;
        }
      }
    }
    $slice = trim($slice);
    if ($slice !== '') $chunks[] = $slice;
    if ($remaining <= $actual) {
      $position = $length;
      break;
    }
    $position += max(1, $actual - min($overlap, max(0, $actual - 1)));
  }
  if ($position < $length) throw new RuntimeException('knowledge_chunk_limit_exceeded');
  return $chunks;
}

function coreui_rag_query_terms(string $query, int $limit = 8): array {
  $lower = mb_strtolower($query, 'UTF-8');
  $parts = preg_split('~[^\pL\pN_-]+~u', $lower) ?: [];
  $stop = [
    'der','die','das','und','oder','ein','eine','ist','sind','mit','von','auf','ich','du','mir','bitte',
    'was','wie','wer','wo','mein','meine','aus','den','dem','des','fuer','für','zum','zur','dass','dies',
  ];
  $terms = [];
  foreach ($parts as $part) {
    $part = trim($part);
    $length = mb_strlen($part, 'UTF-8');
    if ($length < 3 || in_array($part, $stop, true)) continue;
    $terms[$part] = $length;
  }
  arsort($terms);
  return array_slice(array_keys($terms), 0, max(1, min(12, $limit)));
}

function coreui_rag_score(string $title, string $text, array $terms): float {
  $haystack = mb_strtolower($title . "\n" . $text, 'UTF-8');
  $titleLower = mb_strtolower($title, 'UTF-8');
  $score = 0.0;
  foreach ($terms as $term) {
    $count = substr_count($haystack, $term);
    if ($count > 0) $score += 1.0 + min(4, $count) * 0.35;
    if ($titleLower !== '' && str_contains($titleLower, $term)) $score += 2.0;
  }
  return $score;
}
