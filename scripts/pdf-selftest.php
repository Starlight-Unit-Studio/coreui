<?php
declare(strict_types=1);

// Standalone-Regressionstest fuer beide PDF-Pfade:
// 1. PDF mit Textebene ueber pdftotext.
// 2. Scan-PDF ohne Textebene ueber pdfinfo und pdftoppm als Seitenbild.
// Datenbank, Upload und Ollama werden nicht benoetigt.

$projectRoot = dirname(__DIR__);
$testLog = [];

if (!defined('STU_EMBER_PDF_MAX_PAGES')) {
  define('STU_EMBER_PDF_MAX_PAGES', 4);
}
if (!defined('STU_EMBER_PDF_PAGE_PX')) {
  define('STU_EMBER_PDF_PAGE_PX', 1024);
}
if (!defined('STU_EMBER_PDF_PAGE_DIR')) {
  define('STU_EMBER_PDF_PAGE_DIR', $projectRoot . '/var/pdf_pages');
}

function ember_cfg(string $name, $default = null) {
  return defined($name) ? constant($name) : $default;
}

function ember_debug_log(string $type, array $meta = []): void {
  $GLOBALS['testLog'][] = ['type' => $type] + $meta;
}

require_once $projectRoot . '/api/ember_attachments.php';

function pdf_selftest_fail(string $message): never {
  throw new RuntimeException($message);
}

function pdf_selftest_assemble(array $objects): string {
  ksort($objects, SORT_NUMERIC);
  $pdf = "%PDF-1.4\n";
  $offsets = [0 => 0];
  foreach ($objects as $number => $body) {
    $offsets[(int)$number] = strlen($pdf);
    $pdf .= (int)$number . " 0 obj\n" . $body . "\nendobj\n";
  }

  $size = max(array_keys($objects)) + 1;
  $xref = strlen($pdf);
  $pdf .= "xref\n0 " . $size . "\n";
  $pdf .= "0000000000 65535 f \n";
  for ($i = 1; $i < $size; $i++) {
    if (!isset($offsets[$i])) pdf_selftest_fail('PDF-Testobjekte sind nicht fortlaufend.');
    $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
  }
  $pdf .= "trailer\n<< /Size " . $size . " /Root 1 0 R >>\n";
  $pdf .= "startxref\n" . $xref . "\n%%EOF\n";
  return $pdf;
}

function pdf_selftest_text_document(): string {
  $content = "BT\n/F1 18 Tf\n40 120 Td\n(EMBER PDF SELFTEST TEXT) Tj\nET\n";
  return pdf_selftest_assemble([
    1 => '<< /Type /Catalog /Pages 2 0 R >>',
    2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
    3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 360 180] '
      . '/Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
    4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
    5 => '<< /Length ' . strlen($content) . ">>\nstream\n" . $content . "endstream",
  ]);
}

function pdf_selftest_scan_document(): string {
  if (!function_exists('imagecreatetruecolor') || !function_exists('imagejpeg')) {
    pdf_selftest_fail('PHP-GD mit JPEG-Unterstuetzung fehlt.');
  }
  $width = 640;
  $height = 320;
  $image = imagecreatetruecolor($width, $height);
  if ($image === false) pdf_selftest_fail('Testbild konnte nicht erzeugt werden.');
  $white = imagecolorallocate($image, 255, 255, 255);
  $blue = imagecolorallocate($image, 40, 92, 190);
  imagefilledrectangle($image, 0, 0, $width, $height, $white);
  imagerectangle($image, 18, 18, $width - 19, $height - 19, $blue);
  imagestring($image, 5, 110, 145, 'EMBER PDF SELFTEST SCAN', $blue);
  ob_start();
  imagejpeg($image, null, 90);
  $jpeg = (string)ob_get_clean();
  imagedestroy($image);
  if (strlen($jpeg) < 512) pdf_selftest_fail('JPEG-Testbild ist ungueltig.');

  $content = "q\n" . $width . " 0 0 " . $height . " 0 0 cm\n/Im0 Do\nQ\n";
  return pdf_selftest_assemble([
    1 => '<< /Type /Catalog /Pages 2 0 R >>',
    2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
    3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . $width . ' ' . $height . '] '
      . '/Resources << /XObject << /Im0 4 0 R >> >> /Contents 5 0 R >>',
    4 => '<< /Type /XObject /Subtype /Image /Width ' . $width . ' /Height ' . $height
      . ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length '
      . strlen($jpeg) . ">>\nstream\n" . $jpeg . "\nendstream",
    5 => '<< /Length ' . strlen($content) . ">>\nstream\n" . $content . "endstream",
  ]);
}

