<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/ai_settings.php';
require_once __DIR__ . '/profile_store.php';

$pdo = stu_pdo();
$uid = stu_require_user_id();
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
  stu_json(['ok'=>false, 'error'=>'method_not_allowed'], 405);
}
stu_require_csrf();

function coreui_export_rows(PDO $pdo, string $sql, array $params): array {
  $st = $pdo->prepare($sql);
  $st->execute($params);
  return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function coreui_export_profile_media(PDO $pdo, int $uid): array {
  $rows = coreui_export_rows(
    $pdo,
    'SELECT slot,original_name,mime_type,file_size,width_px,height_px,stored_name,created_at '
      . 'FROM stu_coreui_profile_media WHERE user_id=? ORDER BY slot',
    [$uid]
  );
  foreach ($rows as &$row) {
    $storedName = basename((string)($row['stored_name'] ?? ''));
    unset($row['stored_name']);
    $path = coreui_profile_media_dir() . '/' . $storedName;
    $raw = $storedName !== '' && is_file($path) ? @file_get_contents($path) : false;
    $row['data_base64'] = is_string($raw) ? base64_encode($raw) : null;
  }
  unset($row);
  return $rows;
}

try {
  $accountRows = coreui_export_rows(
    $pdo,
    'SELECT username,is_guest,permission_level,banned_until,banned_reason,created_at,password_changed_at,last_login_at '
      . 'FROM stu_users WHERE id=? LIMIT 1',
    [$uid]
  );
  if (!$accountRows) stu_json(['ok'=>false, 'error'=>'account_not_found'], 404);

  $sessions = coreui_export_rows(
    $pdo,
    'SELECT id,title,since_id,last_message_id,last_read_message_id,created_at,updated_at,archived_at '
      . 'FROM stu_console_sessions WHERE user_id=? ORDER BY created_at,id',
    [$uid]
  );
  $messages = coreui_export_rows(
    $pdo,
    "SELECT id,session_id,character_id,character_name,message,file_uuid,reply_to_id,created_at,deleted_at "
      . "FROM stu_chat_messages WHERE channel='console' AND user_id=? ORDER BY id",
    [$uid]
  );
  // thinking_content is deliberately never selected or exported.

  $export = [
    'format' => 'ember-coreui-account-export',
    'format_version' => 1,
    'coreui_version' => '0.4.1-alpha',
    'generated_at' => gmdate('c'),
    'public_base_url' => defined('STU_PUBLIC_BASE_URL') ? (string)STU_PUBLIC_BASE_URL : '',
    'account' => $accountRows[0],
    'profile' => coreui_profile_load($pdo, $uid),
    'profile_media' => coreui_export_profile_media($pdo, $uid),
    'ai_settings' => coreui_ai_settings_load($pdo, $uid),
    'characters' => coreui_export_rows(
      $pdo,
      'SELECT id,name,world_id,portrait_index,portrait_path,gender,created_at FROM stu_characters WHERE user_id=? ORDER BY created_at,id',
      [$uid]
    ),
    'memories' => coreui_export_rows(
      $pdo,
      "SELECT id,fact,relevance,scope,character_id,created_at,updated_at,last_used_at FROM ember_memories "
        . "WHERE (scope='user' AND user_id=?) OR (scope='character' AND character_id IN "
        . '(SELECT id FROM stu_characters WHERE user_id=?)) ORDER BY id',
      [$uid, $uid]
    ),
    'knowledge_sources' => coreui_export_rows(
      $pdo,
      'SELECT uuid,title,original_name,mime_type,file_size,char_count,chunk_count,status,error_message,created_at,updated_at '
        . 'FROM stu_user_knowledge_sources WHERE user_id=? ORDER BY created_at,uuid',
      [$uid]
    ),
    'knowledge_chunks' => coreui_export_rows(
      $pdo,
      'SELECT source_uuid,title,chunk_no,chunk_text,created_at FROM stu_user_knowledge_chunks '
        . 'WHERE user_id=? ORDER BY source_uuid,chunk_no',
      [$uid]
    ),
    'conversation_sessions' => $sessions,
    'conversation_messages' => $messages,
    'message_attachments' => coreui_export_rows(
      $pdo,
      'SELECT a.message_id,a.media_uuid,a.position,a.created_at FROM stu_console_message_attachments a '
        . 'JOIN stu_chat_messages m ON m.id=a.message_id AND m.user_id=a.user_id '
        . "WHERE a.user_id=? AND m.channel='console' ORDER BY a.message_id,a.position",
      [$uid]
    ),
    'attachment_metadata' => coreui_export_rows(
      $pdo,
      'SELECT uuid,kind,orig_name,mime_type,file_size,created_at FROM stu_console_media WHERE user_id=? ORDER BY id',
      [$uid]
    ),
    'login_sessions' => coreui_auth_session_list($pdo, $uid),
    'security_notice' => [
      'password_hashes_exported' => false,
      'login_tokens_exported' => false,
      'thinking_content_exported' => false,
      'private_server_paths_exported' => false,
      'uploaded_document_binaries_exported' => false,
    ],
  ];

  try {
    $pdo->prepare(
      "INSERT INTO stu_admin_audit (actor_user_id,action_name,target_type,target_id,detail_json,created_at) "
      . "VALUES (?,'account.data_exported','account',?,NULL,NOW())"
    )->execute([$uid, (string)$uid]);
  } catch (Throwable $auditError) {}

  $json = json_encode($export, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
  if (!is_string($json)) throw new RuntimeException('export_encode_failed');
  $filename = 'ember-coreui-account-' . gmdate('Ymd-His') . '.json';
  header('Content-Type: application/json; charset=utf-8');
  header('Content-Disposition: attachment; filename="' . $filename . '"');
  header('Cache-Control: no-store, max-age=0');
  header('X-Content-Type-Options: nosniff');
  echo $json;
  exit;
} catch (Throwable $e) {
  if (function_exists('stu__log_error')) {
    stu__log_error(['type'=>'account_export_failed', 'uid'=>$uid, 'message'=>$e->getMessage()]);
  }
  stu_json(['ok'=>false, 'error'=>'export_failed'], 500);
}
