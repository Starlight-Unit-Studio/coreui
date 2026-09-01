<?php
declare(strict_types=1);

define('STU_CHAT_LIB', 1);
require __DIR__ . '/chat.php';
require_once __DIR__ . '/console_session_store.php';
require_once __DIR__ . '/console_action_store.php';

$pdo = stu_pdo();
$uid = stu_require_user_id();
stu_enforce_maintenance($pdo, (int)$uid);

if (!coreui_console_session_schema_ready($pdo)) {
  stu_json(['ok'=>false, 'error'=>'session_migration_required'], 503);
}

$sessionId = coreui_console_session_normalize_id($_GET['session_id'] ?? '');
if ($sessionId === '') stu_json(['ok'=>false, 'error'=>'invalid_session'], 400);

try {
  $session = coreui_console_session_require($pdo, (int)$uid, $sessionId);
} catch (Throwable $e) {
  stu_json(['ok'=>false, 'error'=>'session_not_found'], 404);
}

$characterId = trim((string)($_GET['character_id'] ?? ''));
try {
  $char = chat_require_character($pdo, (int)$uid, $characterId);
  $characterId = (string)$char['id'];
} catch (Throwable $e) {
  stu_json(['ok'=>false, 'error'=>'character_not_found'], 404);
}

$limit = max(1, min(100, (int)($_GET['limit'] ?? 60)));
$afterId = max(0, (int)($_GET['after_id'] ?? 0));
$beforeId = max(0, (int)($_GET['before_id'] ?? 0));
if ($afterId > 0 && $beforeId > 0) stu_json(['ok'=>false, 'error'=>'invalid_cursor'], 400);

$select = "SELECT m.id, m.session_id, m.reply_to_id, m.user_id, m.character_id,
                  m.character_name, m.message, m.thinking_content, m.image_url,
                  m.file_uuid, m.created_at,
                  DATE_FORMAT(m.created_at, '%H:%i') AS time_label,
                  DATE_FORMAT(m.created_at, '%d.%m.%Y') AS date_label,
                  cm.kind AS attachment_kind, cm.orig_name AS attachment_name,
                  cm.mime_type AS attachment_mime, cm.file_size AS attachment_size,
                  cm.public_url AS attachment_public_url
             FROM stu_chat_messages m
        LEFT JOIN stu_console_media cm
               ON cm.uuid=m.file_uuid AND cm.user_id=m.user_id
            WHERE m.channel='console' AND m.alliance_id IS NULL
              AND m.user_id=? AND m.session_id=? AND m.deleted_at IS NULL";

$params = [(int)$uid, $sessionId];
$descending = false;
if ($afterId > 0) {
  $select .= ' AND m.id > ? ORDER BY m.id ASC LIMIT ' . ($limit + 1);
  $params[] = $afterId;
} else {
  if ($beforeId > 0) {
    $select .= ' AND m.id < ?';
    $params[] = $beforeId;
  }
  $select .= ' ORDER BY m.id DESC LIMIT ' . ($limit + 1);
  $descending = true;
}

$st = $pdo->prepare($select);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
$hasMore = count($rows) > $limit;
if ($hasMore) array_pop($rows);
if ($descending) $rows = array_reverse($rows);

$attachmentMap = [];
$messageIds = array_values(array_filter(array_map(
  static fn(array $row): int => (int)($row['id'] ?? 0),
  $rows
)));
if ($messageIds !== [] && coreui_console_attachment_schema_ready($pdo)) {
  $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
  $stAttachments = $pdo->prepare(
    'SELECT a.message_id, a.media_uuid, a.position, cm.kind, cm.orig_name, '
    . 'cm.mime_type, cm.file_size, cm.public_url '
    . 'FROM stu_console_message_attachments a '
    . 'JOIN stu_console_media cm ON cm.uuid=a.media_uuid AND cm.user_id=a.user_id '
    . 'WHERE a.user_id=? AND a.message_id IN (' . $placeholders . ') '
    . 'ORDER BY a.message_id ASC, a.position ASC'
  );
  $stAttachments->execute(array_merge([(int)$uid], $messageIds));
  foreach (($stAttachments->fetchAll(PDO::FETCH_ASSOC) ?: []) as $media) {
    $messageId = (int)($media['message_id'] ?? 0);
    $uuid = (string)($media['media_uuid'] ?? '');
    if ($messageId <= 0 || $uuid === '') continue;
    if (!isset($attachmentMap[$messageId])) $attachmentMap[$messageId] = [];
    $attachmentMap[$messageId][] = [
      'uuid' => $uuid,
      'kind' => (string)($media['kind'] ?? 'document'),
      'name' => (string)($media['orig_name'] ?? 'Datei'),
      'mime' => (string)($media['mime_type'] ?? ''),
      'size' => (int)($media['file_size'] ?? 0),
      'url' => stu_public_path('api/console_media.php?uuid=' . rawurlencode($uuid)),
      'image_url' => $media['public_url'] ?: null,
    ];
  }
}

