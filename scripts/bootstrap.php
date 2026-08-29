<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
  fwrite(STDERR, "CLI only\n");
  exit(1);
}

require_once __DIR__ . '/../api/db.php';

$options = getopt('', ['email:', 'password:', 'name::', 'gender::']);
$email = strtolower(trim((string)($options['email'] ?? getenv('COREUI_ADMIN_EMAIL') ?: '')));
$password = (string)($options['password'] ?? getenv('COREUI_ADMIN_PASSWORD') ?: '');
$name = trim((string)($options['name'] ?? getenv('COREUI_ADMIN_NAME') ?: 'Operator'));
$gender = strtolower(trim((string)($options['gender'] ?? getenv('COREUI_ADMIN_GENDER') ?: '')));

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
  fwrite(STDERR, "Ungueltige oder fehlende Admin-E-Mail. Nutze --email.\n");
  exit(2);
}
if (strlen($password) < 12) {
  fwrite(STDERR, "Das Admin-Passwort muss mindestens 12 Zeichen lang sein.\n");
  exit(2);
}
if ($name === '' || mb_strlen($name, 'UTF-8') > 32) {
  fwrite(STDERR, "Der Anzeigename muss 1 bis 32 Zeichen lang sein.\n");
  exit(2);
}
if (mb_strtolower($name, 'UTF-8') === 'ember') {
  fwrite(STDERR, "Der Anzeigename Ember ist fuer den lokalen Kern reserviert.\n");
  exit(2);
}
if (!in_array($gender, ['', 'm', 'f'], true)) {
  fwrite(STDERR, "Gender muss leer, m oder f sein.\n");
  exit(2);
}

$pdo = stu_pdo();
$pdo->beginTransaction();

try {
  $pdo->exec(
    "INSERT INTO stu_users
       (id, guest_key, username, password_hash, is_guest, permission_level, created_at)
     VALUES
       (1, NULL, 'ember@coreui.invalid', NULL, 1, 0, NOW())
     ON DUPLICATE KEY UPDATE permission_level = 0"
  );

  $emberCharacter = [
    'id' => 'ember',
    'name' => 'Ember',
    'level' => 73,
    'lv' => 73,
    'xp' => 0,
    'xpMax' => 7400,
    'gender' => 'f',
  ];

  $stEmberChar = $pdo->prepare(
    "INSERT INTO stu_characters
       (id, user_id, alliance_id, name, name_norm, world_id, portrait_index, portrait_path, gender, created_at)
     VALUES ('ember', 1, NULL, 'Ember', 'ember', 1, 0, '', 'f', NOW())
     ON DUPLICATE KEY UPDATE user_id = 1, name = 'Ember', name_norm = 'ember', gender = 'f'"
  );
  $stEmberChar->execute();

  $stEmberKv = $pdo->prepare(
    "INSERT INTO stu_kv (user_id, k, value, updated_at) VALUES (1, 'stu_characters', ?, NOW())
     ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = NOW()"
  );
  $stEmberKv->execute([json_encode([$emberCharacter], JSON_UNESCAPED_UNICODE)]);

  $hash = password_hash($password, PASSWORD_DEFAULT);
  if (!is_string($hash) || $hash === '') throw new RuntimeException('password_hash_failed');

  $stFind = $pdo->prepare('SELECT id FROM stu_users WHERE username = ? LIMIT 1');
  $stFind->execute([$email]);
  $adminId = (int)($stFind->fetchColumn() ?: 0);

  if ($adminId > 0) {
    $stUpdate = $pdo->prepare(
      'UPDATE stu_users SET password_hash = ?, is_guest = 0, permission_level = 0, banned_until = NULL, banned_reason = ? WHERE id = ?'
    );
    $stUpdate->execute([$hash, '', $adminId]);
  } else {
    $stInsert = $pdo->prepare(
      'INSERT INTO stu_users (guest_key, username, password_hash, is_guest, permission_level, created_at) VALUES (NULL, ?, ?, 0, 0, NOW())'
    );
    $stInsert->execute([$email, $hash]);
    $adminId = (int)$pdo->lastInsertId();
  }

  $characterId = 'operator_' . substr(hash('sha256', $email), 0, 16);
  $nameNorm = mb_strtolower($name, 'UTF-8');
  $stCharacter = $pdo->prepare(
    "INSERT INTO stu_characters
       (id, user_id, alliance_id, name, name_norm, world_id, portrait_index, portrait_path, gender, created_at)
     VALUES (?, ?, NULL, ?, ?, 1, 0, '', ?, NOW())
     ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), name = VALUES(name), name_norm = VALUES(name_norm), gender = VALUES(gender)"
  );
  $stCharacter->execute([$characterId, $adminId, $name, $nameNorm, ($gender !== '' ? $gender : null)]);

  $character = [
    'id' => $characterId,
    'name' => $name,
    'level' => 1,
    'lv' => 1,
    'xp' => 0,
    'xpMax' => 200,
    'gender' => ($gender !== '' ? $gender : null),
  ];

  $stKv = $pdo->prepare(
    "INSERT INTO stu_kv (user_id, k, value, updated_at) VALUES (?, ?, ?, NOW())
     ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = NOW()"
  );
  $stKv->execute([$adminId, 'stu_characters', json_encode([$character], JSON_UNESCAPED_UNICODE)]);
  $stKv->execute([$adminId, 'stu_active_character_id', $characterId]);

  $stProfile = $pdo->prepare(
    "INSERT INTO stu_coreui_profiles (user_id, display_name, assistant_name, created_at, updated_at)
     VALUES (?, ?, 'Ember', NOW(), NOW())
     ON DUPLICATE KEY UPDATE display_name = VALUES(display_name), updated_at = NOW()"
  );
  $stProfile->execute([$adminId, $name]);

  $pdo->exec(
    "INSERT INTO stu_app_settings (k, value, updated_at)
     VALUES ('maintenance_enabled', '0', NOW())
     ON DUPLICATE KEY UPDATE value = '0', updated_at = NOW()"
  );

  $pdo->commit();

  echo "Ember CoreUI wurde initialisiert.\n";
  echo "Admin-ID: " . $adminId . "\n";
  echo "Charakter-ID: " . $characterId . "\n";
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  fwrite(STDERR, "Bootstrap fehlgeschlagen: " . $e->getMessage() . "\n");
  exit(3);
}
