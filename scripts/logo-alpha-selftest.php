<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
  fwrite(STDERR, "CLI only\n");
  exit(1);
}

$fileName = 'starlight_unit_studios_logo_original.png';
$approvedSha256 = '3463758e8e2265315267fa2c45ea5f377b03df37beee9df878331873a4971274';
$path = dirname(__DIR__) . '/images/' . $fileName;
if (!is_file($path) || !is_readable($path)) {
  fwrite(STDERR, "Studio-Logo fehlt oder ist nicht lesbar.\n");
  exit(2);
}

$size = @getimagesize($path);
if (!is_array($size) || ($size[2] ?? 0) !== IMAGETYPE_PNG) {
  fwrite(STDERR, "Studio-Logo ist keine gueltige PNG-Datei.\n");
  exit(2);
}
if ((int)$size[0] < 1000 || (int)$size[1] < 500) {
  fwrite(STDERR, "Studio-Logo ist unerwartet klein.\n");
  exit(2);
}

$actualSha256 = hash_file('sha256', $path);
if (!is_string($actualSha256) || !hash_equals($approvedSha256, $actualSha256)) {
  fwrite(STDERR, "Studio-Logo entspricht nicht dem freigegebenen Original.\n");
  exit(3);
}

$image = @imagecreatefrompng($path);
if ($image === false) {
  fwrite(STDERR, "Studio-Logo konnte nicht mit GD gelesen werden.\n");
  exit(2);
}

$corner = imagecolorat($image, 0, 0);
$alpha = ($corner >> 24) & 0x7f;
imagedestroy($image);

if ($alpha < 120) {
  fwrite(STDERR, "Studio-Logo besitzt am Bildrand keine echte Transparenz.\n");
  exit(4);
}

foreach (['index.html', 'login.html', 'settings.html', 'admin/index.html'] as $htmlFile) {
  $html = (string)file_get_contents(dirname(__DIR__) . '/' . $htmlFile);
  if ($html === '' || !str_contains($html, $fileName)) {
    fwrite(STDERR, "Studio-Logo besitzt keine eindeutige Cache-Buster-Referenz in {$htmlFile}.\n");
    exit(5);
  }
}

fwrite(STDOUT, "Logo-Selftest OK: freigegebenes Original, RGBA-Transparenz und neuer Assetpfad aktiv, {$size[0]}x{$size[1]} Pixel.\n");
