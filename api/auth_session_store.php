<?php
declare(strict_types=1);

/**
 * Widerrufbare SQL-Schicht fuer authentifizierte Ember CoreUI-PHP-Sitzungen.
 * In der Datenbank liegt nur SHA-256 des zufaelligen Sitzungstokens.
 */

function coreui_auth_session_schema_ready(PDO $pdo): bool {
  static $ready = null;
  if ($ready !== null) return $ready;
  try {
    $pdo->query('SELECT user_id, token_hash, expires_at, revoked_at FROM stu_auth_sessions LIMIT 0');
    return $ready = true;
  } catch (Throwable $e) {
    return $ready = false;
  }
}

function coreui_auth_session_hash(string $token): string {
  return hash('sha256', $token);
}

function coreui_auth_session_token(): string {
  $token = $_SESSION['coreui_auth_token'] ?? '';
  return is_string($token) ? trim($token) : '';
}

function coreui_auth_device_label(string $userAgent): string {
  $ua = strtolower($userAgent);
  $device = 'Unbekanntes Geraet';
  if (str_contains($ua, 'android')) $device = 'Android';
  elseif (str_contains($ua, 'iphone')) $device = 'iPhone';
  elseif (str_contains($ua, 'ipad')) $device = 'iPad';
  elseif (str_contains($ua, 'windows')) $device = 'Windows';
  elseif (str_contains($ua, 'macintosh') || str_contains($ua, 'mac os')) $device = 'macOS';
  elseif (str_contains($ua, 'linux')) $device = 'Linux';

  $browser = '';
  if (str_contains($ua, 'edg/')) $browser = 'Edge';
  elseif (str_contains($ua, 'firefox/')) $browser = 'Firefox';
  elseif (str_contains($ua, 'chrome/') || str_contains($ua, 'crios/')) $browser = 'Chrome';
  elseif (str_contains($ua, 'safari/')) $browser = 'Safari';
  return $browser !== '' ? ($device . ' / ' . $browser) : $device;
}

