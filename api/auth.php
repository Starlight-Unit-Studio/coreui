<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/account_store.php';
require_once __DIR__ . '/profile_store.php';

// Simple username/password auth for Alpha.
// - Session cookie stores stu_uid when logged in.

$action = $_GET['action'] ?? 'me';

function stu_rate_limit_key(): string {
  $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
  return 'rl_' . preg_replace('/[^0-9a-f:.]/i', '_', $ip);
}

function stu_rate_limit(string $bucket, int $max, int $windowSec): void {
  stu_start_session();
  $k = stu_rate_limit_key() . '_' . $bucket;
  $now = time();
  $row = $_SESSION[$k] ?? ['t' => $now, 'n' => 0];
  if (!is_array($row) || !isset($row['t'], $row['n'])) $row = ['t' => $now, 'n' => 0];
  if (($now - (int)$row['t']) > $windowSec) {
    $row = ['t' => $now, 'n' => 0];
  }
  $row['n'] = (int)$row['n'] + 1;
  $_SESSION[$k] = $row;
  if ($row['n'] > $max) stu_json(['ok' => false, 'error' => 'rate_limited'], 429);
}

function stu_clean_username(string $u): string {
  return coreui_account_clean_email($u);
}

function stu_valid_username(string $u): bool {
  return coreui_account_valid_email($u);
}

if ($action === 'me') {
  $uid = stu_get_user_id();
  if ($uid) {
    // Session may outlive DB rows (DB reset/migration). Verify user exists.
    try {
      $pdo = stu_pdo();
      stu_enforce_account_access($pdo, (int)$uid);
      $st = $pdo->prepare('SELECT id, username, permission_level FROM stu_users WHERE id = ? LIMIT 1');
      $st->execute([$uid]);
      $urow = $st->fetch();
      if ($urow) {
        coreui_account_ensure($pdo, (int)$uid, (string)($urow['username'] ?? ''));
        $lvl = isset($urow['permission_level']) ? (int)$urow['permission_level'] : 4;
        $_SESSION['stu_perm'] = $lvl;
        $maintenance = stu_maintenance_status($pdo);
        if (!empty($maintenance['enabled']) && !stu_is_staff_level($lvl)) {
          // Keep HTTP 200 here so the login page can stay interactive for Staff users.
          stu_json(stu_maintenance_payload($pdo), 200);
        }
        $profile = null;
        try {
          if (coreui_profile_schema_ready($pdo)) $profile = coreui_profile_load($pdo, (int)$uid);
        } catch (Throwable $e) {}
        stu_json([
          'ok' => true,
          'user_id' => $uid,
          'username' => $urow['username'] ?? null,
          'display_name' => is_array($profile) ? ($profile['display_name'] ?? null) : null,
          'assistant_name' => is_array($profile) ? ($profile['assistant_name'] ?? 'Ember') : 'Ember',
          'avatars' => is_array($profile) ? ($profile['avatars'] ?? ['user' => null, 'assistant' => null]) : ['user' => null, 'assistant' => null],
          'permission_level' => $lvl,
          'permission_label' => stu_permission_label($lvl),
          'csrf_token' => stu_csrf_token(),
        ]);
      }
    } catch (Throwable $e) {
      // DB unavailable -> treat as not authenticated
    }
    // Invalid/stale session: force logout
    stu_logout();
  }
  stu_json(['ok' => false, 'error' => 'not_authenticated'], 200);
}

if ($action === 'logout') {
  // POST preferred but tolerate GET in alpha tools.
  stu_logout();
  stu_json(['ok' => true]);
}

