<?php
declare(strict_types=1);

require_once __DIR__ . '/console_session_store.php';

function coreui_console_actions_schema_ready(PDO $pdo): bool {
  try {
    $pdo->query('SELECT id,response_floor_id,mode,status,response_message_id,browse_job_id FROM stu_console_generation_requests LIMIT 0');
    return true;
  } catch (Throwable $e) {
    return false;
  }
}

function coreui_console_edits_schema_ready(PDO $pdo): bool {
  try {
    $pdo->query('SELECT id,revision_no,superseded_message_count FROM stu_console_message_revisions LIMIT 0');
    $st = $pdo->query(
      "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='stu_console_generation_requests'
          AND COLUMN_NAME='mode'"
    );
    $modeType = (string)($st->fetchColumn() ?: '');
    return str_contains($modeType, "'edit'");
  } catch (Throwable $e) {
    return false;
  }
}

function coreui_console_action_message(
  PDO $pdo,
  int $uid,
  string $sessionId,
  int $messageId,
  ?bool $mustBeEmber = null
): array {
  $sessionId = coreui_console_session_normalize_id($sessionId);
  if ($uid <= 0 || $sessionId === '' || $messageId <= 0) {
    throw new InvalidArgumentException('invalid_message');
  }
  coreui_console_session_require($pdo, $uid, $sessionId);
  $st = $pdo->prepare(
    "SELECT id,session_id,reply_to_id,user_id,character_id,character_name,message,file_uuid,image_url,created_at
       FROM stu_chat_messages
      WHERE id=? AND channel='console' AND alliance_id IS NULL
        AND user_id=? AND session_id=? AND deleted_at IS NULL
      LIMIT 1"
  );
  $st->execute([$messageId, $uid, $sessionId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) throw new RuntimeException('message_not_found');
  $isEmber = strtolower((string)($row['character_id'] ?? '')) === strtolower(ember_character_id())
    || strtolower((string)($row['character_name'] ?? '')) === strtolower(ember_character_name());
  if ($mustBeEmber === true && !$isEmber) throw new RuntimeException('ember_message_required');
  if ($mustBeEmber === false && $isEmber) throw new RuntimeException('user_message_required');
  $row['is_ember'] = $isEmber;
  return $row;
}

function coreui_console_feedback_toggle(
  PDO $pdo,
  int $uid,
  string $sessionId,
  int $messageId,
  string $value,
  string $characterId
): ?string {
  // ASCII-Tokens funktionieren auch auf aelteren STU-Datenbanken, deren bereits
  // vorhandene Reaktionstabelle noch nicht mit utf8mb4 angelegt wurde. Genau
  // dort schlug das Speichern der eigentlichen Emoji-Zeichen mit einem SQL-
  // Fehler fehl, obwohl Migration 008 und alle anderen Nachrichtenaktionen
  // funktionierten. Historische Emoji-Werte werden weiterhin gelesen und beim
  // naechsten Umschalten sauber ersetzt.
  $token = $value === 'up' ? 'coreui_up' : ($value === 'down' ? 'coreui_down' : '');
  if ($token === '') throw new InvalidArgumentException('invalid_feedback');
  $sameLegacy = $value === 'up' ? '👍' : '👎';

  $ownsTransaction = !$pdo->inTransaction();
  if ($ownsTransaction) $pdo->beginTransaction();
  try {
    coreui_console_action_message($pdo, $uid, $sessionId, $messageId, true);
    $stLock = $pdo->prepare('SELECT id FROM stu_chat_messages WHERE id=? FOR UPDATE');
    $stLock->execute([$messageId]);
    if (!$stLock->fetchColumn()) throw new RuntimeException('message_not_found');
    $stExisting = $pdo->prepare(
      'SELECT id,emoji FROM stu_chat_reactions '
      . 'WHERE message_id=? AND user_id=? ORDER BY id ASC FOR UPDATE'
    );
    $stExisting->execute([$messageId, $uid]);
    $existing = 0;
    $feedbackIds = [];
    foreach (($stExisting->fetchAll(PDO::FETCH_ASSOC) ?: []) as $reaction) {
      $reactionId = (int)($reaction['id'] ?? 0);
      $reactionValue = (string)($reaction['emoji'] ?? '');
      if (!in_array($reactionValue, ['coreui_up', 'coreui_down', '👍', '👎'], true)) continue;
      if ($reactionId > 0) $feedbackIds[] = $reactionId;
      if ($reactionValue === $token || $reactionValue === $sameLegacy) $existing = $reactionId;
    }

    // Pro Benutzer und Ember-Antwort existiert hoechstens eine der beiden
    // CoreUI-Bewertungen. Auch Altwerte aus 0.5.0 werden mit entfernt.
    if ($feedbackIds !== []) {
      $deletePlaceholders = implode(',', array_fill(0, count($feedbackIds), '?'));
      $pdo->prepare("DELETE FROM stu_chat_reactions WHERE id IN ($deletePlaceholders)")
        ->execute($feedbackIds);
    }
    if ($existing > 0) {
      $selected = null;
    } else {
      $pdo->prepare(
        "INSERT INTO stu_chat_reactions
          (message_id,channel,alliance_id,user_id,character_id,emoji,created_at)
         VALUES (?,'console',NULL,?,?,?,NOW())"
      )->execute([$messageId, $uid, $characterId, $token]);
      $selected = $value;
    }
    if ($ownsTransaction) $pdo->commit();
    return $selected;
  } catch (Throwable $e) {
    if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }
}

function coreui_console_feedback_map(PDO $pdo, int $uid, array $messageIds): array {
  $ids = array_values(array_unique(array_filter(array_map('intval', $messageIds), static fn(int $id): bool => $id > 0)));
  if ($uid <= 0 || $ids === []) return [];
  $placeholders = implode(',', array_fill(0, count($ids), '?'));
  $st = $pdo->prepare(
    "SELECT message_id,emoji FROM stu_chat_reactions
      WHERE user_id=? AND message_id IN ($placeholders)
      ORDER BY id ASC"
  );
  $st->execute(array_merge([$uid], $ids));
  $out = [];
  foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
    $messageId = (int)($row['message_id'] ?? 0);
    $emoji = (string)($row['emoji'] ?? '');
    if ($messageId > 0 && in_array($emoji, ['coreui_up', 'coreui_down', '👍', '👎'], true)) {
      $out[$messageId] = in_array($emoji, ['coreui_up', '👍'], true)
        ? 'up'
        : (in_array($emoji, ['coreui_down', '👎'], true) ? 'down' : null);
    }
  }
  return $out;
}