function coreui_auth_session_issue(PDO $pdo, int $uid): ?array {
  if ($uid <= 0 || !coreui_auth_session_schema_ready($pdo)) return null;
  stu_start_session();
  $token = bin2hex(random_bytes(32));
  $hash = coreui_auth_session_hash($token);
  $userAgent = trim((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
  if (function_exists('mb_substr')) $userAgent = mb_substr($userAgent, 0, 512, 'UTF-8');
  else $userAgent = substr($userAgent, 0, 512);
  $label = coreui_auth_device_label($userAgent);

  $pdo->beginTransaction();
  try {
    $pdo->prepare(
      "UPDATE stu_auth_sessions SET revoked_at=NOW(), revoked_reason='token_rotated' "
      . 'WHERE user_id=? AND token_hash=? AND revoked_at IS NULL'
    )->execute([$uid, coreui_auth_session_hash(coreui_auth_session_token())]);
    $st = $pdo->prepare(
      'INSERT INTO stu_auth_sessions '
      . '(user_id,token_hash,device_label,user_agent,created_at,last_seen_at,expires_at,revoked_at,revoked_reason) '
      . 'VALUES (?,?,?,?,NOW(),NOW(),DATE_ADD(NOW(),INTERVAL 30 DAY),NULL,\'\')'
    );
    $st->execute([$uid, $hash, $label, $userAgent]);
    $id = (int)$pdo->lastInsertId();
    $pdo->prepare(
      'DELETE FROM stu_auth_sessions WHERE user_id=? '
      . 'AND ((revoked_at IS NOT NULL AND revoked_at < DATE_SUB(NOW(),INTERVAL 90 DAY)) '
      . 'OR expires_at < DATE_SUB(NOW(),INTERVAL 90 DAY))'
    )->execute([$uid]);
    $pdo->commit();
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }
  $_SESSION['coreui_auth_token'] = $token;
  $_SESSION['coreui_auth_session_id'] = $id;
  $_SESSION['coreui_auth_checked_at'] = time();
  return ['id'=>$id, 'token'=>$token];
}

function coreui_auth_session_validate_current(PDO $pdo, int $uid): bool {
  static $requestCache = [];
  if ($uid <= 0) return false;
  if (!coreui_auth_session_schema_ready($pdo)) return true;
  stu_start_session();
  $token = coreui_auth_session_token();
  if ($token === '') {
    return coreui_auth_session_issue($pdo, $uid) !== null;
  }

  $hash = coreui_auth_session_hash($token);
  $cacheKey = $uid . ':' . $hash;
  if (array_key_exists($cacheKey, $requestCache)) return $requestCache[$cacheKey];
  $st = $pdo->prepare(
    'SELECT id FROM stu_auth_sessions WHERE user_id=? AND token_hash=? '
    . 'AND revoked_at IS NULL AND expires_at>NOW() LIMIT 1'
  );
  $st->execute([$uid, $hash]);
  $id = (int)($st->fetchColumn() ?: 0);
  if ($id <= 0) return $requestCache[$cacheKey] = false;
  $lastTouch = (int)($_SESSION['coreui_auth_checked_at'] ?? 0);
  if ($lastTouch <= 0 || (time() - $lastTouch) >= 60) {
    $pdo->prepare(
      'UPDATE stu_auth_sessions SET last_seen_at=NOW(), expires_at=DATE_ADD(NOW(),INTERVAL 30 DAY) '
      . 'WHERE id=? AND user_id=?'
    )->execute([$id, $uid]);
  }
  $_SESSION['coreui_auth_session_id'] = $id;
  $_SESSION['coreui_auth_checked_at'] = time();
  return $requestCache[$cacheKey] = true;
}

function coreui_auth_session_revoke_current(PDO $pdo, int $uid, string $reason = 'logout'): void {
  if ($uid <= 0 || !coreui_auth_session_schema_ready($pdo)) return;
  $token = coreui_auth_session_token();
  if ($token === '') return;
  $st = $pdo->prepare(
    'UPDATE stu_auth_sessions SET revoked_at=NOW(), revoked_reason=? '
    . 'WHERE user_id=? AND token_hash=? AND revoked_at IS NULL'
  );
  $st->execute([substr($reason, 0, 64), $uid, coreui_auth_session_hash($token)]);
}

function coreui_auth_session_list(PDO $pdo, int $uid): array {
  if ($uid <= 0 || !coreui_auth_session_schema_ready($pdo)) return [];
  $currentHash = coreui_auth_session_token() !== ''
    ? coreui_auth_session_hash(coreui_auth_session_token())
    : '';
  $st = $pdo->prepare(
    'SELECT id, token_hash, device_label, user_agent, created_at, last_seen_at, expires_at '
    . 'FROM stu_auth_sessions WHERE user_id=? AND revoked_at IS NULL AND expires_at>NOW() '
    . 'ORDER BY last_seen_at DESC, id DESC LIMIT 50'
  );
  $st->execute([$uid]);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  foreach ($rows as &$row) {
    $row['id'] = (int)($row['id'] ?? 0);
    $row['current'] = $currentHash !== '' && hash_equals($currentHash, (string)($row['token_hash'] ?? ''));
    unset($row['token_hash']);
  }
  unset($row);
  return $rows;
}

function coreui_auth_session_revoke(PDO $pdo, int $uid, int $sessionId, string $reason = 'user_revoked'): bool {
  if ($uid <= 0 || $sessionId <= 0 || !coreui_auth_session_schema_ready($pdo)) return false;
  $st = $pdo->prepare(
    'UPDATE stu_auth_sessions SET revoked_at=NOW(), revoked_reason=? '
    . 'WHERE id=? AND user_id=? AND revoked_at IS NULL'
  );
  $st->execute([substr($reason, 0, 64), $sessionId, $uid]);
  return $st->rowCount() === 1;
}

function coreui_auth_session_revoke_others(PDO $pdo, int $uid, string $reason = 'user_revoked_others'): int {
  if ($uid <= 0 || !coreui_auth_session_schema_ready($pdo)) return 0;
  $currentHash = coreui_auth_session_token() !== ''
    ? coreui_auth_session_hash(coreui_auth_session_token())
    : '';
  $sql = 'UPDATE stu_auth_sessions SET revoked_at=NOW(), revoked_reason=? '
    . 'WHERE user_id=? AND revoked_at IS NULL AND expires_at>NOW()';
  $params = [substr($reason, 0, 64), $uid];
  if ($currentHash !== '') {
    $sql .= ' AND token_hash<>?';
    $params[] = $currentHash;
  }
  $st = $pdo->prepare($sql);
  $st->execute($params);
  return $st->rowCount();
}

function coreui_auth_session_revoke_all(PDO $pdo, int $uid, string $reason = 'password_reset'): int {
  if ($uid <= 0 || !coreui_auth_session_schema_ready($pdo)) return 0;
  $st = $pdo->prepare(
    'UPDATE stu_auth_sessions SET revoked_at=NOW(), revoked_reason=? '
    . 'WHERE user_id=? AND revoked_at IS NULL'
  );
  $st->execute([substr($reason, 0, 64), $uid]);
  return $st->rowCount();
}
