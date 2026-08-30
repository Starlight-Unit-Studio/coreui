<?php
declare(strict_types=1);

/**
 * Account provisioning shared by registration and Admin Core.
 * Every interactive Ember CoreUI user receives one stable internal operator character.
 */

function coreui_account_clean_email(string $value): string {
  return strtolower(trim((string)preg_replace('/\s+/', '', $value)));
}

function coreui_account_valid_email(string $value): bool {
  $length = strlen($value);
  return $length >= 6 && $length <= 254 && filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
}

function coreui_account_clean_display_name(string $value, string $fallback = 'Operator'): string {
  $value = trim((string)preg_replace('~\s+~u', ' ', $value));
  $value = (string)preg_replace('/[\x00-\x1F\x7F]/u', '', $value);
  $value = trim((string)preg_replace("~[^\p{L}\p{N} ._'’()\\-]+~u", '', $value));
  if ($value === '') $value = $fallback;
  if (function_exists('mb_substr')) return mb_substr($value, 0, 64, 'UTF-8');
  return substr($value, 0, 64);
}

function coreui_account_default_display_name(string $email): string {
  $local = trim((string)strtok($email, '@'));
  $local = str_replace(['.', '_', '-'], ' ', $local);
  $local = trim((string)preg_replace('~\s+~u', ' ', $local));
  if ($local === '') return 'Operator';
  return coreui_account_clean_display_name(ucwords($local), 'Operator');
}

function coreui_account_character_name(PDO $pdo, int $uid, string $displayName): string {
  $base = coreui_account_clean_display_name($displayName, 'Operator');
  if (function_exists('mb_substr')) $base = mb_substr($base, 0, 32, 'UTF-8');
  else $base = substr($base, 0, 32);
  if (function_exists('mb_strtolower')) $norm = mb_strtolower($base, 'UTF-8');
  else $norm = strtolower($base);
  if ($norm === 'ember' || $norm === 'system') $base = 'Operator ' . $uid;

  $candidate = $base;
  $st = $pdo->prepare('SELECT user_id FROM stu_characters WHERE name_norm = ? LIMIT 1');
  $normalize = static function (string $name): string {
    return function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
  };
  $st->execute([$normalize($candidate)]);
  $owner = $st->fetchColumn();
  if ($owner !== false && (int)$owner !== $uid) {
    $suffix = ' #' . $uid;
    $room = max(1, 32 - strlen($suffix));
    $head = function_exists('mb_substr') ? mb_substr($base, 0, $room, 'UTF-8') : substr($base, 0, $room);
    $candidate = rtrim($head) . $suffix;
  }
  return $candidate;
}