if ($action === 'register' || $action === 'login') {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    stu_json(['ok' => false, 'error' => 'method_not_allowed'], 405);
  }

  // Rate limit per IP bucket
  stu_rate_limit($action, 10, 60);

  $body = stu_read_json_body();
  $username = stu_clean_username((string)($body['username'] ?? ''));
  $password = (string)($body['password'] ?? '');
  $displayName = coreui_account_clean_display_name(
    (string)($body['display_name'] ?? ''),
    coreui_account_default_display_name($username)
  );

  if (!stu_valid_username($username)) {
    stu_json(['ok' => false, 'error' => 'invalid_username'], 400);
  }
  if (strlen($password) < 8) {
    stu_json(['ok' => false, 'error' => 'invalid_password'], 400);
  }

  $pdo = stu_pdo();
  if (!coreui_auth_session_schema_ready($pdo)) {
    stu_json(['ok' => false, 'error' => 'missing_schema_006'], 503);
  }

  if ($action === 'register') {
    $registrationEnabled = defined('STU_ALLOW_REGISTRATION') && STU_ALLOW_REGISTRATION;
    try {
      $registrationEnabled = stu_app_setting_get(
        $pdo,
        'registration_enabled',
        $registrationEnabled ? '1' : '0'
      ) === '1';
    } catch (Throwable $e) {}
    if (!$registrationEnabled) {
      stu_json(['ok' => false, 'error' => 'registration_disabled'], 403);
    }
    // During maintenance, only existing Staff accounts may pass. New accounts are normal users.
    stu_enforce_maintenance($pdo, null, 4);
    // Ensure columns exist (graceful message if schema not migrated)
    try {
      $pdo->query("SELECT username FROM stu_users LIMIT 1");
    } catch (Throwable $e) {
      stu_json(['ok' => false, 'error' => 'schema_missing_accounts'], 500);
    }

    try {
      $created = coreui_account_create($pdo, $username, $password, $displayName, 4);
      $uid = (int)$created['user_id'];
    } catch (RuntimeException $e) {
      if ($e->getMessage() === 'username_taken') stu_json(['ok' => false, 'error' => 'username_taken'], 409);
      if ($e->getMessage() === 'hash_failed') stu_json(['ok' => false, 'error' => 'hash_failed'], 500);
      if ($e->getMessage() === 'missing_schema_004') stu_json(['ok' => false, 'error' => 'missing_schema_004'], 503);
      if (function_exists('stu__log_error')) stu__log_error(['type' => 'registration_failed', 'message' => $e->getMessage()]);
      stu_json(['ok' => false, 'error' => 'registration_failed'], 500);
    } catch (Throwable $e) {
      if (function_exists('stu__log_error')) stu__log_error(['type' => 'registration_failed', 'message' => $e->getMessage()]);
      stu_json(['ok' => false, 'error' => 'registration_failed'], 500);
    }

    session_regenerate_id(true);
    stu_set_user_id($uid);
    $_SESSION['stu_perm'] = 4;
    try {
      $pdo->prepare('UPDATE stu_users SET password_changed_at=NOW(), last_login_at=NOW() WHERE id=?')->execute([$uid]);
      if (coreui_auth_session_schema_ready($pdo)) coreui_auth_session_issue($pdo, $uid);
    } catch (Throwable $e) {
      stu_logout(false);
      stu_json(['ok' => false, 'error' => 'auth_session_failed'], 500);
    }
    stu_json(['ok' => true, 'user_id' => $uid, 'permission_level' => 4]);
  }

  // login