$emberCid = strtolower(ember_character_id());
$emberName = strtolower(ember_character_name());
$showThinking = (bool)(coreui_ai_settings_load($pdo, (int)$uid)['thinking_enabled'] ?? true);
$feedbackMap = [];
$generationMap = [];
try {
  $feedbackMap = coreui_console_feedback_map($pdo, (int)$uid, $messageIds);
  $generationMap = coreui_console_generation_metadata_map($pdo, (int)$uid, $messageIds);
} catch (Throwable $e) {
  // Rollendes Update: Nachrichten bleiben lesbar, auch wenn Migration 008
  // gerade erst durch den Installer eingespielt wird.
}
$maxId = 0;
$oldestId = 0;

foreach ($rows as &$row) {
  $id = (int)($row['id'] ?? 0);
  if ($oldestId === 0 || ($id > 0 && $id < $oldestId)) $oldestId = $id;
  if ($id > $maxId) $maxId = $id;
  $row['id'] = $id;
  $row['reply_to_id'] = (int)($row['reply_to_id'] ?? 0);
  $isEmber = strtolower((string)($row['character_id'] ?? '')) === $emberCid
    || strtolower((string)($row['character_name'] ?? '')) === $emberName;
  $row['is_ember'] = $isEmber;
  $text = chat_console_transport_text((string)($row['message'] ?? ''));
  if ($isEmber) {
    $safe = ember_sanitize_public_reply($text, '');
    $text = $safe !== '' ? $safe : 'Diese ältere Antwort wurde vom Ember CoreUI-Sicherheitsfilter ausgeblendet.';
  }
  $row['message'] = $text;
  $row['thinking_content'] = ($isEmber && $showThinking)
    ? ember_public_thinking_from_storage(isset($row['thinking_content']) ? (string)$row['thinking_content'] : null)
    : null;
  $row['feedback'] = $isEmber ? ($feedbackMap[$id] ?? null) : null;
  $row['generation'] = $isEmber ? ($generationMap[$id] ?? null) : null;

  $attachments = $attachmentMap[$id] ?? [];
  $fileUuid = trim((string)($row['file_uuid'] ?? ''));
  $imageUrl = trim((string)($row['image_url'] ?? ''));
  if ($attachments === [] && $fileUuid !== '' && !empty($row['attachment_name'])) {
    $attachments[] = [
      'uuid' => $fileUuid,
      'kind' => (string)($row['attachment_kind'] ?? 'document'),
      'name' => (string)$row['attachment_name'],
      'mime' => (string)($row['attachment_mime'] ?? ''),
      'size' => (int)($row['attachment_size'] ?? 0),
      'url' => stu_public_path('api/console_media.php?uuid=' . rawurlencode($fileUuid)),
      'image_url' => $row['attachment_public_url'] ?: null,
    ];
  } elseif ($attachments === [] && $imageUrl !== '') {
    $attachments[] = [
      'uuid' => null,
      'kind' => 'image',
      'name' => 'Bild',
      'mime' => '',
      'size' => 0,
      'url' => $imageUrl,
      'image_url' => $imageUrl,
    ];
  }
  $row['attachments'] = $attachments;
  $row['attachment'] = $attachments[0] ?? null;
  unset(
    $row['attachment_kind'], $row['attachment_name'], $row['attachment_mime'],
    $row['attachment_size'], $row['attachment_public_url']
  );
}
unset($row);

if ($maxId > 0) coreui_console_session_mark_read($pdo, (int)$uid, $sessionId, $maxId);

stu_json([
  'ok' => true,
  'session_id' => $sessionId,
  'session_title' => (string)($session['title'] ?? ''),
  'messages' => $rows,
  'oldest_id' => $oldestId,
  'last_id' => $maxId,
  'has_more_before' => $descending ? $hasMore : false,
  'has_more_after' => !$descending ? $hasMore : false,
]);
