<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ai_settings.php';

$pdo = stu_pdo();
$uid = stu_require_user_id();
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'GET') {
  stu_json([
    'ok' => true,
    'settings' => coreui_ai_settings_load($pdo, $uid),
    'limits' => [
      'system_prompt_chars' => coreui_ai_prompt_limit($pdo),
      'memory_limit_max' => 60,
      'num_predict_min' => 256,
      'num_predict_max' => 16384,
      'temperature_min' => 0.1,
      'temperature_max' => 1.5,
    ],
    'external' => coreui_ai_external_capability($pdo),
    'csrf_token' => stu_csrf_token(),
    'permission_level' => stu_get_permission_level($pdo),
  ]);
}

if ($method !== 'POST') stu_json(['ok' => false, 'error' => 'method_not_allowed'], 405);
stu_require_csrf();

$body = stu_read_json_body();
try {
  $settings = coreui_ai_settings_save($pdo, $uid, $body);
  stu_json([
    'ok' => true,
    'settings' => $settings,
    'external' => coreui_ai_external_capability($pdo),
  ]);
} catch (RuntimeException $e) {
  stu_json(['ok' => false, 'error' => $e->getMessage()], 503);
} catch (Throwable $e) {
  if (function_exists('stu__log_error')) {
    stu__log_error(['type' => 'user_ai_settings_save_failed', 'uid' => $uid, 'message' => $e->getMessage()]);
  }
  stu_json(['ok' => false, 'error' => 'save_failed'], 500);
}
