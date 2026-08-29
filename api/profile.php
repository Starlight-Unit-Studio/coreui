<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/profile_store.php';

$pdo = stu_pdo();
$uid = stu_require_user_id();
stu_enforce_maintenance($pdo, $uid);
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'GET') {
  if (!coreui_profile_schema_ready($pdo)) stu_json(['ok' => false, 'error' => 'missing_schema_004'], 503);
  stu_json([
    'ok' => true,
    'profile' => coreui_profile_load($pdo, $uid),
    'csrf_token' => stu_csrf_token(),
    'limits' => ['name_chars' => 64, 'avatar_mb' => 4, 'avatar_px' => 512],
  ]);
}

if ($method !== 'POST') stu_json(['ok' => false, 'error' => 'method_not_allowed'], 405);
stu_require_csrf();

$contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
try {
  if (str_contains($contentType, 'multipart/form-data')) {
    if (empty($_FILES['file'])) stu_json(['ok' => false, 'error' => 'no_file_uploaded'], 400);
    $uploadError = (int)($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($uploadError !== UPLOAD_ERR_OK) stu_json(['ok' => false, 'error' => 'upload_failed', 'code' => $uploadError], 400);
    $slot = strtolower(trim((string)($_POST['slot'] ?? '')));
    $profile = coreui_profile_store_avatar($pdo, $uid, $slot, $_FILES['file']);
    stu_json(['ok' => true, 'profile' => $profile]);
  }

  $body = stu_read_json_body();
  $action = strtolower(trim((string)($body['action'] ?? 'save')));
  if ($action === 'save') {
    $profile = coreui_profile_save(
      $pdo,
      $uid,
      (string)($body['display_name'] ?? ''),
      (string)($body['assistant_name'] ?? '')
    );
    stu_json(['ok' => true, 'profile' => $profile]);
  }
  if ($action === 'avatar_delete') {
    $slot = strtolower(trim((string)($body['slot'] ?? '')));
    $deleted = coreui_profile_delete_avatar($pdo, $uid, $slot);
    stu_json(['ok' => true, 'deleted' => $deleted, 'profile' => coreui_profile_load($pdo, $uid)]);
  }
  stu_json(['ok' => false, 'error' => 'unknown_action'], 404);
} catch (InvalidArgumentException $e) {
  stu_json(['ok' => false, 'error' => $e->getMessage()], 400);
} catch (RuntimeException $e) {
  $code = $e->getMessage() === 'missing_schema_004' ? 503 : 500;
  stu_json(['ok' => false, 'error' => $e->getMessage()], $code);
} catch (Throwable $e) {
  if (function_exists('stu__log_error')) {
    stu__log_error(['type' => 'coreui_profile_failed', 'uid' => $uid, 'message' => $e->getMessage()]);
  }
  stu_json(['ok' => false, 'error' => 'profile_failed'], 500);
}
