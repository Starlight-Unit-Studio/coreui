<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/knowledge_store.php';

$pdo = stu_pdo();
$uid = stu_require_user_id();
stu_enforce_maintenance($pdo, $uid);
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

function coreui_knowledge_response(PDO $pdo, int $uid): void {
  $limits = coreui_knowledge_limits($pdo);
  stu_json([
    'ok' => true,
    'sources' => coreui_knowledge_list($pdo, $uid),
    'totals' => coreui_knowledge_totals($pdo, $uid),
    'limits' => [
      'max_sources' => $limits['max_sources'],
      'max_file_mb' => (int)round($limits['max_file_bytes'] / 1048576),
      'max_total_chars' => $limits['max_total_chars'],
      'formats' => ['txt', 'md', 'pdf', 'docx'],
    ],
    'csrf_token' => stu_csrf_token(),
  ]);
}

if (!coreui_knowledge_schema_ready($pdo)) stu_json(['ok' => false, 'error' => 'missing_schema_004'], 503);
if ($method === 'GET') coreui_knowledge_response($pdo, $uid);
if ($method !== 'POST') stu_json(['ok' => false, 'error' => 'method_not_allowed'], 405);
stu_require_csrf();

$contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
try {
  if (str_contains($contentType, 'multipart/form-data')) {
    if (empty($_FILES['file'])) stu_json(['ok' => false, 'error' => 'no_file_uploaded'], 400);
    $result = coreui_knowledge_ingest($pdo, $uid, $_FILES['file'], (string)($_POST['title'] ?? ''));
    stu_json([
      'ok' => true,
      'source' => $result,
      'sources' => coreui_knowledge_list($pdo, $uid),
      'totals' => coreui_knowledge_totals($pdo, $uid),
    ]);
  }
  $body = stu_read_json_body();
  $action = strtolower(trim((string)($body['action'] ?? '')));
  if ($action === 'delete') {
    $deleted = coreui_knowledge_delete($pdo, $uid, strtolower(trim((string)($body['uuid'] ?? ''))));
    stu_json([
      'ok' => true,
      'deleted' => $deleted,
      'sources' => coreui_knowledge_list($pdo, $uid),
      'totals' => coreui_knowledge_totals($pdo, $uid),
    ]);
  }
  stu_json(['ok' => false, 'error' => 'unknown_action'], 404);
} catch (InvalidArgumentException $e) {
  stu_json(['ok' => false, 'error' => $e->getMessage()], 400);
} catch (RuntimeException $e) {
  $notFound = $e->getMessage() === 'knowledge_source_not_found';
  stu_json(['ok' => false, 'error' => $e->getMessage()], $notFound ? 404 : 422);
} catch (Throwable $e) {
  if (function_exists('stu__log_error')) {
    stu__log_error(['type' => 'coreui_knowledge_failed', 'uid' => $uid, 'message' => $e->getMessage()]);
  }
  stu_json(['ok' => false, 'error' => 'knowledge_failed'], 500);
}
