<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/profile_store.php';

$pdo = stu_pdo();
$uid = stu_require_user_id();
$slot = strtolower(trim((string)($_GET['slot'] ?? '')));
if (!in_array($slot, ['user', 'assistant'], true)) {
  http_response_code(404);
  exit;
}

$st = $pdo->prepare(
  'SELECT stored_name, mime_type, file_size FROM stu_coreui_profile_media WHERE user_id = ? AND slot = ? LIMIT 1'
);
$st->execute([$uid, $slot]);
$row = $st->fetch(PDO::FETCH_ASSOC);
if (!$row) {
  http_response_code(404);
  exit;
}
$path = coreui_profile_media_dir() . '/' . basename((string)$row['stored_name']);
if (!is_file($path) || !is_readable($path)) {
  http_response_code(404);
  exit;
}

header('Content-Type: ' . ((string)$row['mime_type'] ?: 'image/png'));
header('Content-Length: ' . (string)filesize($path));
header('Cache-Control: private, no-cache, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline; filename="coreui-' . $slot . '.png"');
readfile($path);
exit;
