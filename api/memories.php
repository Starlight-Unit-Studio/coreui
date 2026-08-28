<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

$pdo = stu_pdo();
$uid = stu_require_user_id();
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

function coreui_memory_owned_character(PDO $pdo, int $uid, string $characterId): bool {
  if ($characterId === '') return false;
  $st = $pdo->prepare('SELECT 1 FROM stu_characters WHERE id = ? AND user_id = ? LIMIT 1');
  $st->execute([$characterId, $uid]);
  return (bool)$st->fetchColumn();
}

if ($method === 'GET') {
  $q = trim((string)($_GET['q'] ?? ''));
  $limit = max(1, min(250, (int)($_GET['limit'] ?? 100)));
  $params = [$uid, $uid];
  $where = "(scope = 'global' OR (scope = 'user' AND user_id = ?) OR (scope = 'character' AND character_id IN (SELECT id FROM stu_characters WHERE user_id = ?)))";
  if ($q !== '') {
    $where .= ' AND fact LIKE ?';
    $params[] = '%' . $q . '%';
  }
  $st = $pdo->prepare(
    'SELECT id, fact, relevance, scope, user_id, character_id, created_at, updated_at, last_used_at '
    . 'FROM ember_memories WHERE ' . $where . ' ORDER BY relevance DESC, updated_at DESC LIMIT ' . $limit
  );
  $st->execute($params);
  stu_json(['ok' => true, 'items' => $st->fetchAll(PDO::FETCH_ASSOC) ?: []]);
}

if ($method !== 'POST') stu_json(['ok' => false, 'error' => 'method_not_allowed'], 405);

stu_require_csrf();
$body = stu_read_json_body();
$action = strtolower(trim((string)($body['action'] ?? 'upsert')));

if ($action === 'delete') {
  $id = (int)($body['id'] ?? 0);
  if ($id <= 0) stu_json(['ok' => false, 'error' => 'invalid_id'], 400);
  $st = $pdo->prepare(
    "DELETE FROM ember_memories WHERE id = ? AND (
      (scope = 'user' AND user_id = ?)
      OR (scope = 'character' AND character_id IN (SELECT id FROM stu_characters WHERE user_id = ?))
      OR (scope = 'global' AND ? <= 1)
    )"
  );
  $st->execute([$id, $uid, $uid, stu_get_permission_level($pdo)]);
  stu_json(['ok' => true, 'deleted' => $st->rowCount() > 0]);
}

if ($action !== 'upsert') stu_json(['ok' => false, 'error' => 'unknown_action'], 400);

$id = max(0, (int)($body['id'] ?? 0));
$fact = trim((string)($body['fact'] ?? ''));
$scope = strtolower(trim((string)($body['scope'] ?? 'user')));
$relevance = max(1, min(10, (int)($body['relevance'] ?? 5)));
$characterId = trim((string)($body['character_id'] ?? ''));

$length = function_exists('mb_strlen') ? mb_strlen($fact, 'UTF-8') : strlen($fact);
if ($length < 3 || $length > 1000) stu_json(['ok' => false, 'error' => 'invalid_fact'], 400);
if (!in_array($scope, ['global', 'user', 'character'], true)) stu_json(['ok' => false, 'error' => 'invalid_scope'], 400);
if ($scope === 'global' && stu_get_permission_level($pdo) > 1) stu_json(['ok' => false, 'error' => 'forbidden'], 403);
if ($scope === 'character' && !coreui_memory_owned_character($pdo, $uid, $characterId)) {
  stu_json(['ok' => false, 'error' => 'character_not_found'], 404);
}

$memoryUserId = ($scope === 'user') ? $uid : null;
$memoryCharacterId = ($scope === 'character') ? $characterId : null;
$normalized = function_exists('mb_strtolower') ? mb_strtolower($fact, 'UTF-8') : strtolower($fact);
$hash = md5($normalized);

if ($id > 0) {
  $permissionLevel = stu_get_permission_level($pdo);
  $stOwned = $pdo->prepare(
    "SELECT id FROM ember_memories WHERE id = ? AND (
       (scope = 'user' AND user_id = ?)
       OR (scope = 'character' AND character_id IN (SELECT id FROM stu_characters WHERE user_id = ?))
       OR (scope = 'global' AND ? <= 1)
     ) LIMIT 1"
  );
  $stOwned->execute([$id, $uid, $uid, $permissionLevel]);
  if (!$stOwned->fetchColumn()) stu_json(['ok' => false, 'error' => 'memory_not_found'], 404);

  $st = $pdo->prepare(
    'UPDATE ember_memories SET fact = ?, relevance = ?, scope = ?, user_id = ?, character_id = ?, fact_hash = ?, updated_at = NOW() WHERE id = ?'
  );
  $st->execute([$fact, $relevance, $scope, $memoryUserId, $memoryCharacterId, $hash, $id]);
  stu_json(['ok' => true, 'id' => $id, 'mode' => 'update']);
}

$stExisting = $pdo->prepare(
  'SELECT id FROM ember_memories WHERE scope = ? AND user_id <=> ? AND character_id <=> ? AND fact_hash = ? LIMIT 1'
);
$stExisting->execute([$scope, $memoryUserId, $memoryCharacterId, $hash]);
$existingId = (int)($stExisting->fetchColumn() ?: 0);
if ($existingId > 0) {
  $st = $pdo->prepare('UPDATE ember_memories SET fact = ?, relevance = GREATEST(relevance, ?), updated_at = NOW(), last_used_at = NOW() WHERE id = ?');
  $st->execute([$fact, $relevance, $existingId]);
  stu_json(['ok' => true, 'id' => $existingId, 'mode' => 'merge']);
}

$st = $pdo->prepare(
  'INSERT INTO ember_memories (fact, relevance, scope, user_id, character_id, fact_hash, last_used_at) VALUES (?, ?, ?, ?, ?, ?, NOW())'
);
$st->execute([$fact, $relevance, $scope, $memoryUserId, $memoryCharacterId, $hash]);
stu_json(['ok' => true, 'id' => (int)$pdo->lastInsertId(), 'mode' => 'insert']);