function coreui_console_assert_idle_for_edit(
  PDO $pdo,
  int $uid,
  string $sessionId
): void {
  coreui_console_generation_expire($pdo, $uid);
  $stBusy = $pdo->prepare(
    "SELECT id FROM stu_console_generation_requests
      WHERE user_id=? AND session_id=? AND status IN ('issued','running') AND expires_at>=NOW()
      ORDER BY created_at DESC LIMIT 1"
  );
  $stBusy->execute([$uid, $sessionId]);
  if ($stBusy->fetchColumn()) throw new RuntimeException('generation_busy');

  $stBrowse = $pdo->prepare(
    "SELECT id FROM stu_ember_browse_jobs
      WHERE channel='console' AND recipient_uid=? AND session_id=? AND status IN ('queued','running')
      LIMIT 1"
  );
  $stBrowse->execute([$uid, $sessionId]);
  if ($stBrowse->fetchColumn()) throw new RuntimeException('session_busy');
}

function coreui_console_edit_prepare(
  PDO $pdo,
  int $uid,
  string $sessionId,
  int $messageId,
  string $revisedMessage
): array {
  if (!coreui_console_edits_schema_ready($pdo)) throw new RuntimeException('message_editing_migration_required');
  $sessionId = coreui_console_session_normalize_id($sessionId);
  $revisedMessage = chat_clean_console_message($revisedMessage);
  $ownsTransaction = !$pdo->inTransaction();
  if ($ownsTransaction) $pdo->beginTransaction();
  try {
    $stSessionLock = $pdo->prepare(
      'SELECT id FROM stu_console_sessions WHERE id=? AND user_id=? AND archived_at IS NULL FOR UPDATE'
    );
    $stSessionLock->execute([$sessionId, $uid]);
    if (!$stSessionLock->fetchColumn()) throw new RuntimeException('session_not_found');

    coreui_console_assert_idle_for_edit($pdo, $uid, $sessionId);
    $turn = coreui_console_action_message($pdo, $uid, $sessionId, $messageId, false);
    $previousMessage = chat_clean_console_message((string)($turn['message'] ?? ''));
    $attachmentIds = coreui_console_attachment_ids_for_message(
      $pdo,
      $messageId,
      $uid,
      isset($turn['file_uuid']) ? (string)$turn['file_uuid'] : null
    );
    $maxLen = $attachmentIds === [] ? 12000 : 20000;
    $messageLen = function_exists('mb_strlen')
      ? mb_strlen($revisedMessage, 'UTF-8')
      : strlen($revisedMessage);
    if (($messageLen === 0 && $attachmentIds === []) || $messageLen > $maxLen) {
      throw new InvalidArgumentException('invalid_message');
    }
    if ($attachmentIds === [] && $messageLen < 2) throw new InvalidArgumentException('too_short');
    if (hash_equals($previousMessage, $revisedMessage)) throw new InvalidArgumentException('message_unchanged');

    $stSource = $pdo->prepare(
      "SELECT id FROM stu_chat_messages
        WHERE channel='console' AND alliance_id IS NULL AND user_id=? AND session_id=?
          AND reply_to_id=? AND deleted_at IS NULL
          AND (LOWER(character_id)=? OR LOWER(character_name)=?)
        ORDER BY id DESC LIMIT 1 FOR UPDATE"
    );
    $stSource->execute([
      $uid,
      $sessionId,
      $messageId,
      strtolower(ember_character_id()),
      strtolower(ember_character_name()),
    ]);
    $sourceResponseId = (int)($stSource->fetchColumn() ?: 0);

    $stFloor = $pdo->prepare(
      "SELECT COALESCE(MAX(id),0) FROM stu_chat_messages
        WHERE channel='console' AND alliance_id IS NULL AND user_id=? AND session_id=?"
    );
    $stFloor->execute([$uid, $sessionId]);
    $responseFloorId = (int)($stFloor->fetchColumn() ?: 0);

    $stSupersede = $pdo->prepare(
      "UPDATE stu_chat_messages SET deleted_at=NOW()
        WHERE channel='console' AND alliance_id IS NULL AND user_id=? AND session_id=?
          AND id>? AND deleted_at IS NULL"
    );
    $stSupersede->execute([$uid, $sessionId, $messageId]);
    $supersededCount = $stSupersede->rowCount();

    $pdo->prepare(
      "DELETE r FROM stu_chat_reactions r
        JOIN stu_chat_messages m ON m.id=r.message_id
        WHERE m.channel='console' AND m.user_id=? AND m.session_id=? AND m.deleted_at IS NOT NULL"
    )->execute([$uid, $sessionId]);

    $pdo->prepare('UPDATE stu_chat_messages SET message=? WHERE id=? AND user_id=? LIMIT 1')
      ->execute([$revisedMessage, $messageId, $uid]);

    // Die zuvor gesperrte Sitzungszeile serialisiert Bearbeitungen bereits.
    // Ein Locking-Clause auf einer Aggregatabfrage ist je nach MariaDB-Version
    // uneinheitlich und wird deshalb hier bewusst nicht benoetigt.
    $stRevision = $pdo->prepare(
      'SELECT COALESCE(MAX(revision_no),0) FROM stu_console_message_revisions '
      . 'WHERE message_id=?'
    );
    $stRevision->execute([$messageId]);
    $revisionNo = (int)$stRevision->fetchColumn() + 1;
    $pdo->prepare(
      'INSERT INTO stu_console_message_revisions '
      . '(user_id,session_id,message_id,revision_no,previous_message,revised_message,superseded_message_count,created_at) '
      . 'VALUES (?,?,?,?,?,?,?,NOW())'
    )->execute([
      $uid,
      $sessionId,
      $messageId,
      $revisionNo,
      $previousMessage,
      $revisedMessage,
      $supersededCount,
    ]);

    $requestId = bin2hex(random_bytes(16));
    $pdo->prepare(
      "INSERT INTO stu_console_generation_requests
        (id,user_id,session_id,trigger_message_id,source_response_id,response_floor_id,mode,status,created_at,expires_at)
       VALUES (?,?,?,?,?,?,'edit','issued',NOW(),DATE_ADD(NOW(),INTERVAL 3 MINUTE))"
    )->execute([
      $requestId,
      $uid,
      $sessionId,
      $messageId,
      $sourceResponseId > 0 ? $sourceResponseId : $messageId,
      $responseFloorId,
    ]);

    $pdo->prepare(
      'UPDATE stu_console_sessions '
      . 'SET last_message_id=?,last_read_message_id=?,updated_at=NOW() '
      . 'WHERE id=? AND user_id=?'
    )->execute([$messageId, $messageId, $sessionId, $uid]);

    if ($ownsTransaction) $pdo->commit();
    return [
      'message_id' => $messageId,
      'message' => $revisedMessage,
      'revision_no' => $revisionNo,
      'superseded_message_count' => $supersededCount,
      'request' => [
        'id' => $requestId,
        'session_id' => $sessionId,
        'trigger_message_id' => $messageId,
        'source_response_id' => $sourceResponseId > 0 ? $sourceResponseId : $messageId,
        'mode' => 'edit',
        'status' => 'issued',
        'character_id' => (string)($turn['character_id'] ?? ''),
      ],
    ];
  } catch (Throwable $e) {
    if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }
}

