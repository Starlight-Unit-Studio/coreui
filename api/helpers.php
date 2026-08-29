<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth_session_store.php';

// --- API crash shielding (returns JSON instead of a blank HTTP 500 page) ---
// Enable verbose error payloads by defining STU_DEBUG=true in config.local.php or env STU_DEBUG=1
if (!defined('STU_DEBUG')) {
  $dbg = getenv('STU_DEBUG');
  define('STU_DEBUG', ($dbg === '1' || strtolower((string)$dbg) === 'true'));
}


if (!function_exists('stu__log_error')) {
  function stu__log_error(array $entry): void {
    $base = dirname(__DIR__); // project root
    $dir = $base . '/logs';
    $file = $dir . '/stu_error.log';
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    $entry['ts'] = $entry['ts'] ?? date('c');
    $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($line === false) { $line = '{"ts":"' . date('c') . '","type":"log_encode_failed"}'; }
    @file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
  }
}

if (!function_exists('stu__safe_json')) {
  function stu__safe_json(array $payload, int $status = 500): void {
    if (!headers_sent()) {
      http_response_code($status);
      header('Content-Type: application/json; charset=utf-8');
      header('Cache-Control: no-store');
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  }
}

// Convert notices/warnings into exceptions (so we can surface them as JSON)
set_error_handler(function($severity, $message, $file, $line) {
  if (!(error_reporting() & $severity)) return false;
  throw new ErrorException($message, 0, $severity, $file, $line);
});

// Any uncaught exception => JSON
set_exception_handler(function ($e) {
  stu__log_error([
    'type'=>'exception',
    'message'=>$e instanceof Throwable ? $e->getMessage() : (string)$e,
    'file'=>$e instanceof Throwable ? $e->getFile() : '',
    'line'=>$e instanceof Throwable ? $e->getLine() : 0,
  ]);
$payload = ['ok' => false, 'error' => 'server_error'];
  if (STU_DEBUG) {
    $payload['type'] = is_object($e) ? get_class($e) : 'unknown';
    $payload['message'] = is_object($e) && method_exists($e, 'getMessage') ? $e->getMessage() : '';
    $payload['file'] = is_object($e) && method_exists($e, 'getFile') ? $e->getFile() : '';
    $payload['line'] = is_object($e) && method_exists($e, 'getLine') ? $e->getLine() : 0;
  }
  stu__safe_json($payload, 500);
  exit;
});

// Fatal errors => JSON (only triggers if nothing else was printed)
register_shutdown_function(function() {
  $err = error_get_last();
  if (!$err) return;

  $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
  if (!in_array($err['type'] ?? 0, $fatalTypes, true)) return;

  // If a script already emitted output, don't corrupt it.
  if (headers_sent()) return;

  $payload = ['ok' => false, 'error' => 'php_fatal'];
  if (STU_DEBUG) {
    $payload['message'] = (string)($err['message'] ?? '');
    $payload['file'] = (string)($err['file'] ?? '');
    $payload['line'] = (int)($err['line'] ?? 0);
    $payload['type'] = (int)($err['type'] ?? 0);
  }
  stu__safe_json($payload, 500);
});

function stu_json($data, int $status = 200): void {
  http_response_code($status);
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store');
  echo json_encode($data, JSON_UNESCAPED_UNICODE);
  exit;
}

function stu_read_json_body(): array {
  // Accept JSON *and* classic form POSTs.
  // Some hardened Apache/WAF setups can interfere with JSON bodies; this keeps endpoints robust.
  $ct = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''));
  $raw = file_get_contents('php://input') ?: '';

  // 1) JSON body
  if (str_contains($ct, 'application/json')) {
    $obj = json_decode($raw, true);
    if (is_array($obj)) return $obj;
  }

  // 2) Standard form post
  if (!empty($_POST) && is_array($_POST)) {
    return $_POST;
  }

  // 3) Fallback: parse raw as query string
  $out = [];
  if (is_string($raw) && $raw !== '') {
    parse_str($raw, $out);
    if (is_array($out) && !empty($out)) return $out;
  }

  return [];
}

function stu_public_path(string $relative): string {
  $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/api/index.php'));
  $projectPath = rtrim(str_replace('\\', '/', dirname(dirname($script))), '/.');
  return ($projectPath !== '' ? $projectPath : '') . '/' . ltrim($relative, '/');
}