$textPdf = '';
$scanPdf = '';
$pages = null;
$exitCode = 0;

try {
  foreach (['pdftotext', 'pdfinfo', 'pdftoppm'] as $binary) {
    if (ember_attach_bin($binary) === null) {
      pdf_selftest_fail($binary . ' fehlt. Im Compose-Betrieb muss poppler-utils im PHP-Image liegen.');
    }
  }

  $pageRoot = ember_pdf_page_dir();
  if (!is_dir($pageRoot) || !is_writable($pageRoot)) {
    pdf_selftest_fail('PDF-Seitenordner ist nicht beschreibbar: ' . $pageRoot);
  }

  try {
    $suffix = bin2hex(random_bytes(6));
  } catch (Throwable $e) {
    $suffix = substr(sha1(uniqid('coreui-pdf-', true)), 0, 12);
  }
  $tmp = rtrim(sys_get_temp_dir(), '/');
  $textPdf = $tmp . '/coreui-pdf-text-' . $suffix . '.pdf';
  $scanPdf = $tmp . '/coreui-pdf-scan-' . $suffix . '.pdf';

  if (@file_put_contents($textPdf, pdf_selftest_text_document()) === false) {
    pdf_selftest_fail('Text-PDF konnte nicht geschrieben werden.');
  }
  if (@file_put_contents($scanPdf, pdf_selftest_scan_document()) === false) {
    pdf_selftest_fail('Scan-PDF konnte nicht geschrieben werden.');
  }

  $text = ember_attach_read_pdf($textPdf, 4000);
  if (!is_string($text) || !str_contains($text, 'EMBER PDF SELFTEST TEXT')) {
    pdf_selftest_fail('pdftotext hat die bekannte Textebene nicht erkannt.');
  }

  $scanText = ember_attach_read_pdf($scanPdf, 4000);
  if ($scanText !== null && trim($scanText) !== '') {
    pdf_selftest_fail('Das bildbasierte Scan-PDF wurde unerwartet als Text erkannt.');
  }

  $pages = ember_attach_pdf_pages($scanPdf);
  if (!is_array($pages) || count($pages['paths'] ?? []) !== 1) {
    $code = (string)($GLOBALS['STU_EMBER_PDF_FAILURE'] ?? 'unknown');
    pdf_selftest_fail('Scan-PDF-Rasterung fehlgeschlagen: ' . $code);
  }
  $pagePath = (string)$pages['paths'][0];
  $dimension = @getimagesize($pagePath);
  if (!is_array($dimension)) pdf_selftest_fail('Gerasterte PDF-Seite ist kein lesbares Bild.');
  $width = (int)$dimension[0];
  $height = (int)$dimension[1];
  if ($width < 1 || $height < 1 || $width > 1024 || $height > 1024) {
    pdf_selftest_fail("Ungueltige PDF-Skalierung: {$width}x{$height}");
  }
  if (($pages['page_numbers'][0] ?? 0) !== 1 || ($pages['total_pages'] ?? 0) !== 1) {
    pdf_selftest_fail('PDF-Seitenmetadaten sind unplausibel.');
  }

  echo 'PDF-Selftest OK: Textebene erkannt, Scan-Fallback 1 Seite, '
    . $width . 'x' . $height . "\n";
} catch (Throwable $e) {
  fwrite(STDERR, 'PDF-Selftest FEHLER: ' . $e->getMessage() . "\n");
  $exitCode = 1;
} finally {
  if (is_array($pages)) {
    ember_attach_pdf_pages_cleanup($pages['dir'] ?? null);
  }
  if ($textPdf !== '') @unlink($textPdf);
  if ($scanPdf !== '') @unlink($scanPdf);
}

exit($exitCode);