function coreui_console_generation_expire(PDO $pdo, int $uid): void {
  $st = $pdo->prepare(
    "UPDATE stu_console_generation_requests
        SET status='expired',finished_at=NOW(),error_code='expired'
      WHERE user_id=? AND status IN ('issued','running') AND expires_at<NOW()"
  );
  $st->execute([$uid]);
}

function coreui_console_generation_prepare(
  PDO $pdo,
  int $uid,
  string $sessionId,
  int $sourceResponseId,
  string $mode
): array {
  if (!coreui_console_actions_schema_ready($pdo)) throw new RuntimeException('message_actions_migration_required');
  if (!in_array($mode, ['regenerate', 'continue'], true)) throw new InvalidArgumentException('invalid_generation_mode');
  $ownsTransaction = !$pdo->inTransaction();
  if ($ownsTransaction) $pdo->beginTransaction();
  try {
    $source = coreui_console_action_message($pdo, $uid, $sessionId, $sourceResponseId, true);
    $triggerId = (int)($source['reply_to_id'] ?? 0);
    if ($triggerId <= 0) throw new RuntimeException('source_turn_missing');
    coreui_console_action_message($pdo, $uid, $sessionId, $triggerId, false);

    // Die Sitzungszeile serialisiert konkurrierende Aktionsanforderungen. So
    // koennen auch zwei fast gleichzeitige Klicks nicht beide issued werden.
    $stSessionLock = $pdo->prepare(
      'SELECT id FROM stu_console_sessions WHERE id=? AND user_id=? AND archived_at IS NULL FOR UPDATE'
    );
    $stSessionLock->execute([$sessionId, $uid]);
    if (!$stSessionLock->fetchColumn()) throw new RuntimeException('session_not_found');

    coreui_console_generation_expire($pdo, $uid);
    $stBusy = $pdo->prepare(
      "SELECT id FROM stu_console_generation_requests
        WHERE user_id=? AND session_id=? AND status IN ('issued','running') AND expires_at>=NOW()
        ORDER BY created_at DESC LIMIT 1"
    );
    $stBusy->execute([$uid, $sessionId]);
    if ($stBusy->fetchColumn()) throw new RuntimeException('generation_busy');

    $stBrowse = $pdo->prepare(
      "SELECT id FROM stu_ember_browse_jobs
        WHERE channel='console' AND recipient_uid=? AND session_id=? AND status IN ('queued','running')
        LIMIT 1"
    );
    $stBrowse->execute([$uid, $sessionId]);
    if ($stBrowse->fetchColumn()) throw new RuntimeException('session_busy');

    $stFloor = $pdo->prepare(
      "SELECT COALESCE(MAX(id),0) FROM stu_chat_messages
        WHERE channel='console' AND alliance_id IS NULL AND user_id=? AND session_id=?"
    );
    $stFloor->execute([$uid, $sessionId]);
    $responseFloorId = (int)($stFloor->fetchColumn() ?: 0);

    $requestId = bin2hex(random_bytes(16));
    $st = $pdo->prepare(
      "INSERT INTO stu_console_generation_requests
        (id,user_id,session_id,trigger_message_id,source_response_id,response_floor_id,mode,status,created_at,expires_at)
       VALUES (?,?,?,?,?,?,?,'issued',NOW(),DATE_ADD(NOW(),INTERVAL 3 MINUTE))"
    );
    $st->execute([$requestId, $uid, $sessionId, $triggerId, $sourceResponseId, $responseFloorId, $mode]);
    if ($ownsTransaction) $pdo->commit();
    return [
      'id' => $requestId,
      'session_id' => $sessionId,
      'trigger_message_id' => $triggerId,
      'source_response_id' => $sourceResponseId,
      'mode' => $mode,
      'status' => 'issued',
    ];
  } catch (Throwable $e) {
    if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }
}