function stu_start_session(): void {
  if (session_status() === PHP_SESSION_ACTIVE) return;

  if (!headers_sent() && defined('STU_SESSION_NAME') && STU_SESSION_NAME !== '') {
    session_name((string)STU_SESSION_NAME);
  }

  $cookiePath = defined('STU_SESSION_COOKIE_PATH') ? trim((string)STU_SESSION_COOKIE_PATH) : '';
  if ($cookiePath === '') {
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/api/auth.php'));
    $projectPath = rtrim(str_replace('\\', '/', dirname(dirname($script))), '/.');
    $cookiePath = ($projectPath === '') ? '/' : ($projectPath . '/');
  }

  $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);
  if (PHP_VERSION_ID >= 70300) {
    session_set_cookie_params([
      'lifetime' => 0,
      'path' => $cookiePath,
      'secure' => $isHttps,
      'httponly' => true,
      'samesite' => 'Lax',
    ]);
  } else {
    // Legacy fallback
    ini_set('session.cookie_httponly', '1');
    if ($isHttps) ini_set('session.cookie_secure', '1');
  }

  session_start();
}

function stu_csrf_token(): string {
  stu_start_session();
  $token = $_SESSION['stu_csrf'] ?? '';
  if (!is_string($token) || strlen($token) < 32) {
    $token = bin2hex(random_bytes(24));
    $_SESSION['stu_csrf'] = $token;
  }
  return $token;
}

function stu_csrf_valid(?string $token): bool {
  if (!is_string($token) || $token === '') return false;
  return hash_equals(stu_csrf_token(), $token);
}

function stu_require_csrf(?string $token = null): void {
  if ($token === null) $token = (string)($_SERVER['HTTP_X_COREUI_CSRF'] ?? '');
  if (!stu_csrf_valid($token)) {
    stu_json(['ok' => false, 'error' => 'csrf_failed'], 403);
  }
}

function stu_get_user_id(): ?int {
  stu_start_session();
  $uid = $_SESSION['stu_uid'] ?? null;
  if (!is_int($uid) || $uid <= 0) return null;
  if (!empty($_SESSION['stu_logged_in']) && function_exists('coreui_auth_session_validate_current')) {
    try {
      if (!coreui_auth_session_validate_current(stu_pdo(), $uid)) {
        stu_logout(false);
        return null;
      }
    } catch (Throwable $e) {
      // Bei einem Datenbankausfall entscheidet der eigentliche Endpunkt ueber
      // die Fehlermeldung. Ein Ausfall darf nicht still alle Cookies loeschen.
    }
  }
  return $uid;
}

function stu_set_user_id(int $uid): void {
  stu_start_session();
  $_SESSION['stu_uid'] = $uid;
  $_SESSION['stu_logged_in'] = true;
}

function stu_get_permission_level(?PDO $pdo = null): int {
  stu_start_session();
  $cached = isset($_SESSION['stu_perm']) && is_int($_SESSION['stu_perm'])
    ? (int)$_SESSION['stu_perm']
    : 4;
  $uid = stu_get_user_id();
  if (!$uid) return 4;
  try {
    if (!$pdo) $pdo = stu_pdo();
    $st = $pdo->prepare('SELECT permission_level FROM stu_users WHERE id = ? LIMIT 1');
    $st->execute([$uid]);
    $lvl = $st->fetchColumn();
    $lvl = ($lvl === false || $lvl === null) ? 4 : (int)$lvl;
  } catch (Throwable $e) {
    return $cached;
  }
  $_SESSION['stu_perm'] = $lvl;
  return $lvl;
}

function stu_require_permission_at_most(int $maxLevel, ?PDO $pdo = null): int {
  $lvl = stu_get_permission_level($pdo);
  if ($lvl > $maxLevel) {
    stu_json(['ok'=>false,'error'=>'insufficient_permissions','required_at_most'=>$maxLevel,'your_level'=>$lvl], 403);
  }
  return $lvl;
}

function stu_permission_label(int $lvl): string {
  switch ($lvl) {
    case 0: return 'system_admin';
    case 1: return 'admin';
    case 2: return 'game_moderator';
    case 3: return 'chat_moderator';
    default: return 'user';
  }
}

