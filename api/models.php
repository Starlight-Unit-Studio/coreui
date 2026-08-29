<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/model_catalog.php';

stu_require_user_id();
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
  stu_json(['ok'=>false, 'error'=>'method_not_allowed'], 405);
}

try {
  $models = coreui_ollama_models();
  stu_json([
    'ok'=>true,
    'models'=>$models,
    'default_model'=>defined('STU_EMBER_MODEL') ? (string)STU_EMBER_MODEL : '',
  ]);
} catch (RuntimeException $e) {
  stu_json(['ok'=>false, 'error'=>$e->getMessage()], 503);
}