function coreui_console_generation_load(PDO $pdo, int $uid, string $requestId, bool $forUpdate = false): array {
  if (!preg_match('/^[a-f0-9]{32}$/', $requestId)) throw new InvalidArgumentException('invalid_generation_request');
  $sql = 'SELECT * FROM stu_console_generation_requests WHERE id=? AND user_id=? LIMIT 1';
  if ($forUpdate) $sql .= ' FOR UPDATE';
  $st = $pdo->prepare($sql);
  $st->execute([$requestId, $uid]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) throw new RuntimeException('generation_request_not_found');
  return $row;
}

function coreui_console_generation_mark_running(PDO $pdo, int $uid, string $requestId): array {
  $ownsTransaction = !$pdo->inTransaction();
  if ($ownsTransaction) $pdo->beginTransaction();
  try {
    $request = coreui_console_generation_load($pdo, $uid, $requestId, true);
    $status = (string)($request['status'] ?? '');
    if (in_array($status, ['issued', 'running'], true)
        && strtotime((string)($request['expires_at'] ?? '')) < time()) {
      $pdo->prepare(
        "UPDATE stu_console_generation_requests SET status='expired',finished_at=NOW(),error_code='expired' WHERE id=?"
      )->execute([$requestId]);
      if ($ownsTransaction) $pdo->commit();
      throw new RuntimeException('generation_request_expired');
    }
    if ($status === 'issued') {
      $pdo->prepare(
        "UPDATE stu_console_generation_requests
            SET status='running',started_at=NOW(),expires_at=DATE_ADD(NOW(),INTERVAL 40 MINUTE)
          WHERE id=?"
      )->execute([$requestId]);
      $request['status'] = 'running';
    }
    $request = coreui_console_generation_reconcile($pdo, $uid, $requestId, $request);
    if ($ownsTransaction) $pdo->commit();
    return $request;
  } catch (Throwable $e) {
    if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }
}

