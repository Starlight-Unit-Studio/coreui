<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/console_session_store.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$pdo = stu_pdo();
$uid = stu_require_user_id();
stu_enforce_maintenance($pdo, (int)$uid);

if (!coreui_console_session_schema_ready($pdo)) {
  stu_json(['ok'=>false, 'error'=>'session_migration_required'], 503);
}

$action = strtolower(trim((string)($_GET['action'] ?? 'list')));
$body = [];
if ($method === 'POST') {
  $body = stu_read_json_body();
  $action = strtolower(trim((string)($body['action'] ?? $action)));
}

try {
  if ($action === 'list') {
    $includeArchived = !empty($_GET['include_archived']);
    $rows = coreui_console_session_list($pdo, (int)$uid, $includeArchived);
    stu_json(['ok'=>true, 'sessions'=>$rows, 'count'=>count($rows)]);
  }

  if ($method !== 'POST') stu_json(['ok'=>false, 'error'=>'method_not_allowed'], 405);

  if ($action === 'create') {
    $session = coreui_console_session_create($pdo, (int)$uid, (string)($body['title'] ?? ''));
    stu_json(['ok'=>true, 'session'=>$session] + $session, 201);
  }

  $sessionId = coreui_console_session_normalize_id($body['id'] ?? $body['session_id'] ?? '');
  if ($sessionId === '') stu_json(['ok'=>false, 'error'=>'invalid_session'], 400);

  if ($action === 'rename') {
    $title = coreui_console_session_rename($pdo, (int)$uid, $sessionId, (string)($body['title'] ?? ''));
    stu_json(['ok'=>true, 'id'=>$sessionId, 'title'=>$title]);
  }

  if ($action === 'mark_read' || $action === 'update_since') {
    coreui_console_session_require($pdo, (int)$uid, $sessionId, true);
    $messageId = max(0, (int)($body['message_id'] ?? $body['since_id'] ?? 0));
    coreui_console_session_mark_read($pdo, (int)$uid, $sessionId, $messageId);
    stu_json(['ok'=>true, 'id'=>$sessionId, 'last_read_message_id'=>$messageId]);
  }

  if ($action === 'archive') {
    coreui_console_session_archive($pdo, (int)$uid, $sessionId);
    stu_json(['ok'=>true, 'id'=>$sessionId, 'archived'=>true]);
  }

  if ($action === 'restore') {
    coreui_console_session_restore($pdo, (int)$uid, $sessionId);
    stu_json(['ok'=>true, 'id'=>$sessionId, 'archived'=>false]);
  }

  if ($action === 'delete' || $action === 'delete_permanently') {
    $confirmation = (string)($body['confirmation'] ?? '');
    if (!hash_equals('DELETE:' . $sessionId, $confirmation)) {
      throw new InvalidArgumentException('delete_confirmation_required');
    }
    $deleted = coreui_console_session_delete($pdo, (int)$uid, $sessionId);
    stu_json(['ok'=>true, 'id'=>$sessionId, 'deleted'=>true, 'counts'=>$deleted]);
  }

  stu_json(['ok'=>false, 'error'=>'unknown_action'], 400);
} catch (InvalidArgumentException $e) {
  stu_json(['ok'=>false, 'error'=>$e->getMessage()], 400);
} catch (RuntimeException $e) {
  $code = $e->getMessage();
  $status = ($code === 'session_not_found') ? 404
    : (in_array($code, ['session_limit_reached', 'session_must_be_archived', 'session_busy', 'session_delete_conflict'], true) ? 409 : 400);
  stu_json(['ok'=>false, 'error'=>$code], $status);
} catch (Throwable $e) {
  if (function_exists('stu__log_error')) {
    stu__log_error(['type'=>'console_sessions_exception', 'message'=>$e->getMessage()]);
  }
  stu_json(['ok'=>false, 'error'=>'session_operation_failed'], 500);
}
