<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ai_settings.php';
require_once __DIR__ . '/account_store.php';
require_once __DIR__ . '/profile_store.php';

$pdo = stu_pdo();
$actorId = stu_require_user_id();
$actorLevel = stu_require_permission_at_most(1, $pdo);
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action = strtolower(trim((string)($_GET['action'] ?? 'overview')));

function coreui_admin_count(PDO $pdo, string $sql, array $params = []): int {
  try {
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return (int)($st->fetchColumn() ?: 0);
  } catch (Throwable $e) {
    return 0;
  }
}

function coreui_admin_audit(PDO $pdo, int $actorId, string $action, string $type = '', string $targetId = '', array $detail = []): void {
  try {
    $st = $pdo->prepare(
      'INSERT INTO stu_admin_audit (actor_user_id, action_name, target_type, target_id, detail_json) VALUES (?, ?, ?, ?, ?)'
    );
    $json = $detail ? json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
    $st->execute([$actorId, $action, $type, $targetId, $json]);
  } catch (Throwable $e) {
    if (function_exists('stu__log_error')) {
      stu__log_error(['type' => 'admin_audit_failed', 'action' => $action, 'message' => $e->getMessage()]);
    }
  }
}

function coreui_admin_log_path(): string {
  return dirname(__DIR__) . '/logs/stu_error.log';
}

function coreui_admin_tail(string $file, int $limit): array {
  if (!is_file($file) || !is_readable($file)) return [];
  $lines = file($file, FILE_IGNORE_NEW_LINES);
  if (!is_array($lines)) return [];
  return array_slice($lines, -max(1, min(2000, $limit)));
}

function coreui_admin_setting_rows(PDO $pdo): array {
  $defaults = [
    'registration_enabled' => '0',
    'maintenance_enabled' => '0',
    'maintenance_message' => '',
    'memory_default_enabled' => '1',
    'user_system_prompt_max_chars' => '6000',
    'tool_web_enabled' => defined('STU_EMBER_WEB_ENABLED') && STU_EMBER_WEB_ENABLED ? '1' : '0',
    'tool_browse_enabled' => defined('STU_EMBER_BROWSE_ENABLED') && STU_EMBER_BROWSE_ENABLED ? '1' : '0',
    'tool_python_enabled' => defined('STU_EMBER_PY_ENABLED') && STU_EMBER_PY_ENABLED ? '1' : '0',
    'external_api_enabled' => '0',
    'external_api_label' => 'OpenAI-kompatibel',
    'external_api_base_url' => '',
    'external_api_model' => '',
  ];
  $out = [];
  foreach ($defaults as $key => $default) {
    $out[$key] = (string)stu_app_setting_get($pdo, $key, $default);
  }
  return $out;
}

function coreui_admin_get_overview(PDO $pdo, int $actorLevel): void {
  $stats = [
    'users' => coreui_admin_count($pdo, 'SELECT COUNT(*) FROM stu_users WHERE is_guest = 0'),
    'sessions' => coreui_admin_count($pdo, 'SELECT COUNT(*) FROM stu_console_sessions'),
    'messages' => coreui_admin_count($pdo, "SELECT COUNT(*) FROM stu_chat_messages WHERE channel = 'console' AND deleted_at IS NULL"),
    'memories' => coreui_admin_count($pdo, 'SELECT COUNT(*) FROM ember_memories'),
    'lore_chunks' => coreui_admin_count($pdo, 'SELECT COUNT(*) FROM ember_knowledge_chunks'),
    'user_knowledge_chunks' => coreui_admin_count($pdo, 'SELECT COUNT(*) FROM stu_user_knowledge_chunks'),
    'browse_active' => coreui_admin_count($pdo, "SELECT COUNT(*) FROM stu_ember_browse_jobs WHERE status IN ('queued','running')"),
  ];
  $logPath = coreui_admin_log_path();
  $stats['log_bytes'] = is_file($logPath) ? (int)filesize($logPath) : 0;
  stu_json([
    'ok' => true,
    'stats' => $stats,
    'settings' => coreui_admin_setting_rows($pdo),
    'external' => coreui_ai_external_capability($pdo),
    'permission_level' => $actorLevel,
    'csrf_token' => stu_csrf_token(),
  ]);
}

