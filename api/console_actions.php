<?php
declare(strict_types=1);

define('STU_CHAT_LIB', 1);
require __DIR__ . '/chat.php';
require_once __DIR__ . '/console_action_store.php';

$pdo = stu_pdo();
$uid = stu_require_user_id();
stu_enforce_maintenance($pdo, (int)$uid);
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

try {
  if ($method === 'GET') {
    if (!coreui_console_actions_schema_ready($pdo)) {
      stu_json(['ok'=>false, 'error'=>'message_actions_migration_required'], 503);
    }
    $requestId = strtolower(trim((string)($_GET['request_id'] ?? '')));
    $request = coreui_console_generation_load($pdo, (int)$uid, $requestId);
    $sessionId = coreui_console_session_normalize_id($_GET['session_id'] ?? '');
    if ($sessionId === '' || !hash_equals((string)$request['session_id'], $sessionId)) {
      stu_json(['ok'=>false, 'error'=>'generation_request_not_found'], 404);
    }
    $request = coreui_console_generation_reconcile($pdo, (int)$uid, $requestId, $request);
    coreui_console_generation_expire($pdo, (int)$uid);
    if (in_array((string)$request['status'], ['issued', 'running'], true)) {
      $request = coreui_console_generation_load($pdo, (int)$uid, $requestId);
    }
    stu_json([
      'ok' => true,
      'request_id' => $requestId,
      'session_id' => $sessionId,
      'status' => (string)$request['status'],
      'mode' => (string)$request['mode'],
      'trigger_message_id' => (int)$request['trigger_message_id'],
      'source_response_id' => (int)$request['source_response_id'],
      'response_message_id' => (int)($request['response_message_id'] ?? 0),
      'error' => (string)($request['error_code'] ?? ''),
    ]);
  }

  if ($method !== 'POST') stu_json(['ok'=>false, 'error'=>'method_not_allowed'], 405);
  stu_require_csrf();
  $body = stu_read_json_body();
  $action = strtolower(trim((string)($body['action'] ?? '')));
  $sessionId = coreui_console_session_normalize_id($body['session_id'] ?? '');
  if ($sessionId === '') stu_json(['ok'=>false, 'error'=>'invalid_session'], 400);

  if ($action === 'feedback') {
    $messageId = (int)($body['message_id'] ?? 0);
    $value = strtolower(trim((string)($body['value'] ?? '')));
    $characterId = trim((string)($body['character_id'] ?? ''));
    chat_require_character($pdo, (int)$uid, $characterId);
    $selected = coreui_console_feedback_toggle(
      $pdo,
      (int)$uid,
      $sessionId,
      $messageId,
      $value,
      $characterId
    );
    stu_json(['ok'=>true, 'message_id'=>$messageId, 'feedback'=>$selected]);
  }

  if ($action === 'prepare_generation') {
    $characterId = trim((string)($body['character_id'] ?? ''));
    chat_require_character($pdo, (int)$uid, $characterId);
    $request = coreui_console_generation_prepare(
      $pdo,
      (int)$uid,
      $sessionId,
      (int)($body['source_response_id'] ?? 0),
      strtolower(trim((string)($body['mode'] ?? '')))
    );
    stu_json(['ok'=>true, 'request'=>$request], 201);
  }

  stu_json(['ok'=>false, 'error'=>'unknown_action'], 400);
} catch (InvalidArgumentException $e) {
  stu_json(['ok'=>false, 'error'=>$e->getMessage()], 400);
} catch (RuntimeException $e) {
  $error = $e->getMessage();
  $status = str_contains($error, 'not_found') ? 404
    : (in_array($error, ['generation_busy', 'session_busy'], true) ? 409
      : ($error === 'message_actions_migration_required' ? 503 : 400));
  stu_json(['ok'=>false, 'error'=>$error], $status);
} catch (Throwable $e) {
  if (function_exists('stu__log_error')) {
    stu__log_error(['type'=>'console_actions_exception', 'uid'=>(int)$uid, 'message'=>$e->getMessage()]);
  }
  stu_json(['ok'=>false, 'error'=>'console_action_failed'], 500);
}