// Fetch permission_level if migrated; fall back gracefully on older schema.
try {
  $stmt = $pdo->prepare('SELECT id, password_hash, permission_level, banned_until, banned_reason FROM stu_users WHERE username = ? AND is_guest = 0 LIMIT 1');
  $stmt->execute([$username]);
  $row = $stmt->fetch();
} catch (Throwable $e) {
  $stmt = $pdo->prepare('SELECT id, password_hash FROM stu_users WHERE username = ? AND is_guest = 0 LIMIT 1');
  $stmt->execute([$username]);
  $row = $stmt->fetch();
  if (is_array($row) && !isset($row['permission_level'])) $row['permission_level'] = 4;
  if (is_array($row) && !isset($row['banned_until'])) $row['banned_until'] = null;
  if (is_array($row) && !isset($row['banned_reason'])) $row['banned_reason'] = '';
}

  if (!$row || empty($row['password_hash'])) {
    stu_json(['ok' => false, 'error' => 'invalid_credentials'], 401);
  }
  if (!password_verify($password, (string)$row['password_hash'])) {
    stu_json(['ok' => false, 'error' => 'invalid_credentials'], 401);
  }
  // Ban check (optional schema extension)
  if (isset($row['banned_until']) && !empty($row['banned_until'])) {
    $ts = strtotime((string)$row['banned_until']);
    if ($ts !== false && $ts > time()) {
      $reason = isset($row['banned_reason']) ? (string)$row['banned_reason'] : '';
      stu_json(['ok'=>false,'error'=>'banned','until'=>$row['banned_until'],'reason'=>$reason], 403);
    }
  }


  $lvl = isset($row['permission_level']) ? (int)$row['permission_level'] : 4;
  stu_enforce_maintenance($pdo, (int)$row['id'], $lvl);
  try {
    coreui_account_ensure($pdo, (int)$row['id'], $username);
  } catch (Throwable $e) {
    if (function_exists('stu__log_error')) {
      stu__log_error(['type' => 'account_provision_failed', 'uid' => (int)$row['id'], 'message' => $e->getMessage()]);
    }
    stu_json(['ok' => false, 'error' => $e->getMessage() === 'missing_schema_004' ? 'missing_schema_004' : 'account_provision_failed'], 503);
  }

  // Rehash on login if algo changed
  if (password_needs_rehash((string)$row['password_hash'], PASSWORD_DEFAULT)) {
    $newHash = password_hash($password, PASSWORD_DEFAULT);
    if ($newHash) {
      $up = $pdo->prepare('UPDATE stu_users SET password_hash = ? WHERE id = ? LIMIT 1');
      $up->execute([$newHash, (int)$row['id']]);
    }
  }

  session_regenerate_id(true);
  stu_set_user_id((int)$row['id']);
  $_SESSION['stu_perm'] = $lvl;
  try {
    $pdo->prepare('UPDATE stu_users SET last_login_at=NOW() WHERE id=?')->execute([(int)$row['id']]);
    if (coreui_auth_session_schema_ready($pdo)) coreui_auth_session_issue($pdo, (int)$row['id']);
  } catch (Throwable $e) {
    stu_logout(false);
    stu_json(['ok' => false, 'error' => 'auth_session_failed'], 500);
  }
  stu_json(['ok' => true, 'user_id' => (int)$row['id'], 'permission_level' => $lvl]);
}