if ($method === 'GET') {
  if ($action === 'overview') coreui_admin_get_overview($pdo, $actorLevel);

  if ($action === 'users') {
    $q = trim((string)($_GET['q'] ?? ''));
    $limit = max(1, min(250, (int)($_GET['limit'] ?? 100)));
    $where = '';
    $params = [];
    if ($q !== '') {
      $where = 'WHERE u.username LIKE ? OR c.name LIKE ? OR p.display_name LIKE ?';
      $params = ['%' . $q . '%', '%' . $q . '%', '%' . $q . '%'];
    }
    $st = $pdo->prepare(
      'SELECT u.id, u.username, u.permission_level, u.banned_until, u.banned_reason, u.created_at, '
      . 'u.chat_seconds_lifetime, p.display_name, p.assistant_name, MAX(c.name) AS character_name, COUNT(DISTINCT s.id) AS session_count '
      . 'FROM stu_users u LEFT JOIN stu_characters c ON c.user_id = u.id '
      . 'LEFT JOIN stu_coreui_profiles p ON p.user_id = u.id '
      . 'LEFT JOIN stu_console_sessions s ON s.user_id = u.id ' . $where
      . ' GROUP BY u.id, u.username, u.permission_level, u.banned_until, u.banned_reason, u.created_at, u.chat_seconds_lifetime, p.display_name, p.assistant_name '
      . 'ORDER BY u.id DESC LIMIT ' . $limit
    );
    $st->execute($params);
    stu_json(['ok' => true, 'items' => $st->fetchAll(PDO::FETCH_ASSOC) ?: []]);
  }

  if ($action === 'memories') {
    $q = trim((string)($_GET['q'] ?? ''));
    $scope = strtolower(trim((string)($_GET['scope'] ?? '')));
    $limit = max(1, min(500, (int)($_GET['limit'] ?? 150)));
    $where = [];
    $params = [];
    if ($q !== '') { $where[] = 'm.fact LIKE ?'; $params[] = '%' . $q . '%'; }
    if (in_array($scope, ['global', 'user', 'character'], true)) { $where[] = 'm.scope = ?'; $params[] = $scope; }
    $sql = 'SELECT m.id, m.fact, m.relevance, m.scope, m.user_id, m.character_id, m.created_at, m.updated_at, '
      . 'u.username, c.name AS character_name FROM ember_memories m '
      . 'LEFT JOIN stu_users u ON u.id = m.user_id LEFT JOIN stu_characters c ON c.id = m.character_id '
      . ($where ? ('WHERE ' . implode(' AND ', $where) . ' ') : '')
      . 'ORDER BY m.updated_at DESC, m.id DESC LIMIT ' . $limit;
    $st = $pdo->prepare($sql);
    $st->execute($params);
    stu_json(['ok' => true, 'items' => $st->fetchAll(PDO::FETCH_ASSOC) ?: []]);
  }

  if ($action === 'lore') {
    $q = trim((string)($_GET['q'] ?? ''));
    $source = trim((string)($_GET['source'] ?? ''));
    $sources = $pdo->query(
      'SELECT source, COUNT(*) AS chunks, MAX(created_at) AS latest FROM ember_knowledge_chunks GROUP BY source ORDER BY source'
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $items = [];
    if ($q !== '' || $source !== '') {
      $where = [];
      $params = [];
      if ($q !== '') { $where[] = '(title LIKE ? OR chunk_text LIKE ?)'; $params[] = '%' . $q . '%'; $params[] = '%' . $q . '%'; }
      if ($source !== '') { $where[] = 'source = ?'; $params[] = $source; }
      $st = $pdo->prepare(
        'SELECT id, source, title, chunk_no, chunk_text, created_at FROM ember_knowledge_chunks '
        . 'WHERE ' . implode(' AND ', $where) . ' ORDER BY source, chunk_no LIMIT 100'
      );
      $st->execute($params);
      $items = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    stu_json(['ok' => true, 'sources' => $sources, 'items' => $items]);
  }

  if ($action === 'logs') {
    $limit = max(1, min(2000, (int)($_GET['limit'] ?? 500)));
    stu_json(['ok' => true, 'lines' => coreui_admin_tail(coreui_admin_log_path(), $limit)]);
  }

  if ($action === 'audit') {
    $limit = max(1, min(500, (int)($_GET['limit'] ?? 100)));
    $st = $pdo->query(
      'SELECT a.id, a.actor_user_id, u.username AS actor, a.action_name, a.target_type, a.target_id, a.detail_json, a.created_at '
      . 'FROM stu_admin_audit a LEFT JOIN stu_users u ON u.id = a.actor_user_id ORDER BY a.id DESC LIMIT ' . $limit
    );
    stu_json(['ok' => true, 'items' => $st->fetchAll(PDO::FETCH_ASSOC) ?: []]);
  }

  stu_json(['ok' => false, 'error' => 'unknown_action'], 404);
}

if ($method !== 'POST') stu_json(['ok' => false, 'error' => 'method_not_allowed'], 405);
stu_require_csrf();
$body = stu_read_json_body();

if ($action === 'user_create') {
  $email = coreui_account_clean_email((string)($body['username'] ?? ''));
  $password = (string)($body['password'] ?? '');
  $displayName = coreui_account_clean_display_name(
    (string)($body['display_name'] ?? ''),
    coreui_account_default_display_name($email)
  );
  $newLevel = max(1, min(4, (int)($body['permission_level'] ?? 4)));
  if (!coreui_account_valid_email($email)) stu_json(['ok' => false, 'error' => 'invalid_username'], 400);
  if (strlen($password) < 12 || strlen($password) > 1024) stu_json(['ok' => false, 'error' => 'invalid_password'], 400);
  if ($actorLevel > 0 && $newLevel <= $actorLevel) stu_json(['ok' => false, 'error' => 'protected_account'], 403);
  try {
    $created = coreui_account_create($pdo, $email, $password, $displayName, $newLevel);
  } catch (RuntimeException $e) {
    if ($e->getMessage() === 'username_taken') stu_json(['ok' => false, 'error' => 'username_taken'], 409);
    if ($e->getMessage() === 'missing_schema_004') stu_json(['ok' => false, 'error' => 'missing_schema_004'], 503);
    if (function_exists('stu__log_error')) {
      stu__log_error(['type' => 'admin_user_create_failed', 'actor' => $actorId, 'message' => $e->getMessage()]);
    }
    stu_json(['ok' => false, 'error' => 'user_create_failed'], 500);
  } catch (Throwable $e) {
    if (function_exists('stu__log_error')) {
      stu__log_error(['type' => 'admin_user_create_failed', 'actor' => $actorId, 'message' => $e->getMessage()]);
    }
    stu_json(['ok' => false, 'error' => 'user_create_failed'], 500);
  }
  coreui_admin_audit($pdo, $actorId, 'user_create', 'user', (string)$created['user_id'], [
    'permission_level' => $newLevel,
    'display_name' => $displayName,
  ]);
  stu_json(['ok' => true, 'user' => [
    'id' => (int)$created['user_id'],
    'username' => $email,
    'display_name' => $displayName,
    'permission_level' => $newLevel,
    'character_id' => (string)$created['character_id'],
  ]]);
}

if ($action === 'user_update') {
  $targetId = (int)($body['user_id'] ?? 0);
  $newLevel = max(0, min(4, (int)($body['permission_level'] ?? 4)));
  $reason = trim((string)($body['banned_reason'] ?? ''));
  if (function_exists('mb_substr')) $reason = mb_substr($reason, 0, 255, 'UTF-8');
  else $reason = substr($reason, 0, 255);
  $untilRaw = trim((string)($body['banned_until'] ?? ''));
  $until = null;
  if ($untilRaw !== '') {
    $ts = strtotime($untilRaw);
    if ($ts === false) stu_json(['ok' => false, 'error' => 'invalid_ban_time'], 400);
    $until = date('Y-m-d H:i:s', $ts);
  }
  if ($targetId <= 0) stu_json(['ok' => false, 'error' => 'invalid_user'], 400);

  $st = $pdo->prepare('SELECT id, username, permission_level FROM stu_users WHERE id = ? LIMIT 1');
  $st->execute([$targetId]);
  $target = $st->fetch(PDO::FETCH_ASSOC);
  if (!$target) stu_json(['ok' => false, 'error' => 'user_not_found'], 404);
  $oldLevel = (int)$target['permission_level'];
  if ($actorLevel > 0 && ($oldLevel <= $actorLevel || $newLevel <= $actorLevel)) {
    stu_json(['ok' => false, 'error' => 'protected_account'], 403);
  }
  if ($targetId === $actorId && ($newLevel !== $oldLevel || $until !== null)) {
    stu_json(['ok' => false, 'error' => 'self_lockout_blocked'], 409);
  }

  $up = $pdo->prepare('UPDATE stu_users SET permission_level = ?, banned_until = ?, banned_reason = ? WHERE id = ?');
  $up->execute([$newLevel, $until, $until ? $reason : '', $targetId]);
  coreui_admin_audit($pdo, $actorId, 'user_update', 'user', (string)$targetId, [
    'old_level' => $oldLevel,
    'new_level' => $newLevel,
    'banned_until' => $until,
  ]);
  stu_json(['ok' => true]);
}

if ($action === 'memory_upsert') {
  $id = max(0, (int)($body['id'] ?? 0));
  $fact = trim((string)($body['fact'] ?? ''));
  $scope = strtolower(trim((string)($body['scope'] ?? 'global')));
  $relevance = max(1, min(10, (int)($body['relevance'] ?? 5)));
  $userId = (int)($body['user_id'] ?? 0);
  $characterId = trim((string)($body['character_id'] ?? ''));
  $length = function_exists('mb_strlen') ? mb_strlen($fact, 'UTF-8') : strlen($fact);
  if ($length < 3 || $length > 2000) stu_json(['ok' => false, 'error' => 'invalid_fact'], 400);
  if (!in_array($scope, ['global', 'user', 'character'], true)) stu_json(['ok' => false, 'error' => 'invalid_scope'], 400);
  if ($scope === 'user' && $userId <= 0) stu_json(['ok' => false, 'error' => 'user_required'], 400);
  if ($scope === 'character' && $characterId === '') stu_json(['ok' => false, 'error' => 'character_required'], 400);
  $normalized = function_exists('mb_strtolower') ? mb_strtolower($fact, 'UTF-8') : strtolower($fact);
  $hash = md5($normalized);
  $memoryUserId = $scope === 'user' ? $userId : null;
  $memoryCharacterId = $scope === 'character' ? $characterId : null;
  if ($id > 0) {
    $st = $pdo->prepare(
      'UPDATE ember_memories SET fact = ?, relevance = ?, scope = ?, user_id = ?, character_id = ?, fact_hash = ?, updated_at = NOW() WHERE id = ?'
    );
    $st->execute([$fact, $relevance, $scope, $memoryUserId, $memoryCharacterId, $hash, $id]);
    if ($st->rowCount() === 0 && coreui_admin_count($pdo, 'SELECT COUNT(*) FROM ember_memories WHERE id = ?', [$id]) === 0) {
      stu_json(['ok' => false, 'error' => 'memory_not_found'], 404);
    }
  } else {
    $st = $pdo->prepare(
      'INSERT INTO ember_memories (fact, relevance, scope, user_id, character_id, fact_hash, last_used_at) VALUES (?, ?, ?, ?, ?, ?, NOW())'
    );
    $st->execute([$fact, $relevance, $scope, $memoryUserId, $memoryCharacterId, $hash]);
    $id = (int)$pdo->lastInsertId();
  }
  coreui_admin_audit($pdo, $actorId, 'memory_upsert', 'memory', (string)$id, ['scope' => $scope]);
  stu_json(['ok' => true, 'id' => $id]);
}

if ($action === 'memory_delete') {
  $id = (int)($body['id'] ?? 0);
  if ($id <= 0) stu_json(['ok' => false, 'error' => 'invalid_id'], 400);
  $st = $pdo->prepare('DELETE FROM ember_memories WHERE id = ?');
  $st->execute([$id]);
  coreui_admin_audit($pdo, $actorId, 'memory_delete', 'memory', (string)$id);
  stu_json(['ok' => true, 'deleted' => $st->rowCount() > 0]);
}

if ($action === 'settings_save') {
  $whitelist = [
    'registration_enabled', 'maintenance_enabled', 'maintenance_message',
    'memory_default_enabled', 'user_system_prompt_max_chars',
    'tool_web_enabled', 'tool_browse_enabled', 'tool_python_enabled',
    'external_api_enabled', 'external_api_label', 'external_api_base_url', 'external_api_model',
  ];
  $incoming = $body['settings'] ?? [];
  if (!is_array($incoming)) stu_json(['ok' => false, 'error' => 'invalid_settings'], 400);
  $saved = [];
  foreach ($whitelist as $key) {
    if (!array_key_exists($key, $incoming)) continue;
    $value = trim((string)$incoming[$key]);
    if (str_ends_with($key, '_enabled')) $value = in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true) ? '1' : '0';
    if ($key === 'user_system_prompt_max_chars') $value = (string)max(500, min(12000, (int)$value));
    if ($key === 'maintenance_message') {
      if (function_exists('mb_substr')) $value = mb_substr($value, 0, 520, 'UTF-8');
      else $value = substr($value, 0, 520);
    }
    if ($key === 'external_api_base_url' && $value !== '') {
      $valid = filter_var($value, FILTER_VALIDATE_URL);
      if (!$valid || strtolower((string)parse_url($value, PHP_URL_SCHEME)) !== 'https') {
        stu_json(['ok' => false, 'error' => 'external_url_requires_https'], 400);
      }
      $value = rtrim($value, '/');
    }
    if (in_array($key, ['external_api_label', 'external_api_model'], true)) {
      if (function_exists('mb_substr')) $value = mb_substr($value, 0, 160, 'UTF-8');
      else $value = substr($value, 0, 160);
    }
    stu_app_setting_set($pdo, $key, $value);
    $saved[$key] = $value;
  }
  coreui_admin_audit($pdo, $actorId, 'settings_save', 'app_settings', '', ['keys' => array_keys($saved)]);
  stu_json(['ok' => true, 'settings' => coreui_admin_setting_rows($pdo)]);
}

if ($action === 'logs_clear') {
  $file = coreui_admin_log_path();
  $removed = count(coreui_admin_tail($file, 2000));
  if (is_file($file) && file_put_contents($file, '', LOCK_EX) === false) {
    stu_json(['ok' => false, 'error' => 'log_clear_failed'], 500);
  }
  coreui_admin_audit($pdo, $actorId, 'logs_clear', 'log', 'stu_error.log', ['visible_lines' => $removed]);
  stu_json(['ok' => true, 'removed' => $removed]);
}

stu_json(['ok' => false, 'error' => 'unknown_action'], 404);
