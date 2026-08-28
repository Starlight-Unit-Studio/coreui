<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

// -----------------------------------------------------------------------------
// STU Console - Auslieferung hochgeladener Dateien (v1.1.1.89)
//
// Liefert Dateien aus dem lokalen Projektordner var/console_media, den Nginx selbst
// nicht ausliefern kann. Jeder Abruf prueft die Session und den Eigentuemer.
// Range-Unterstuetzung ist Pflicht, sonst laesst sich in einem Video nicht
// spulen: der Browser fordert dafuer Teilbereiche an und erwartet 206.
// -----------------------------------------------------------------------------

$pdo = stu_pdo();
$uid = stu_get_user_id();

function console_media_fail(int $code, string $msg): void {
  http_response_code($code);
  header('Content-Type: text/plain; charset=utf-8');
  header('Cache-Control: no-store');
  echo $msg;
  exit;
}

if (!$uid) console_media_fail(401, 'not authenticated');

$uuid = strtolower(trim((string)($_GET['uuid'] ?? '')));
if (!preg_match('~^[a-f0-9]{32}$~', $uuid)) console_media_fail(400, 'bad uuid');

try {
  $st = $pdo->prepare("SELECT * FROM stu_console_media WHERE uuid = ? LIMIT 1");
  $st->execute([$uuid]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  console_media_fail(500, 'db error');
}
if (!is_array($row)) console_media_fail(404, 'not found');

// Die Console ist ein privater 1:1-Kanal. Fremde Uploads bleiben fremd,
// auch wenn jemand eine gueltige UUID errät oder mitliest.
if ((int)$row['user_id'] !== (int)$uid) console_media_fail(403, 'forbidden');

$projectRoot = dirname(__DIR__);
$path = $projectRoot . '/' . ltrim((string)$row['rel_path'], '/');

// Pfad haerten: rel_path stammt zwar aus der eigenen DB, aber ein Traversal
// darf hier auch dann nicht moeglich sein, wenn die Spalte je manipuliert wird.
$real = realpath($path);
$base = realpath($projectRoot . '/var/console_media');
$pubBase = realpath(dirname(__DIR__) . '/assets/chat_media');
$okBase = ($real !== false) && (
     ($base !== false && str_starts_with($real, $base . DIRECTORY_SEPARATOR))
  || ($pubBase !== false && str_starts_with($real, $pubBase . DIRECTORY_SEPARATOR))
);
if (!$okBase || !is_file($real)) console_media_fail(404, 'file missing');

$size = (int)filesize($real);
$mime = (string)($row['mime_type'] ?? 'application/octet-stream');
$name = (string)($row['orig_name'] ?? 'datei');

// Inline nur fuer Typen, die der Browser gefahrlos selbst darstellt.
// Alles andere als Download ausliefern, damit nichts im Seitenkontext
// gerendert wird (HTML/SVG koennten sonst Skript im eigenen Ursprung ausfuehren).
$inlineOk = str_starts_with($mime, 'image/')
         || str_starts_with($mime, 'video/')
         || str_starts_with($mime, 'audio/')
         || $mime === 'application/pdf'
         || $mime === 'text/plain';
if ($mime === 'image/svg+xml') $inlineOk = false;

$disp = $inlineOk ? 'inline' : 'attachment';
$fallback = preg_replace('~[^A-Za-z0-9._-]~', '_', $name);

header('Content-Type: ' . $mime);
header('Content-Disposition: ' . $disp . '; filename="' . $fallback . '"; filename*=UTF-8\'\'' . rawurlencode($name));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=600');
header('Accept-Ranges: bytes');

$start = 0;
$end   = $size - 1;
$range = (string)($_SERVER['HTTP_RANGE'] ?? '');

if ($range !== '' && preg_match('~^bytes=(\d*)-(\d*)$~', trim($range), $m)) {
  $rs = $m[1]; $re = $m[2];
  if ($rs === '' && $re === '') {
    header('Content-Range: bytes */' . $size);
    console_media_fail(416, 'bad range');
  }
  if ($rs === '') {
    // Suffix-Range: die letzten N Bytes
    $len = (int)$re;
    if ($len <= 0) { header('Content-Range: bytes */' . $size); console_media_fail(416, 'bad range'); }
    $start = max(0, $size - $len);
  } else {
    $start = (int)$rs;
    if ($re !== '') $end = (int)$re;
  }
  if ($start > $end || $start >= $size) {
    header('Content-Range: bytes */' . $size);
    console_media_fail(416, 'range not satisfiable');
  }
  if ($end >= $size) $end = $size - 1;
  http_response_code(206);
  header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
}

$length = $end - $start + 1;
header('Content-Length: ' . $length);

// Ausgabepuffer leeren: bei 256-MB-Dateien darf nichts im Speicher landen.
while (ob_get_level() > 0) { @ob_end_clean(); }

$fh = @fopen($real, 'rb');
if (!$fh) console_media_fail(500, 'open failed');
if ($start > 0) fseek($fh, $start);

$remaining = $length;
$chunk = 256 * 1024;
while ($remaining > 0 && !feof($fh)) {
  $read = ($remaining > $chunk) ? $chunk : $remaining;
  $buf = fread($fh, $read);
  if ($buf === false) break;
  echo $buf;
  $remaining -= strlen($buf);
  flush();
  // Bricht der Client ab (Video geschlossen), nicht weiter ins Leere schreiben.
  if (connection_aborted()) break;
}
fclose($fh);
exit;
