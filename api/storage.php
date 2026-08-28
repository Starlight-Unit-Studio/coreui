<?php
require_once __DIR__ . '/helpers.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$pdo = stu_pdo();

// Auto-create guest user on any request so the frontend can just call storage.
$uid = stu_bootstrap_user($pdo);

if ($method === 'GET') {
  $key = (string)($_GET['key'] ?? '');
  stu_validate_key($key);

  $stmt = $pdo->prepare('SELECT value, updated_at FROM stu_kv WHERE user_id = ? AND k = ? ORDER BY updated_at DESC LIMIT 1');
  $stmt->execute([$uid, $key]);
  $row = $stmt->fetch();

  // Account isolation hardening: sanitize sensitive keys on READ.
  if ($key === 'stu_characters') {
    $raw = $row ? (string)($row['value'] ?? '') : '';

    // RECOVERY: KV leer aber Tabelle hat Charaktere -> aus Tabelle wiederherstellen.
    // (gleicher Fix wie in batch.php gegen den Charaktererstellung-nach-Logout-Bug)
    $kvEmpty = (trim($raw) === '' || trim($raw) === '[]');
    if ($kvEmpty) {
      $rebuilt = stu_rebuild_kv_chars_from_table($pdo, $uid);
      if ($rebuilt !== null && $rebuilt !== '[]') {
        $raw = $rebuilt;
        stu_kv_write($pdo, $uid, 'stu_characters', $rebuilt);
        $row = ['value' => $rebuilt, 'updated_at' => date('Y-m-d H:i:s')];
      }
    }

    $san = stu_sanitize_characters_kv($pdo, $uid, $raw);
    if (!$row) {
      $row = ['value' => $san, 'updated_at' => null];
    } elseif (($row['value'] ?? null) !== $san) {
      stu_kv_write($pdo, $uid, 'stu_characters', $san);
      $row['value'] = $san;
      $row['updated_at'] = date('Y-m-d H:i:s');
    }
  } elseif ($key === 'stu_active_character_id') {
    // Validate against sanitized character list.
    $stC = $pdo->prepare('SELECT value FROM stu_kv WHERE user_id = ? AND k = ? ORDER BY updated_at DESC LIMIT 1');
    $stC->execute([$uid, 'stu_characters']);
    $charsSan = stu_sanitize_characters_kv($pdo, $uid, $stC->fetchColumn() ?: '[]');
    $rawA = $row ? (string)($row['value'] ?? '') : '';
    $activeSan = stu_sanitize_active_character_kv($pdo, $uid, $rawA, $charsSan);
    if ($activeSan === null) {
      $pdo->prepare('DELETE FROM stu_kv WHERE user_id = ? AND k = ?')->execute([$uid, 'stu_active_character_id']);
      $row = ['value' => null, 'updated_at' => null];
    } else {
      $row = ['value' => $activeSan, 'updated_at' => ($row['updated_at'] ?? null)];
    }
  } elseif (strpos($key, 'stu_idle_state_') === 0) {
    $cid = substr($key, strlen('stu_idle_state_'));
    if ($cid && !stu_user_owns_character($pdo, $uid, $cid)) {
      $row = ['value' => null, 'updated_at' => null];
    }

  } elseif (strpos($key, 'stu_ep_v3_') === 0) {
    $cid = substr($key, strlen('stu_ep_v3_'));
    if ($cid && !stu_user_owns_character($pdo, $uid, $cid)) {
      $row = ['value' => null, 'updated_at' => null];
    }
  }


  if (!$row) {
    stu_json(['ok' => true, 'key' => $key, 'value' => null, 'updated_at' => null]);
  }
  stu_json(['ok' => true, 'key' => $key, 'value' => $row['value'], 'updated_at' => $row['updated_at']]);
}

if ($method === 'POST') {
  $body = stu_read_json_body();
  $key = (string)($body['key'] ?? '');
  $value = (string)($body['value'] ?? '');
  stu_validate_key($key);

  // Account isolation: sanitize on WRITE.
  if ($key === 'stu_characters') {
    $value = stu_sanitize_characters_kv($pdo, $uid, $value);
  } elseif ($key === 'stu_active_character_id') {
    $stC = $pdo->prepare('SELECT value FROM stu_kv WHERE user_id = ? AND k = ? ORDER BY updated_at DESC LIMIT 1');
    $stC->execute([$uid, 'stu_characters']);
    $charsSan = stu_sanitize_characters_kv($pdo, $uid, $stC->fetchColumn() ?: '[]');
    $activeSan = stu_sanitize_active_character_kv($pdo, $uid, $value, $charsSan);
    if ($activeSan === null) stu_json(['ok'=>false,'error'=>'invalid_active_character'], 400);
    $value = $activeSan;
  } elseif (strpos($key, 'stu_idle_state_') === 0) {
    $cid = substr($key, strlen('stu_idle_state_'));
    if ($cid && !stu_user_owns_character($pdo, $uid, $cid)) {
      stu_json(['ok'=>false,'error'=>'forbidden_idle_state'], 403);
    }
  } elseif (strpos($key, 'stu_ep_v3_') === 0) {
    $cid = substr($key, strlen('stu_ep_v3_'));
    if ($cid && !stu_user_owns_character($pdo, $uid, $cid)) {
      stu_json(['ok'=>false,'error'=>'forbidden_campaign_state'], 403);
    }
  }

  stu_limit_value($value);

  stu_kv_write($pdo, $uid, $key, $value);

  stu_json(['ok' => true, 'key' => $key]);
}

stu_json(['ok' => false, 'error' => 'method_not_allowed'], 405);