function stu_is_logged_in(): bool {
  stu_start_session();
  return !empty($_SESSION['stu_logged_in']) && !!stu_get_user_id();
}

function stu_logout(bool $revokeSession = true): void {
  stu_start_session();
  $uid = isset($_SESSION['stu_uid']) && is_int($_SESSION['stu_uid'])
    ? (int)$_SESSION['stu_uid']
    : 0;
  if ($revokeSession && $uid > 0 && function_exists('coreui_auth_session_revoke_current')) {
    try {
      coreui_auth_session_revoke_current(stu_pdo(), $uid, 'logout');
    } catch (Throwable $e) {}
  }
  $_SESSION = [];
  if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'] ?? '/', $params['domain'] ?? '', $params['secure'] ?? false, $params['httponly'] ?? true);
  }
  session_destroy();
}

function stu_enforce_account_access(PDO $pdo, int $uid): void {
  if ($uid <= 0) stu_json(['ok' => false, 'error' => 'not_authenticated'], 401);
  $st = $pdo->prepare('SELECT id, banned_until, banned_reason FROM stu_users WHERE id = ? LIMIT 1');
  $st->execute([$uid]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!is_array($row)) {
    stu_logout();
    stu_json(['ok' => false, 'error' => 'not_authenticated'], 401);
  }
  $until = trim((string)($row['banned_until'] ?? ''));
  if ($until === '') return;
  $ts = strtotime($until);
  if ($ts === false || $ts <= time()) return;
  $reason = trim((string)($row['banned_reason'] ?? ''));
  stu_logout();
  stu_json([
    'ok' => false,
    'error' => 'banned',
    'until' => $until,
    'reason' => $reason,
  ], 403);
}

function stu_require_user_id(): int {
  $uid = stu_get_user_id();
  if (!$uid) stu_json(['ok' => false, 'error' => 'not_authenticated'], 401);
  try {
    $pdo = stu_pdo();
    stu_enforce_account_access($pdo, (int)$uid);
    stu_enforce_maintenance($pdo, (int)$uid);
  } catch (Throwable $e) {
    // Maintenance check must never create a blank page on hosts with partial migrations.
  }
  return $uid;
}

function stu_random_key(int $len = 32): string {
  return bin2hex(random_bytes((int)ceil($len / 2)));
}

function stu_bootstrap_user(?PDO $pdo = null): int {
  stu_start_session();
  $existing = stu_get_user_id();
  if ($existing) {
    // Stale session protection:
    // If you rebuilt/emptied the DB but the client still holds an old PHPSESSID cookie,
    // $_SESSION['stu_uid'] may point to a user id that no longer exists.
    // That would break writes to stu_kv due to the foreign key (user_id -> stu_users.id).
    try {
      if (!$pdo) $pdo = stu_pdo();
      $chk = $pdo->prepare('SELECT 1 FROM stu_users WHERE id = ?');
      $chk->execute([(int)$existing]);
      if ($chk->fetchColumn()) {
        stu_enforce_maintenance($pdo, (int)$existing);
        return (int)$existing;
      }
    } catch (Throwable $e) {
      // If DB is not reachable, keep old behavior (fail fast elsewhere).
      return (int)$existing;
    }

    // Session points to a missing user -> reset session and fall back to creating a new guest.
    unset($_SESSION['stu_uid']);
  }

      if (!$pdo) $pdo = stu_pdo();
  stu_enforce_maintenance($pdo, null, 4);
  $guestKey = stu_random_key(40);
  $stmt = $pdo->prepare('INSERT INTO stu_users (guest_key, created_at) VALUES (?, NOW())');
  $stmt->execute([$guestKey]);
  $uid = (int)$pdo->lastInsertId();
  $_SESSION['stu_uid'] = $uid;
  return $uid;
}

function stu_validate_key(string $key): void {
  // allow only keys we control (prevents arbitrary DB bloat)
  if (!preg_match('/^[a-z0-9_\-]{1,64}$/i', $key)) {
    stu_json(['ok' => false, 'error' => 'invalid_key'], 400);
  }
}