if ($action === 'forgot') {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    stu_json(['ok' => false, 'error' => 'method_not_allowed'], 405);
  }

  // Nicht verraten, ob ein Konto existiert (anti-account-enumeration).
  $body = stu_read_json_body();
  $email = strtolower(trim($body['email'] ?? ''));

  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    stu_json(['ok' => false, 'error' => 'invalid_email']);
  }

  $debugMail = (defined('STU_MAIL_DEBUG') && STU_MAIL_DEBUG);
  if (!(defined('STU_MAIL_ENABLED') && STU_MAIL_ENABLED)) {
    // In Produktion trotzdem ok=true zurückgeben.
    if ($debugMail) stu_json(['ok' => false, 'error' => 'mail_disabled']);
    stu_json(['ok' => true]);
  }

  $pdo = db();

  // In diesem Projekt ist "username" die Email.
  $stmt = $pdo->prepare('SELECT id FROM stu_users WHERE username = ? LIMIT 1');
  $stmt->execute([$email]);
  $uid = $stmt->fetchColumn();

  if (!$uid) {
    stu_json(['ok' => true]);
  }

  // Schema-Check (damit es nicht die Seite zerlegt, wenn Migrations fehlen)
  try {
    $pdo->query('SELECT 1 FROM stu_password_resets LIMIT 1');
  } catch (Throwable $e) {
    if ($debugMail) stu_json(['ok' => false, 'error' => 'missing_schema_password_resets']);
    stu_json(['ok' => true]);
  }

  // --- Reset-Code (App-only friendly)
  // Generates a short code (6 digits) instead of sending a clickable URL.
  // DB requirements: stu_password_resets.token_hash must be NULLABLE + column code_hash must exist.

  // Best-effort check if code_hash column exists (so we don't fatal on older schema)
  $hasCode = false;
  try {
    $st = $pdo->query("SHOW COLUMNS FROM stu_password_resets LIKE 'code_hash'");
    $hasCode = (bool)$st->fetch();
  } catch (Throwable $e) { $hasCode = false; }

  if (!$hasCode) {
    // If migrations are not yet applied, we return ok=true (no enumeration),
    // but in debug mode we reveal what is missing.
    if ($debugMail) stu_json(['ok' => false, 'error' => 'missing_schema_reset_code', 'hint' => 'run migrations/06_stu_mysql_schema_password_reset_codes.sql']);
    stu_json(['ok' => true]);
  }

  // Light rate-limit per account: do not send more than once per 60s.
  try {
    $st = $pdo->prepare('SELECT created_at FROM stu_password_resets WHERE user_id = ? ORDER BY created_at DESC LIMIT 1');
    $st->execute([(int)$uid]);
    $last = $st->fetchColumn();
    if ($last && (time() - strtotime((string)$last)) < 60) {
      stu_json(['ok' => true]);
    }
  } catch (Throwable $e) {
    // ignore
  }

  $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
  $codeHash = password_hash($code, PASSWORD_DEFAULT);
  if (!$codeHash) {
    if ($debugMail) stu_json(['ok' => false, 'error' => 'hash_failed']);
    stu_json(['ok' => true]);
  }

  $expires = date('Y-m-d H:i:s', time() + 15 * 60); // 15 min

  // Keep only one active reset per user.
  $pdo->prepare('DELETE FROM stu_password_resets WHERE user_id = ?')->execute([(int)$uid]);
  $ip = $_SERVER['REMOTE_ADDR'] ?? null;
  $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

  $pdo->prepare('INSERT INTO stu_password_resets (user_id, token_hash, code_hash, expires_at, created_at, used_at, attempts, ip, user_agent, last_sent_at) VALUES (?,?,?,?,NOW(),NULL,0,?,?,NOW())')
      ->execute([(int)$uid, null, $codeHash, $expires, $ip, $ua]);

  require_once __DIR__ . '/mailer.php';
  try {
    // Prefer a configured public base URL to avoid Host header injection.
    // Fallback to the canonical production domain if not configured.
    $base = (defined('STU_PUBLIC_BASE_URL') && STU_PUBLIC_BASE_URL) ? rtrim(STU_PUBLIC_BASE_URL, '/') : '';
    $resetUrl = $base . '/reset_password.html';
    stu_send_mail(
      $email,
      'Starlight Unit – Reset-Code',
      "Hallo!\n\n" .
      "Du hast einen Reset-Code für dein Starlight Unit Passwort angefordert.\n\n" .
      "Dein Code: " . $code . "\n" .
      "Gültig für 15 Minuten.\n\n" .
      "Passwort zurücksetzen: " . $resetUrl . "\n" .
      "(Dort E-Mail + Code eingeben und neues Passwort setzen.)\n\n" .
      "Wichtig: Gib den Code in der App oder auf der Reset-Seite ein.\n" .
      "Wenn du das nicht warst, kannst du diese Mail ignorieren.\n"
    );
  } catch (Throwable $e) {
    if ($debugMail) stu_json(['ok' => false, 'error' => 'email_send_failed', 'detail' => $e->getMessage()]);
    stu_json(['ok' => true]);
  }

  stu_json(['ok' => true]);
}

