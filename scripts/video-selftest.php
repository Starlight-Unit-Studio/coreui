<?php
declare(strict_types=1);

// Standalone-Regressionstest fuer den Video-Pfad. Er verwendet keine Datenbank,
// keinen Upload und kein Ollama. Getestet werden genau die kritischen Schritte:
// ffmpeg/ffprobe, beschreibbares Frame-Volume, zeitliche Extraktion, Skalierung
// und anschliessendes Aufraeumen.

$projectRoot = dirname(__DIR__);
$testLog = [];

if (!defined('STU_EMBER_VIDEO_FRAMES')) {
  define('STU_EMBER_VIDEO_FRAMES', 3);
}
if (!defined('STU_EMBER_VIDEO_FRAME_PX')) {
  define('STU_EMBER_VIDEO_FRAME_PX', 512);
}
if (!defined('STU_EMBER_VIDEO_FRAME_DIR')) {
  define('STU_EMBER_VIDEO_FRAME_DIR', $projectRoot . '/var/ember_frames');
}

function ember_cfg(string $name, $default = null) {
  return defined($name) ? constant($name) : $default;
}

function ember_debug_log(string $type, array $meta = []): void {
  $GLOBALS['testLog'][] = ['type' => $type] + $meta;
}

require_once $projectRoot . '/api/ember_attachments.php';

function video_selftest_fail(string $message): never {
  throw new RuntimeException($message);
}

$testVideo = '';
$frames = null;
$exitCode = 0;

try {
  $ffmpeg = ember_attach_bin('ffmpeg');
  $ffprobe = ember_attach_bin('ffprobe');
  if ($ffmpeg === null || $ffprobe === null) {
    video_selftest_fail('ffmpeg oder ffprobe fehlt.');
  }

  $frameRoot = ember_video_frame_dir();
  if (!is_dir($frameRoot) || !is_writable($frameRoot)) {
    video_selftest_fail('Frame-Ordner ist nicht beschreibbar: ' . $frameRoot);
  }

  try {
    $suffix = bin2hex(random_bytes(6));
  } catch (Throwable $e) {
    $suffix = substr(sha1(uniqid('coreui-video-', true)), 0, 12);
  }
  $testVideo = rtrim(sys_get_temp_dir(), '/') . '/coreui-video-' . $suffix . '.mp4';

  $makeCmd = escapeshellcmd($ffmpeg)
    . ' -nostdin -y -v error'
    . ' -f lavfi -i ' . escapeshellarg('testsrc2=size=1280x720:rate=2:duration=2')
    . ' -an -c:v mpeg4 -q:v 4 -pix_fmt yuv420p '
    . escapeshellarg($testVideo) . ' 2>&1';
  $makeOut = @shell_exec($makeCmd);
  if (!is_file($testVideo) || filesize($testVideo) < 1024) {
    video_selftest_fail('Synthetisches Testvideo konnte nicht erzeugt werden. ' . trim((string)$makeOut));
  }

  $frames = ember_attach_video_frames($testVideo);
  if (!is_array($frames) || empty($frames['paths'])) {
    $code = (string)($GLOBALS['STU_EMBER_VIDEO_FAILURE'] ?? 'unknown');
    video_selftest_fail('Frame-Extraktion fehlgeschlagen: ' . $code);
  }

  $dimensions = [];
  foreach ($frames['paths'] as $framePath) {
    if (!is_file($framePath) || filesize($framePath) <= 512) {
      video_selftest_fail('Ein extrahierter Frame fehlt oder ist leer.');
    }
    $size = @getimagesize($framePath);
    if (!is_array($size)) {
      video_selftest_fail('Ein extrahierter Frame ist kein lesbares Bild.');
    }
    $width = (int)$size[0];
    $height = (int)$size[1];
    if ($width < 1 || $height < 1 || $width > 512 || $height > 512) {
      video_selftest_fail("Ungueltige Frame-Skalierung: {$width}x{$height}");
    }
    if (($width % 2) !== 0 || ($height % 2) !== 0) {
      video_selftest_fail("Frame-Abmessung ist nicht durch zwei teilbar: {$width}x{$height}");
    }
    $dimensions[] = $width . 'x' . $height;
  }

  $duration = ember_video_duration($testVideo);
  if ($duration === null || $duration < 1.5 || $duration > 2.5) {
    video_selftest_fail('ffprobe lieferte keine plausible Dauer.');
  }

  echo 'Video-Selftest OK: ' . count($frames['paths']) . ' Frames, '
    . implode(',', $dimensions) . ', Dauer ' . round($duration, 2) . "s\n";
} catch (Throwable $e) {
  fwrite(STDERR, 'Video-Selftest FEHLER: ' . $e->getMessage() . "\n");
  $exitCode = 1;
} finally {
  if (is_array($frames)) {
    ember_attach_frames_cleanup($frames['dir'] ?? null);
  }
  if ($testVideo !== '') @unlink($testVideo);
}

exit($exitCode);