function stu_limit_value(string $value): void {
  if (strlen($value) > STU_MAX_VALUE_BYTES) {
    stu_json(['ok' => false, 'error' => 'value_too_large', 'max_bytes' => STU_MAX_VALUE_BYTES], 413);
  }
}

function stu_schema_has_column(PDO $pdo, string $table, string $column): bool {
  try {
    $st = $pdo->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
    $st->execute([$table, $column]);
    return (bool)$st->fetchColumn();
  } catch (Throwable $e) {
    try {
      $pdo->query('SELECT `'.str_replace('`', '``', $column).'` FROM `'.str_replace('`', '``', $table).'` LIMIT 1');
      return true;
    } catch (Throwable $e2) {
      return false;
    }
  }
}

function stu_try_add_column(PDO $pdo, string $table, string $column, string $ddl): bool {
  if (stu_schema_has_column($pdo, $table, $column)) return true;
  try {
    $pdo->exec($ddl);
  } catch (Throwable $e) {
    // Best effort only.
  }
  return stu_schema_has_column($pdo, $table, $column);
}


// -----------------------------
// Global maintenance mode
// -----------------------------

function stu_schema_has_table(PDO $pdo, string $table): bool {
  try {
    $st = $pdo->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1');
    $st->execute([$table]);
    return (bool)$st->fetchColumn();
  } catch (Throwable $e) {
    try {
      $pdo->query('SELECT 1 FROM `'.str_replace('`', '``', $table).'` LIMIT 1');
      return true;
    } catch (Throwable $e2) {
      return false;
    }
  }
}


function stu_app_settings_file_path(): string {
  $dir = __DIR__ . '/../logs';
  if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
  return $dir . '/stu_app_settings.json';
}

function stu_app_setting_file_get(string $key, ?string $default = null): ?string {
  $file = stu_app_settings_file_path();
  if (!is_file($file)) return $default;
  $raw = @file_get_contents($file);
  if (!is_string($raw) || trim($raw) === '') return $default;
  $data = json_decode($raw, true);
  if (!is_array($data) || !array_key_exists($key, $data)) return $default;
  $v = $data[$key];
  return is_scalar($v) || $v === null ? ($v === null ? $default : (string)$v) : $default;
}

function stu_app_setting_file_set(string $key, string $value): void {
  $file = stu_app_settings_file_path();
  $data = [];
  if (is_file($file)) {
    $raw = @file_get_contents($file);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    if (is_array($decoded)) $data = $decoded;
  }
  $data[$key] = $value;
  $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
  if (!is_string($json)) return;
  @file_put_contents($file, $json, LOCK_EX);
}