if ($action === 'reset') {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    stu_json(['ok' => false, 'error' => 'method_not_allowed'], 405);
  }

  stu_rate_limit($action, 10, 300);

  $body = stu_read_json_body();
  $token = (string)($body['token'] ?? ''); // legacy link-token flow
  $email = strtolower(trim((string)($body['email'] ?? '')));
  $code  = trim((string)($body['code'] ?? ''));
  $newPass = (string)($body['new_password'] ?? '');

  if (strlen($newPass) < 8) stu_json(['ok' => false, 'error' => 'invalid_password'], 400);

  $pdo = stu_pdo();

  // --- Preferred flow: email + 6-digit reset code
  if ($email && $code) {
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) stu_json(['ok' => false, 'error' => 'invalid_email'], 400);
    if (!preg_match('/^[0-9]{6}$/', $code)) stu_json(['ok' => false, 'error' => 'invalid_code'], 400);

    $stmt = $pdo->prepare('SELECT id FROM stu_users WHERE username = ? LIMIT 1');
    $stmt->execute([$email]);
    $uid = $stmt->fetchColumn();
    if (!$uid) stu_json(['ok' => false, 'error' => 'invalid_code'], 400);

    // Ensure schema has code_hash
    $hasCode = false;
    try {
      $st = $pdo->query("SHOW COLUMNS FROM stu_password_resets LIKE 'code_hash'");
      $hasCode = (bool)$st->fetch();
    } catch (Throwable $e) { $hasCode = false; }
    if (!$hasCode) stu_json(['ok' => false, 'error' => 'missing_schema_reset_code'], 500);

    $stmt = $pdo->prepare('SELECT id, code_hash, expires_at, used_at, attempts FROM stu_password_resets WHERE user_id = ? ORDER BY created_at DESC LIMIT 1');
    $stmt->execute([(int)$uid]);
    $row = $stmt->fetch();
    if (!$row) stu_json(['ok' => false, 'error' => 'invalid_code'], 400);
    if (!empty($row['used_at'])) stu_json(['ok' => false, 'error' => 'code_used'], 400);
    if (strtotime((string)$row['expires_at']) < time()) stu_json(['ok' => false, 'error' => 'code_expired'], 400);
    if ((int)($row['attempts'] ?? 0) >= 5) stu_json(['ok' => false, 'error' => 'too_many_attempts'], 429);

    $ok = false;
    try { $ok = password_verify($code, (string)($row['code_hash'] ?? '')); } catch (Throwable $e) { $ok = false; }
    if (!$ok) {
      $pdo->prepare('UPDATE stu_password_resets SET attempts = attempts + 1 WHERE id = ? LIMIT 1')->execute([(int)$row['id']]);
      stu_json(['ok' => false, 'error' => 'invalid_code'], 400);
    }

    $hash = password_hash($newPass, PASSWORD_DEFAULT);
    if (!$hash) stu_json(['ok' => false, 'error' => 'hash_failed'], 500);

    try {
      $pdo->prepare('UPDATE stu_users SET password_hash = ?, password_changed_at=NOW() WHERE id = ? LIMIT 1')->execute([$hash, (int)$uid]);
    } catch (Throwable $e) {
      $pdo->prepare('UPDATE stu_users SET password_hash = ? WHERE id = ? LIMIT 1')->execute([$hash, (int)$uid]);
    }
    $pdo->prepare('UPDATE stu_password_resets SET used_at = NOW() WHERE id = ? LIMIT 1')->execute([(int)$row['id']]);
    coreui_auth_session_revoke_all($pdo, (int)$uid, 'password_reset');
    stu_json(['ok' => true]);
  }

  // --- Legacy fallback: token link (kept so old mails don't break)
  if (strlen($token) < 20) stu_json(['ok' => false, 'error' => 'invalid_token'], 400);

  try {
    $tokenHash = hash('sha256', $token);
    $stmt = $pdo->prepare('SELECT id, user_id, expires_at, used_at FROM stu_password_resets WHERE token_hash = ? LIMIT 1');
    $stmt->execute([$tokenHash]);
    $row = $stmt->fetch();
  } catch (Throwable $e) {
    stu_json(['ok' => false, 'error' => 'invalid_token'], 400);
  }

  if (!$row) stu_json(['ok' => false, 'error' => 'invalid_token'], 400);
  if (!empty($row['used_at'])) stu_json(['ok' => false, 'error' => 'token_used'], 400);
  if (strtotime((string)$row['expires_at']) < time()) stu_json(['ok' => false, 'error' => 'token_expired'], 400);

  $hash = password_hash($newPass, PASSWORD_DEFAULT);
  if (!$hash) stu_json(['ok' => false, 'error' => 'hash_failed'], 500);

  try {
    $pdo->prepare('UPDATE stu_users SET password_hash = ?, password_changed_at=NOW() WHERE id = ? LIMIT 1')->execute([$hash, (int)$row['user_id']]);
  } catch (Throwable $e) {
    $pdo->prepare('UPDATE stu_users SET password_hash = ? WHERE id = ? LIMIT 1')->execute([$hash, (int)$row['user_id']]);
  }
  $pdo->prepare('UPDATE stu_password_resets SET used_at = NOW() WHERE id = ? LIMIT 1')->execute([(int)$row['id']]);
  coreui_auth_session_revoke_all($pdo, (int)$row['user_id'], 'password_reset');

  stu_json(['ok' => true]);
}

stu_json(['ok' => false, 'error' => 'unknown_action'], 400);
