<?php
declare(strict_types=1);

$root = dirname(__DIR__);

function security_test_expect(bool $condition, string $message): void {
  if (!$condition) throw new RuntimeException($message);
}

$sessionStore = (string)file_get_contents($root . '/api/auth_session_store.php');
$securityApi = (string)file_get_contents($root . '/api/account_security.php');
$exportApi = (string)file_get_contents($root . '/api/account_export.php');
$modelCatalog = (string)file_get_contents($root . '/api/model_catalog.php');
$settingsApi = (string)file_get_contents($root . '/api/user_settings.php');
$settingsJs = (string)file_get_contents($root . '/js/settings.js');
$migration = (string)file_get_contents($root . '/database/migrations/006_account_security.sql');

security_test_expect(str_contains($migration, 'stu_auth_sessions'), 'Migration 006 enthaelt keine Auth-Sitzungen.');
security_test_expect(str_contains($sessionStore, "unset(\$row['token_hash'])"), 'Token-Hashes werden in Sitzungslisten nicht entfernt.');
security_test_expect(str_contains($securityApi, "stu_require_csrf();"), 'Sicherheitsaktionen sind nicht CSRF-geschuetzt.');
security_test_expect(str_contains($securityApi, "password_verify(\$current"), 'Passwortwechsel prueft das aktuelle Passwort nicht.');
security_test_expect(!str_contains($exportApi, 'password_hash,'), 'Kontoexport selektiert einen Passwort-Hash.');
security_test_expect(!str_contains($exportApi, 'token_hash'), 'Kontoexport referenziert Login-Token-Hashes.');
security_test_expect(!str_contains($exportApi, 'thinking_content FROM'), 'Kontoexport selektiert Thinking-Inhalte.');
security_test_expect(!str_contains($exportApi, 'rel_path'), 'Kontoexport referenziert private Upload-Pfade.');
security_test_expect(str_contains($exportApi, "channel='console' AND user_id=?"), 'Nachrichtenexport ist nicht sichtbar benutzergebunden.');
security_test_expect(str_contains($modelCatalog, 'CURLOPT_FOLLOWLOCATION => false'), 'Ollama-Katalog darf Redirects folgen.');
security_test_expect(str_contains($settingsApi, 'coreui_ollama_model_exists'), 'Gespeicherte Modellwahl wird nicht gegen Ollama geprueft.');
security_test_expect(str_contains($settingsJs, 'loadAccountSecurity()'), 'Kontosicherheitsoberflaeche wird nicht geladen.');

if (!is_file($root . '/api/config.local.php')) {
  echo "Account-Security-Selftest: 12 statische Pruefungen bestanden; DB-Test ohne lokale Konfiguration uebersprungen.\n";
  exit(0);
}

require_once $root . '/api/config.php';
require_once $root . '/api/db.php';
require_once $root . '/api/helpers.php';

$pdo = stu_pdo();
security_test_expect(coreui_auth_session_schema_ready($pdo), 'Migration 006 ist in der Datenbank nicht bereit.');

stu_start_session();
$token = bin2hex(random_bytes(32));
$_SESSION['coreui_auth_token'] = $token;
$pdo->beginTransaction();
try {
  $username = 'security-selftest-' . bin2hex(random_bytes(8)) . '@invalid.local';
  $hash = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
  $stUser = $pdo->prepare(
    'INSERT INTO stu_users (username,password_hash,is_guest,permission_level,created_at) VALUES (?,?,0,4,NOW())'
  );
  $stUser->execute([$username, $hash]);
  $uid = (int)$pdo->lastInsertId();

  $stSession = $pdo->prepare(
    'INSERT INTO stu_auth_sessions '
      . '(user_id,token_hash,device_label,user_agent,created_at,last_seen_at,expires_at,revoked_at,revoked_reason) '
      . "VALUES (?,?,?,'selftest',NOW(),NOW(),DATE_ADD(NOW(),INTERVAL 1 DAY),NULL,'')"
  );
  $stSession->execute([$uid, coreui_auth_session_hash($token), 'Aktuelles Testgeraet']);
  $_SESSION['coreui_auth_session_id'] = (int)$pdo->lastInsertId();
  $stSession->execute([$uid, hash('sha256', random_bytes(32)), 'Anderes Testgeraet']);
  $otherId = (int)$pdo->lastInsertId();

  $listed = coreui_auth_session_list($pdo, $uid);
  security_test_expect(count($listed) === 2, 'Aktive Auth-Sitzungen werden nicht vollstaendig gelistet.');
  security_test_expect(!array_key_exists('token_hash', $listed[0]), 'Token-Hash wurde an den Client durchgereicht.');
  security_test_expect(count(array_filter($listed, static fn(array $row): bool => !empty($row['current']))) === 1, 'Aktuelle Sitzung wird nicht eindeutig erkannt.');
  security_test_expect(coreui_auth_session_revoke($pdo, $uid, $otherId), 'Einzelwiderruf ist fehlgeschlagen.');
  security_test_expect(count(coreui_auth_session_list($pdo, $uid)) === 1, 'Widerrufene Sitzung bleibt aktiv.');
  security_test_expect(coreui_auth_session_revoke_all($pdo, $uid, 'selftest') === 1, 'Gesamtwiderruf ist fehlgeschlagen.');
  security_test_expect(count(coreui_auth_session_list($pdo, $uid)) === 0, 'Gesamtwiderruf laesst aktive Sitzungen zurueck.');
  $pdo->rollBack();
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  throw $e;
}

echo "Account-Security-Selftest: 19 Pruefungen bestanden.\n";