function stu_app_settings_install(PDO $pdo): void {
  static $done = false;
  if ($done) return;
  try {
    $pdo->exec(
      "CREATE TABLE IF NOT EXISTS stu_app_settings (" .
      "`k` VARCHAR(64) NOT NULL PRIMARY KEY," .
      "`value` TEXT NULL," .
      "`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP" .
      ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
  } catch (Throwable $e) {
    // Do not break the whole game if the host refuses DDL; callers fall back safely.
    stu__log_error(['type'=>'maintenance_settings_install_failed','message'=>$e->getMessage()]);
  }
  $done = true;
}

function stu_app_setting_get(PDO $pdo, string $key, ?string $default = null): ?string {
  try {
    stu_app_settings_install($pdo);
    $st = $pdo->prepare('SELECT `value` FROM stu_app_settings WHERE `k` = ? LIMIT 1');
    $st->execute([$key]);
    $v = $st->fetchColumn();
    if ($v === false || $v === null) {
      return stu_app_setting_file_get($key, $default);
    }
    return (string)$v;
  } catch (Throwable $e) {
    return stu_app_setting_file_get($key, $default);
  }
}

function stu_app_setting_set(PDO $pdo, string $key, string $value): void {
  try {
    stu_app_settings_install($pdo);
    $st = $pdo->prepare(
      'INSERT INTO stu_app_settings (`k`, `value`, `updated_at`) VALUES (?, ?, NOW()) ' .
      'ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `updated_at` = NOW()'
    );
    $st->execute([$key, $value]);
    return;
  } catch (Throwable $e) {
    stu__log_error(['type'=>'app_setting_db_write_failed','key'=>$key,'message'=>$e->getMessage()]);
    stu_app_setting_file_set($key, $value);
  }
}

function stu_is_staff_level(int $lvl): bool {
  // STU permission model: 0 system_admin, 1 admin, 2 game_moderator, 3 chat_moderator, 4 user.
  return $lvl <= 3;
}

function stu_default_maintenance_message(): string {
  return 'Der Server wird gerade gewartet. Bitte versuche es später erneut.';
}

function stu_maintenance_status(PDO $pdo): array {
  $enabled = stu_app_setting_get($pdo, 'maintenance_enabled', '0') === '1';
  $msg = trim((string)stu_app_setting_get($pdo, 'maintenance_message', stu_default_maintenance_message()));
  if ($msg === '') $msg = stu_default_maintenance_message();
  return [
    'enabled' => $enabled,
    'message' => $msg,
    'staff_bypass_level' => 3,
  ];
}

function stu_security_gate_status(PDO $pdo): array {
  // Security Gate controls the client-side app-only/browser gate.
  // Default is ON so existing production behaviour remains unchanged after deploy.
  $enabled = stu_app_setting_get($pdo, 'security_gate_enabled', '1') !== '0';
  return [
    'enabled' => $enabled,
  ];
}

function stu_maintenance_payload(PDO $pdo): array {
  $m = stu_maintenance_status($pdo);
  return [
    'ok' => false,
    'error' => 'maintenance_mode',
    'maintenance' => true,
    'enabled' => (bool)$m['enabled'],
    'message' => (string)$m['message'],
    'staff_bypass_level' => (int)$m['staff_bypass_level'],
  ];
}

function stu_permission_level_for_user(PDO $pdo, int $uid): int {
  if ($uid <= 0) return 4;
  try {
    $st = $pdo->prepare('SELECT permission_level FROM stu_users WHERE id = ? LIMIT 1');
    $st->execute([$uid]);
    $lvl = $st->fetchColumn();
    return ($lvl === false || $lvl === null) ? 4 : (int)$lvl;
  } catch (Throwable $e) {
    return 4;
  }
}

function stu_enforce_maintenance(PDO $pdo, ?int $uid = null, ?int $permissionLevel = null): void {
  $m = stu_maintenance_status($pdo);
  if (empty($m['enabled'])) return;
  $lvl = $permissionLevel;
  if ($lvl === null) $lvl = ($uid ? stu_permission_level_for_user($pdo, (int)$uid) : 4);
  if (stu_is_staff_level((int)$lvl)) return;
  stu_json(stu_maintenance_payload($pdo), 503);
}


// -----------------------------
// Public homepage tickers
// -----------------------------

function stu_site_ticker_targets(): array {
  return [
    'studio_site' => [
      'label' => 'Central Hub',
      'setting_key' => 'ticker_studio_site',
      'default' => 'ALLE SYSTEME NOMINAL · EPISODE 2 UND STU - THE GAME IN ENTWICKLUNG · ACCESS TIER: OFFEN · STARLIGHT UNIT UNIVERSE - AKTIV SEIT 2023 · KOORDINATEN: TERRA-CORE · 2163 · EMBER PROTOCOL: INITIALISIERT · CREW: 04 AKTIV · NÄCHSTES UPDATE FOLGT',
    ],
    'books_site' => [
      'label' => 'Buch-Seite',
      'setting_key' => 'ticker_books_site',
      'default' => 'STARLIGHT UNIT · EPISODE 1 "DER RING" · JETZT ERHÄLTLICH · ISBN: 978-94-0386313-9 · EBOOK & TASCHENBUCH · EPISODE 2 IN BEARBEITUNG · STARLIGHT UNIT · EPISODE 1 "DER RING" · JETZT ERHÄLTLICH',
    ],
    'games_site' => [
      'label' => 'Spielseite',
      'setting_key' => 'ticker_games_site',
      'default' => 'STΛRLIGHT UNIT THE GAME · ALPHA v{VERSION} · IN ENTWICKLUNG · TEXTBASIERTES SCI-FI MMO · KEINE WERBUNG · KEIN PAY-TO-WIN · MOBILE-FIRST · ANDROID · BETA FOLGT · STΛRLIGHT UNIT THE GAME · ALPHA v{VERSION} · IN ENTWICKLUNG',
    ],
  ];
}

function stu_site_ticker_normalize(string $text): string {
  $text = trim(preg_replace('/\s+/u', ' ', $text) ?: $text);
  if (function_exists('mb_strlen') && mb_strlen($text, 'UTF-8') > 520) {
    $text = mb_substr($text, 0, 520, 'UTF-8');
  } elseif (!function_exists('mb_strlen') && strlen($text) > 520) {
    $text = substr($text, 0, 520);
  }
  return trim($text);
}

function stu_site_tickers_get(PDO $pdo): array {
  $out = [];
  foreach (stu_site_ticker_targets() as $site => $meta) {
    $default = (string)($meta['default'] ?? '');
    $key = (string)($meta['setting_key'] ?? '');
    $text = stu_site_ticker_normalize((string)stu_app_setting_get($pdo, $key, $default));
    if ($text === '') $text = $default;
    $out[$site] = [
      'site' => $site,
      'label' => (string)($meta['label'] ?? $site),
      'text' => $text,
      'max_length' => 520,
    ];
  }
  return $out;
}

function stu_site_ticker_set(PDO $pdo, string $site, string $text): void {
  $targets = stu_site_ticker_targets();
  if (!isset($targets[$site])) {
    stu_json(['ok'=>false,'error'=>'invalid_site'], 400);
  }
  $text = stu_site_ticker_normalize($text);
  if ($text === '') $text = (string)$targets[$site]['default'];
  stu_app_setting_set($pdo, (string)$targets[$site]['setting_key'], $text);
}


// -----------------------------
// Public game changelog entries
// -----------------------------

function stu_kv_write(PDO $pdo, int $uid, string $key, string $value): void {
  stu_validate_key($key);
  stu_limit_value($value);

  $exists = $pdo->prepare('SELECT 1 FROM stu_kv WHERE user_id = ? AND k = ? LIMIT 1');
  $exists->execute([$uid, $key]);

  if ($exists->fetchColumn()) {
    $upd = $pdo->prepare('UPDATE stu_kv SET value = ?, updated_at = NOW() WHERE user_id = ? AND k = ?');
    $upd->execute([$value, $uid, $key]);
    return;
  }

  try {
    $ins = $pdo->prepare('INSERT INTO stu_kv (user_id, k, value, updated_at) VALUES (?, ?, ?, NOW())');
    $ins->execute([$uid, $key, $value]);
  } catch (Throwable $e) {
    $upd = $pdo->prepare('UPDATE stu_kv SET value = ?, updated_at = NOW() WHERE user_id = ? AND k = ?');
    $upd->execute([$value, $uid, $key]);
  }
}

// -----------------------------
// Account isolation helpers
// -----------------------------

function stu_user_owns_character(PDO $pdo, int $uid, string $cid): bool {
  if ($uid <= 0 || $cid === '') return false;
  try {
    $st = $pdo->prepare('SELECT 1 FROM stu_characters WHERE id = ? AND user_id = ? LIMIT 1');
    $st->execute([$cid, $uid]);
    return (bool)$st->fetchColumn();
  } catch (Throwable $e) {
    return false;
  }
}

function stu_sanitize_characters_kv(PDO $pdo, int $uid, ?string $raw): string {
  // Input: JSON array from KV "stu_characters".
  // Output: sanitized JSON that only contains characters owned by this user.
  if (!is_string($raw) || trim($raw) === '') return '[]';
  $arr = json_decode($raw, true);
  if (!is_array($arr)) return '[]';

  $out = [];
  foreach ($arr as $c) {
    if (!is_array($c)) continue;
    $cid = (string)($c['id'] ?? '');
    if ($cid === '') continue;
    if (!stu_user_owns_character($pdo, $uid, $cid)) continue;
    $out[] = $c;
  }

  // Cap to 5 chars (hard safety)
  if (count($out) > 5) $out = array_slice($out, 0, 5);
  return json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function stu_sanitize_active_character_kv(PDO $pdo, int $uid, ?string $raw, string $charsRawSanitized): ?string {
  // Active character must belong to user and exist in sanitized char list.
  if (!is_string($raw) || trim($raw) === '') return null;
  $cid = trim($raw);
  if ($cid === '') return null;
  if (!stu_user_owns_character($pdo, $uid, $cid)) return null;
  $arr = json_decode($charsRawSanitized ?: '[]', true);
  if (is_array($arr)) {
    foreach ($arr as $c) {
      if (is_array($c) && (string)($c['id'] ?? '') === $cid) return $cid;
    }
  }
  return null;
}

function stu_rebuild_kv_chars_from_table(PDO $pdo, int $uid): ?string {
  // Recovery: rebuild the stu_characters KV value from the stu_characters table.
  // Used when the KV store was lost/cleared but the table still has the user's characters.
  // Returns a JSON array string, or null on failure.
  if ($uid <= 0) return null;
  try {
    $pdo->query('SELECT 1 FROM stu_characters LIMIT 1');
  } catch (Throwable $e) {
    return null;
  }
  $hasPlanet = false;
  try {
    $stc = $pdo->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stu_characters' AND COLUMN_NAME = 'planet_name' LIMIT 1");
    $stc->execute();
    $hasPlanet = (bool)$stc->fetchColumn();
  } catch (Throwable $e) {}

  try {
    $cols = 'id, name, world_id, portrait_index, portrait_path' . ($hasPlanet ? ', planet_name' : '');
    $st = $pdo->prepare("SELECT $cols FROM stu_characters WHERE user_id = ? ORDER BY created_at ASC LIMIT 5");
    $st->execute([$uid]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return '[]';

    // Build a map of existing KV chars (id -> data) to preserve level/xp if available.
    $existingByIdMap = [];
    try {
      $stKv = $pdo->prepare('SELECT value FROM stu_kv WHERE user_id=? AND k=? LIMIT 1');
      $stKv->execute([$uid, 'stu_characters']);
      $kvRow = $stKv->fetch();
      if ($kvRow) {
        $kvArr = json_decode($kvRow['value'] ?? '[]', true);
        if (is_array($kvArr)) {
          foreach ($kvArr as $kvChar) {
            if (!empty($kvChar['id'])) {
              $existingByIdMap[(string)$kvChar['id']] = $kvChar;
            }
          }
        }
      }
    } catch (Throwable $e) {}

    $out = [];
    foreach ($rows as $r) {
      // Try to preserve existing KV data (level, xp, res etc.) if available.
      $existingLevel = 1;
      $existingLv    = 1;
      $existingXp    = 0;
      if (!empty($existingByIdMap[(string)($r['id'] ?? '')])) {
        $ex = $existingByIdMap[(string)($r['id'] ?? '')];
        $existingLevel = (int)($ex['level'] ?? $ex['lv'] ?? 1);
        if ($existingLevel < 1) $existingLevel = 1;
        $existingLv  = $existingLevel;
        $existingXp  = (int)($ex['xp'] ?? 0);
      }
      $c = [
        'id'            => (string)($r['id'] ?? ''),
        'name'          => (string)($r['name'] ?? ''),
        'worldId'       => (int)($r['world_id'] ?? 1),
        'portraitIndex' => (int)($r['portrait_index'] ?? 0),
        'portrait'      => (string)($r['portrait_path'] ?? ''),
        'level'         => $existingLevel,
        'lv'            => $existingLv,
        'xp'            => $existingXp,
      ];
      if ($hasPlanet) $c['planetName'] = (string)($r['planet_name'] ?? '');
      if ($c['id'] !== '' && $c['name'] !== '') $out[] = $c;
    }
    return json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  } catch (Throwable $e) {
    return null;
  }
}


// -----------------------------
// Compatibility helpers
// -----------------------------

if (!function_exists('req_str')) {
  function req_str(string $key, int $minLen = 1, int $maxLen = 2048): string {
    $data = stu_read_json_body();
    $val = $data[$key] ?? ($_POST[$key] ?? ($_GET[$key] ?? null));
    if ($val === null) {
      stu_json(['ok'=>false,'error'=>'missing_' . $key], 400);
    }
    $s = trim((string)$val);
    $len = mb_strlen($s, 'UTF-8');
    if ($len < $minLen || $len > $maxLen) {
      stu_json(['ok'=>false,'error'=>'invalid_' . $key], 400);
    }
    return $s;
  }
}

if (!function_exists('json_out')) {
  function json_out(array $payload, int $code = 200): void {
    stu_json($payload, $code);
  }
}