function coreui_console_generation_reconcile(
  PDO $pdo,
  int $uid,
  string $requestId,
  array $request
): array {
  if ((string)($request['status'] ?? '') !== 'running'
      || (int)($request['response_message_id'] ?? 0) > 0) {
    return $request;
  }
  $stResponse = $pdo->prepare(
    "SELECT id FROM stu_chat_messages
      WHERE channel='console' AND alliance_id IS NULL AND user_id=? AND session_id=?
        AND reply_to_id=? AND id>? AND deleted_at IS NULL
        AND (LOWER(character_id)=? OR LOWER(character_name)=?)
      ORDER BY id ASC LIMIT 1"
  );
  $stResponse->execute([
    $uid,
    (string)$request['session_id'],
    (int)$request['trigger_message_id'],
    (int)($request['response_floor_id'] ?? 0),
    strtolower(ember_character_id()),
    strtolower(ember_character_name()),
  ]);
  $responseId = (int)($stResponse->fetchColumn() ?: 0);
  if ($responseId <= 0) {
    $browseJobId = (int)($request['browse_job_id'] ?? 0);
    if ($browseJobId <= 0) return $request;
    $stBrowse = $pdo->prepare(
      "SELECT status FROM stu_ember_browse_jobs
        WHERE id=? AND channel='console' AND recipient_uid=? AND session_id=? AND trigger_message_id=?
        LIMIT 1"
    );
    $stBrowse->execute([
      $browseJobId,
      $uid,
      (string)$request['session_id'],
      (int)$request['trigger_message_id'],
    ]);
    $browseStatus = (string)($stBrowse->fetchColumn() ?: '');
    if (!in_array($browseStatus, ['done', 'error'], true)) return $request;
    $errorCode = $browseStatus === 'error' ? 'browse_failed' : 'browse_result_missing';
    coreui_console_generation_finish($pdo, $uid, $requestId, 0, $errorCode);
    $request['status'] = 'error';
    $request['error_code'] = $errorCode;
    return $request;
  }

  coreui_console_generation_finish($pdo, $uid, $requestId, $responseId);
  $request['status'] = 'done';
  $request['response_message_id'] = $responseId;
  $request['error_code'] = '';
  return $request;
}