function coreui_account_provision(PDO $pdo, int $uid, string $email, string $displayName): array {
  if ($uid <= 0) throw new InvalidArgumentException('invalid_user');
  $displayName = coreui_account_clean_display_name($displayName, coreui_account_default_display_name($email));

  $stExisting = $pdo->prepare('SELECT id, name, gender FROM stu_characters WHERE user_id = ? AND id <> ? ORDER BY created_at ASC LIMIT 1');
  $stExisting->execute([$uid, 'ember']);
  $character = $stExisting->fetch(PDO::FETCH_ASSOC);

  if (!$character) {
    $characterId = 'operator_' . substr(hash('sha256', 'coreui|' . $uid . '|' . $email), 0, 16);
    $characterName = coreui_account_character_name($pdo, $uid, $displayName);
    $nameNorm = function_exists('mb_strtolower')
      ? mb_strtolower($characterName, 'UTF-8')
      : strtolower($characterName);
    $stCharacter = $pdo->prepare(
      "INSERT INTO stu_characters
         (id, user_id, alliance_id, name, name_norm, world_id, portrait_index, portrait_path, gender, created_at)
       VALUES (?, ?, NULL, ?, ?, 1, 0, '', NULL, NOW())"
    );
    $stCharacter->execute([$characterId, $uid, $characterName, $nameNorm]);
    $character = ['id' => $characterId, 'name' => $characterName, 'gender' => null];
  }

  $stProfile = $pdo->prepare(
    "INSERT INTO stu_coreui_profiles (user_id, display_name, assistant_name, created_at, updated_at)
     VALUES (?, ?, 'Ember', NOW(), NOW())
     ON DUPLICATE KEY UPDATE display_name = IF(display_name='', VALUES(display_name), display_name), updated_at = NOW()"
  );
  $stProfile->execute([$uid, $displayName]);

  $characterPayload = [[
    'id' => (string)$character['id'],
    'name' => (string)$character['name'],
    'level' => 1,
    'lv' => 1,
    'xp' => 0,
    'xpMax' => 200,
    'gender' => $character['gender'] ?? null,
  ]];
  $stKv = $pdo->prepare(
    'INSERT INTO stu_kv (user_id, k, value, updated_at) VALUES (?, ?, ?, NOW()) '
    . 'ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = NOW()'
  );
  $stKv->execute([$uid, 'stu_characters', json_encode($characterPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
  $stKv->execute([$uid, 'stu_active_character_id', (string)$character['id']]);

  return [
    'user_id' => $uid,
    'character_id' => (string)$character['id'],
    'display_name' => $displayName,
  ];
}

function coreui_account_ensure(PDO $pdo, int $uid, string $email): void {
  if ($uid <= 0) return;
  $stCharacter = $pdo->prepare('SELECT name FROM stu_characters WHERE user_id = ? AND id <> ? ORDER BY created_at ASC LIMIT 1');
  $stCharacter->execute([$uid, 'ember']);
  $existingCharacterName = (string)($stCharacter->fetchColumn() ?: '');
  $hasCharacter = $existingCharacterName !== '';
  $hasProfile = false;
  try {
    $stProfile = $pdo->prepare('SELECT COUNT(*) FROM stu_coreui_profiles WHERE user_id = ?');
    $stProfile->execute([$uid]);
    $hasProfile = (int)$stProfile->fetchColumn() > 0;
  } catch (Throwable $e) {
    throw new RuntimeException('missing_schema_004');
  }
  if ($hasCharacter && $hasProfile) return;

  $started = !$pdo->inTransaction();
  if ($started) $pdo->beginTransaction();
  try {
    coreui_account_provision(
      $pdo,
      $uid,
      $email,
      $hasCharacter ? $existingCharacterName : coreui_account_default_display_name($email)
    );
    if ($started) $pdo->commit();
  } catch (Throwable $e) {
    if ($started && $pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }
}

function coreui_account_create(
  PDO $pdo,
  string $email,
  string $password,
  string $displayName,
  int $permissionLevel = 4
): array {
  $email = coreui_account_clean_email($email);
  if (!coreui_account_valid_email($email)) throw new InvalidArgumentException('invalid_username');
  if (strlen($password) < 8 || strlen($password) > 1024) throw new InvalidArgumentException('invalid_password');
  $permissionLevel = max(0, min(4, $permissionLevel));
  $displayName = coreui_account_clean_display_name($displayName, coreui_account_default_display_name($email));

  $started = !$pdo->inTransaction();
  if ($started) $pdo->beginTransaction();
  try {
    $stExists = $pdo->prepare('SELECT id FROM stu_users WHERE username = ? LIMIT 1');
    $stExists->execute([$email]);
    if ($stExists->fetchColumn()) throw new RuntimeException('username_taken');

    $hash = password_hash($password, PASSWORD_DEFAULT);
    if (!is_string($hash) || $hash === '') throw new RuntimeException('hash_failed');
    $stUser = $pdo->prepare(
      'INSERT INTO stu_users (guest_key, created_at, username, password_hash, is_guest, permission_level) '
      . 'VALUES (NULL, NOW(), ?, ?, 0, ?)'
    );
    $stUser->execute([$email, $hash, $permissionLevel]);
    $uid = (int)$pdo->lastInsertId();
    $result = coreui_account_provision($pdo, $uid, $email, $displayName);
    $result['permission_level'] = $permissionLevel;
    if ($started) $pdo->commit();
    return $result;
  } catch (Throwable $e) {
    if ($started && $pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }
}
