<?php
declare(strict_types=1);

/**
 * Persistente Zuordnung von bis zu zehn privaten Uploads zu einer CoreUI-Nachricht.
 * file_uuid in stu_chat_messages bleibt als lesbare Abwaertskompatibilitaet erhalten.
 */

function coreui_console_attachment_limit(): int {
  return 10;
}

function coreui_console_attachment_schema_ready(PDO $pdo): bool {
  try {
    $pdo->query('SELECT message_id, media_uuid, user_id, position FROM stu_console_message_attachments LIMIT 0');
    return true;
  } catch (Throwable $e) {
    return false;
  }
}

function coreui_console_attachment_normalize_ids($value): array {
  if (!is_array($value)) return [];
  $ids = [];
  foreach ($value as $candidate) {
    $uuid = strtolower(trim((string)$candidate));
    if (!preg_match('~^[a-f0-9]{32}$~', $uuid)) continue;
    $ids[$uuid] = true;
  }
  return array_slice(array_keys($ids), 0, coreui_console_attachment_limit() + 1);
}

function coreui_console_attachment_markers(string $message): array {
  if (!preg_match_all('~\[file:([a-f0-9]{32})\]~i', $message, $matches)) return [];
  return coreui_console_attachment_normalize_ids($matches[1] ?? []);
}

function coreui_console_attachment_strip_markers(string $message): string {
  $message = preg_replace('~\[file:[a-f0-9]{32}\]~i', '', $message);
  return trim((string)preg_replace('~[ \t]{2,}~u', ' ', (string)$message));
}

function coreui_console_attachment_marker_text(array $ids): string {
  $markers = [];
  foreach (array_slice(coreui_console_attachment_normalize_ids($ids), 0, coreui_console_attachment_limit()) as $uuid) {
    $markers[] = '[file:' . $uuid . ']';
  }
  return implode(' ', $markers);
}

/** @return array<string,array<string,mixed>> */
function coreui_console_attachment_validate(PDO $pdo, int $uid, array $ids, bool $forUpdate = false): array {
  $ids = coreui_console_attachment_normalize_ids($ids);
  if (count($ids) > coreui_console_attachment_limit()) {
    throw new RuntimeException('too_many_attachments');
  }
  if ($ids === []) return [];
  if (!coreui_console_attachment_schema_ready($pdo)) {
    throw new RuntimeException('attachment_migration_required');
  }

  $placeholders = implode(',', array_fill(0, count($ids), '?'));
  $st = $pdo->prepare(
    'SELECT uuid, kind, orig_name, mime_type, file_size, public_url, rel_path '
    . 'FROM stu_console_media WHERE user_id=? AND uuid IN (' . $placeholders . ')'
    . ($forUpdate ? ' FOR UPDATE' : '')
  );
  $st->execute(array_merge([$uid], $ids));
  $rows = [];
  foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
    $rows[(string)$row['uuid']] = $row;
  }
  foreach ($ids as $uuid) {
    if (!isset($rows[$uuid])) throw new RuntimeException('attachment_not_found');
  }
  return $rows;
}

function coreui_console_attachment_store(
  PDO $pdo,
  int $messageId,
  int $uid,
  array $ids,
  ?array $validatedRows = null
): void {
  $ids = coreui_console_attachment_normalize_ids($ids);
  if ($ids === []) return;
  if ($messageId <= 0 || $uid <= 0) throw new InvalidArgumentException('invalid_attachment_owner');
  if (count($ids) > coreui_console_attachment_limit()) throw new RuntimeException('too_many_attachments');
  if ($validatedRows === null) $validatedRows = coreui_console_attachment_validate($pdo, $uid, $ids);

  $st = $pdo->prepare(
    'INSERT INTO stu_console_message_attachments '
    . '(message_id,media_uuid,user_id,position,created_at) VALUES (?,?,?,?,NOW())'
  );
  foreach ($ids as $position => $uuid) {
    if (!isset($validatedRows[$uuid])) throw new RuntimeException('attachment_not_found');
    $st->execute([$messageId, $uuid, $uid, $position]);
  }
}

function coreui_console_attachment_ids_for_message(
  PDO $pdo,
  int $messageId,
  int $uid,
  ?string $legacyUuid = null
): array {
  $ids = [];
  if ($messageId > 0 && $uid > 0 && coreui_console_attachment_schema_ready($pdo)) {
    $st = $pdo->prepare(
      'SELECT media_uuid FROM stu_console_message_attachments '
      . 'WHERE message_id=? AND user_id=? ORDER BY position ASC'
    );
    $st->execute([$messageId, $uid]);
    $ids = array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
  }
  if ($ids === [] && is_string($legacyUuid) && preg_match('~^[a-f0-9]{32}$~i', $legacyUuid)) {
    $ids[] = strtolower($legacyUuid);
  }
  return array_slice(
    coreui_console_attachment_normalize_ids($ids),
    0,
    coreui_console_attachment_limit()
  );
}