function coreui_console_generation_attach_browse(
  PDO $pdo,
  int $uid,
  string $requestId,
  int $browseJobId
): void {
  if ($browseJobId <= 0) throw new InvalidArgumentException('invalid_browse_job');
  $st = $pdo->prepare(
    "UPDATE stu_console_generation_requests r
       JOIN stu_ember_browse_jobs j
         ON j.id=? AND j.channel='console' AND j.recipient_uid=r.user_id
        AND j.session_id=r.session_id AND j.trigger_message_id=r.trigger_message_id
        SET r.browse_job_id=j.id
      WHERE r.id=? AND r.user_id=? AND r.status='running'"
  );
  $st->execute([$browseJobId, $requestId, $uid]);
  if ($st->rowCount() === 1) return;
  $request = coreui_console_generation_load($pdo, $uid, $requestId);
  if ((int)($request['browse_job_id'] ?? 0) !== $browseJobId) {
    throw new RuntimeException('browse_job_binding_failed');
  }
}

function coreui_console_generation_finish(
  PDO $pdo,
  int $uid,
  string $requestId,
  int $responseMessageId,
  string $errorCode = ''
): void {
  $status = $responseMessageId > 0 && $errorCode === '' ? 'done' : 'error';
  $st = $pdo->prepare(
    'UPDATE stu_console_generation_requests
        SET status=?,response_message_id=?,error_code=?,finished_at=NOW()
      WHERE id=? AND user_id=?'
  );
  $st->execute([
    $status,
    $responseMessageId > 0 ? $responseMessageId : null,
    mb_substr($errorCode, 0, 64, 'UTF-8'),
    $requestId,
    $uid,
  ]);
}

function coreui_console_generation_metadata_map(PDO $pdo, int $uid, array $messageIds): array {
  $ids = array_values(array_unique(array_filter(array_map('intval', $messageIds), static fn(int $id): bool => $id > 0)));
  if ($uid <= 0 || $ids === [] || !coreui_console_actions_schema_ready($pdo)) return [];
  $placeholders = implode(',', array_fill(0, count($ids), '?'));
  $st = $pdo->prepare(
    "SELECT response_message_id,source_response_id,mode,finished_at
       FROM stu_console_generation_requests
      WHERE user_id=? AND status='done' AND response_message_id IN ($placeholders)"
  );
  $st->execute(array_merge([$uid], $ids));
  $out = [];
  foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
    $messageId = (int)($row['response_message_id'] ?? 0);
    if ($messageId <= 0) continue;
    $out[$messageId] = [
      'mode' => (string)($row['mode'] ?? ''),
      'source_response_id' => (int)($row['source_response_id'] ?? 0),
      'finished_at' => $row['finished_at'] ?? null,
    ];
  }
  return $out;
}
