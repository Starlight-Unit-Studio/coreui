<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

$pdo = stu_pdo();
$uid = stu_require_user_id();
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if (!coreui_auth_session_schema_ready($pdo)) {
  stu_json(['ok'=>false, 'error'=>'missing_schema_006'], 503);
}

function coreui_security_rate_limit(string $bucket, int $max, int $window): void {
  stu_start_session();
  $key = 'coreui_security_rl_' . preg_replace('~[^a-z0-9_-]~i', '_', $bucket);
  $now = time();
  $row = $_SESSION[$key] ?? ['time'=>$now, 'count'=>0];
  if (!is_array($row) || ($now - (int)($row['time'] ?? 0)) > $window) {
    $row = ['time'=>$now, 'count'=>0];
  }
  $row['count'] = (int)($row['count'] ?? 0) + 1;
  $_SESSION[$key] = $row;
  if ($row['count'] > $max) stu_json(['ok'=>false, 'error'=>'rate_limited'], 429);
}

function coreui_security_audit(PDO $pdo, int $uid, string $action, array $detail = []): void {
  try {
    $json = $detail === [] ? null : json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $st = $pdo->prepare(
      'INSERT INTO stu_admin_audit (actor_user_id,action_name,target_type,target_id,detail_json,created_at) '
      . "VALUES (?,?,'account',?,?,NOW())"
    );
    $st->execute([$uid, $action, (string)$uid, $json]);
  } catch (Throwable $e) {}
}

if ($method === 'GET') {
  $st = $pdo->prepare('SELECT username,password_changed_at,last_login_at,created_at FROM stu_users WHERE id=? LIMIT 1');
  $st->execute([$uid]);
  $account = $st->fetch(PDO::FETCH_ASSOC) ?: [];
  stu_json([
    'ok'=>true,
    'account'=>[
      'email'=>(string)($account['username'] ?? ''),
      'created_at'=>$account['created_at'] ?? null,
      'password_changed_at'=>$account['password_changed_at'] ?? null,
      'last_login_at'=>$account['last_login_at'] ?? null,
    ],
    'sessions'=>coreui_auth_session_list($pdo, $uid),
    'csrf_token'=>stu_csrf_token(),
  ]);
}

if ($method !== 'POST') stu_json(['ok'=>false, 'error'=>'method_not_allowed'], 405);
stu_require_csrf();
$body = stu_read_json_body();
$action = strtolower(trim((string)($body['action'] ?? '')));

if ($action === 'password_change') {
  coreui_security_rate_limit('password_change', 5, 900);
  $current = (string)($body['current_password'] ?? '');
  $next = (string)($body['new_password'] ?? '');
  $confirm = (string)($body['confirm_password'] ?? '');
  if (strlen($current) < 1 || strlen($current) > 1024) stu_json(['ok'=>false, 'error'=>'invalid_current_password'], 400);
  if (strlen($next) < 12 || strlen($next) > 1024) stu_json(['ok'=>false, 'error'=>'invalid_new_password'], 400);
  if (!hash_equals($next, $confirm)) stu_json(['ok'=>false, 'error'=>'password_confirmation_mismatch'], 400);

  $pdo->beginTransaction();
  try {
    $st = $pdo->prepare('SELECT password_hash FROM stu_users WHERE id=? AND is_guest=0 LIMIT 1 FOR UPDATE');
    $st->execute([$uid]);
    $oldHash = (string)($st->fetchColumn() ?: '');
    if ($oldHash === '' || !password_verify($current, $oldHash)) {
      $pdo->rollBack();
      stu_json(['ok'=>false, 'error'=>'invalid_current_password'], 403);
    }
    if (password_verify($next, $oldHash)) {
      $pdo->rollBack();
      stu_json(['ok'=>false, 'error'=>'password_unchanged'], 400);
    }
    $newHash = password_hash($next, PASSWORD_DEFAULT);
    if (!is_string($newHash) || $newHash === '') throw new RuntimeException('hash_failed');
    $pdo->prepare('UPDATE stu_users SET password_hash=?,password_changed_at=NOW() WHERE id=? LIMIT 1')
      ->execute([$newHash, $uid]);
    coreui_auth_session_revoke_all($pdo, $uid, 'password_changed');
    $pdo->commit();
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($e instanceof RuntimeException && $e->getMessage() === 'hash_failed') {
      stu_json(['ok'=>false, 'error'=>'hash_failed'], 500);
    }
    throw $e;
  }

  session_regenerate_id(true);
  try {
    coreui_auth_session_issue($pdo, $uid);
  } catch (Throwable $sessionError) {
    // Das Passwort wurde bereits sicher geaendert und alle alten Tokens sind
    // widerrufen. Ohne neues SQL-Token muss sich der Benutzer neu anmelden.
    stu_logout(false);
    stu_json(['ok'=>false, 'error'=>'auth_session_failed', 'reauth_required'=>true], 503);
  }
  coreui_security_audit($pdo, $uid, 'account.password_changed');
  stu_json([
    'ok'=>true,
    'sessions'=>coreui_auth_session_list($pdo, $uid),
    'csrf_token'=>stu_csrf_token(),
  ]);
}

if ($action === 'session_revoke') {
  coreui_security_rate_limit('session_revoke', 30, 300);
  $sessionId = (int)($body['session_id'] ?? 0);
  $currentId = (int)($_SESSION['coreui_auth_session_id'] ?? 0);
  if ($sessionId <= 0) stu_json(['ok'=>false, 'error'=>'invalid_session_id'], 400);
  if ($sessionId === $currentId) stu_json(['ok'=>false, 'error'=>'cannot_revoke_current'], 409);
  $deleted = coreui_auth_session_revoke($pdo, $uid, $sessionId, 'user_revoked');
  coreui_security_audit($pdo, $uid, 'account.session_revoked', ['session_id'=>$sessionId]);
  stu_json(['ok'=>true, 'revoked'=>$deleted, 'sessions'=>coreui_auth_session_list($pdo, $uid)]);
}

if ($action === 'sessions_revoke_others') {
  coreui_security_rate_limit('session_revoke_others', 10, 300);
  $count = coreui_auth_session_revoke_others($pdo, $uid);
  coreui_security_audit($pdo, $uid, 'account.sessions_revoked_others', ['count'=>$count]);
  stu_json(['ok'=>true, 'revoked_count'=>$count, 'sessions'=>coreui_auth_session_list($pdo, $uid)]);
}

stu_json(['ok'=>false, 'error'=>'unknown_action'], 400);
