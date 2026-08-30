<?php
declare(strict_types=1);

require_once __DIR__ . '/console_attachment_store.php';

/**
 * Persistente Ember CoreUI-Sitzungslogik.
 *
 * Eine Sitzung ist ein echter Nachrichten-Scope. since_id bleibt nur als
 * Kompatibilitaetsfeld fuer alte Clients bestehen und definiert niemals mehr
 * die Zugehoerigkeit einer Nachricht.
 */

function coreui_console_session_normalize_id($value): string {
  $id = trim((string)$value);
  if ($id === '' || !preg_match('~^[A-Za-z0-9_-]{8,40}$~', $id)) return '';
  return $id;
}

function coreui_console_session_schema_ready(PDO $pdo): bool {
  try {
    $pdo->query('SELECT last_message_id, last_read_message_id, archived_at FROM stu_console_sessions LIMIT 1');
    $pdo->query('SELECT session_id, reply_to_id FROM stu_chat_messages LIMIT 1');
    return true;
  } catch (Throwable $e) {
    return false;
  }
}

function coreui_console_session_require(
  PDO $pdo,
  int $uid,
  string $sessionId,
  bool $allowArchived = false
): array {
  $sessionId = coreui_console_session_normalize_id($sessionId);
  if ($uid <= 0 || $sessionId === '') {
    throw new InvalidArgumentException('invalid_session');
  }
  $sql = 'SELECT id, user_id, title, since_id, last_message_id, last_read_message_id, '
    . 'created_at, updated_at, archived_at FROM stu_console_sessions '
    . 'WHERE id = ? AND user_id = ?';
  if (!$allowArchived) $sql .= ' AND archived_at IS NULL';
  $sql .= ' LIMIT 1';
  $st = $pdo->prepare($sql);
  $st->execute([$sessionId, $uid]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) throw new RuntimeException('session_not_found');
  return $row;
}

function coreui_console_session_list(PDO $pdo, int $uid, bool $includeArchived = false): array {
  $sql = "SELECT s.id, s.title, s.since_id, s.last_message_id, s.last_read_message_id,
                 s.created_at, s.updated_at, s.archived_at,
                 (SELECT COUNT(*) FROM stu_chat_messages m
                   WHERE m.channel='console' AND m.user_id=s.user_id
                     AND m.session_id=s.id AND m.deleted_at IS NULL) AS message_count,
                 (SELECT COUNT(*) FROM stu_chat_messages m
                   WHERE m.channel='console' AND m.user_id=s.user_id
                     AND m.session_id=s.id AND m.deleted_at IS NULL
                     AND m.id > COALESCE(s.last_read_message_id, 0)) AS unread_count
            FROM stu_console_sessions s
           WHERE s.user_id = ?";
  if (!$includeArchived) $sql .= ' AND s.archived_at IS NULL';
  $sql .= ' ORDER BY s.updated_at DESC, s.created_at DESC LIMIT 100';
  $st = $pdo->prepare($sql);
  $st->execute([$uid]);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  foreach ($rows as &$row) {
    $row['since_id'] = (int)($row['since_id'] ?? 0);
    $row['last_message_id'] = (int)($row['last_message_id'] ?? 0);
    $row['last_read_message_id'] = (int)($row['last_read_message_id'] ?? 0);
    $row['message_count'] = (int)($row['message_count'] ?? 0);
    $row['unread_count'] = (int)($row['unread_count'] ?? 0);
    $row['archived'] = !empty($row['archived_at']);
  }
  unset($row);
  return $rows;
}

function coreui_console_session_create(PDO $pdo, int $uid, string $title = ''): array {
  $stCount = $pdo->prepare('SELECT COUNT(*) FROM stu_console_sessions WHERE user_id=? AND archived_at IS NULL');
  $stCount->execute([$uid]);
  $count = (int)$stCount->fetchColumn();
  if ($count >= 100) throw new RuntimeException('session_limit_reached');

  $title = trim($title);
  if ($title === '') $title = 'SITZUNG ' . ($count + 1);
  if (function_exists('mb_substr')) $title = mb_substr($title, 0, 64, 'UTF-8');
  else $title = substr($title, 0, 64);

  $id = 'cs_' . bin2hex(random_bytes(12));
  $st = $pdo->prepare(
    'INSERT INTO stu_console_sessions '
    . '(id, user_id, title, since_id, last_message_id, last_read_message_id, created_at, updated_at, archived_at) '
    . 'VALUES (?, ?, ?, 0, NULL, NULL, NOW(), NOW(), NULL)'
  );
  $st->execute([$id, $uid, $title]);
  return [
    'id' => $id,
    'title' => $title,
    'since_id' => 0,
    'last_message_id' => 0,
    'last_read_message_id' => 0,
    'message_count' => 0,
    'unread_count' => 0,
    'created_at' => gmdate('c'),
    'updated_at' => gmdate('c'),
    'archived' => false,
  ];
}

function coreui_console_session_touch(PDO $pdo, int $uid, string $sessionId, int $messageId): void {
  if ($messageId <= 0) return;
  $st = $pdo->prepare(
    'UPDATE stu_console_sessions '
    . 'SET last_message_id=GREATEST(COALESCE(last_message_id,0),?), '
    . 'since_id=GREATEST(COALESCE(since_id,0),?), updated_at=NOW() '
    . 'WHERE id=? AND user_id=?'
  );
  $st->execute([$messageId, $messageId, $sessionId, $uid]);
}

function coreui_console_session_mark_read(PDO $pdo, int $uid, string $sessionId, int $messageId): void {
  if ($messageId <= 0) return;
  $st = $pdo->prepare(
    'UPDATE stu_console_sessions '
    . 'SET last_read_message_id=GREATEST(COALESCE(last_read_message_id,0),?) '
    . 'WHERE id=? AND user_id=?'
  );
  $st->execute([$messageId, $sessionId, $uid]);
}

function coreui_console_session_rename(PDO $pdo, int $uid, string $sessionId, string $title): string {
  $title = trim((string)preg_replace('~\s+~u', ' ', $title));
  if ($title === '') throw new InvalidArgumentException('invalid_title');
  if (function_exists('mb_substr')) $title = mb_substr($title, 0, 64, 'UTF-8');
  else $title = substr($title, 0, 64);
  $st = $pdo->prepare(
    'UPDATE stu_console_sessions SET title=? WHERE id=? AND user_id=? AND archived_at IS NULL'
  );
  $st->execute([$title, $sessionId, $uid]);
  if ($st->rowCount() < 1) coreui_console_session_require($pdo, $uid, $sessionId);
  return $title;
}

function coreui_console_session_auto_title(
  PDO $pdo,
  int $uid,
  string $sessionId,
  string $message
): string {
  $session = coreui_console_session_require($pdo, $uid, $sessionId, true);
  $current = trim((string)($session['title'] ?? ''));
  if ($current !== '' && !preg_match('~^(?:SITZUNG\s+\d+|NEUE SITZUNG)$~iu', $current)) return $current;

  $title = preg_replace('~^@ember\s*~iu', '', trim($message));
  $title = preg_replace('~\[(?:img|file):[^\]]+\]~iu', ' ', (string)$title);
  $title = trim((string)preg_replace('~\s+~u', ' ', (string)$title));
  if ($title === '') $title = 'ANHANG';
  $max = 52;
  $len = function_exists('mb_strlen') ? mb_strlen($title, 'UTF-8') : strlen($title);
  if ($len > $max) {
    $title = function_exists('mb_substr') ? mb_substr($title, 0, $max - 1, 'UTF-8') : substr($title, 0, $max - 1);
    $title = rtrim($title) . '…';
  }
  $st = $pdo->prepare(
    'UPDATE stu_console_sessions SET title=? WHERE id=? AND user_id=? AND title=?'
  );
  $st->execute([$title, $sessionId, $uid, $current]);
  return $st->rowCount() > 0 ? $title : $current;
}

function coreui_console_session_archive(PDO $pdo, int $uid, string $sessionId): void {
  coreui_console_session_require($pdo, $uid, $sessionId);
  $st = $pdo->prepare(
    'UPDATE stu_console_sessions SET archived_at=NOW() WHERE id=? AND user_id=? AND archived_at IS NULL'
  );
  $st->execute([$sessionId, $uid]);
}

function coreui_console_session_restore(PDO $pdo, int $uid, string $sessionId): void {
  coreui_console_session_require($pdo, $uid, $sessionId, true);
  $st = $pdo->prepare(
    'UPDATE stu_console_sessions SET archived_at=NULL, updated_at=NOW() WHERE id=? AND user_id=?'
  );
  $st->execute([$sessionId, $uid]);
}

/**
 * Entfernt eine archivierte Sitzung und alle eindeutig zugeordneten SQL-Daten.
 *
 * Die Funktion erwartet eine bereits gestartete Transaktion. Dadurch kann der
 * Selftest dieselbe produktive Loeschlogik mit Rollback pruefen, waehrend der
 * oeffentliche Wrapper Commit und anschliessende Dateibereinigung uebernimmt.
 */
function coreui_console_session_delete_tx(PDO $pdo, int $uid, string $sessionId): array {
  if (!$pdo->inTransaction()) throw new LogicException('delete_transaction_required');

  $sessionId = coreui_console_session_normalize_id($sessionId);
  if ($uid <= 0 || $sessionId === '') throw new InvalidArgumentException('invalid_session');

  $stSession = $pdo->prepare(
    'SELECT id, archived_at FROM stu_console_sessions WHERE id=? AND user_id=? LIMIT 1 FOR UPDATE'
  );
  $stSession->execute([$sessionId, $uid]);
  $session = $stSession->fetch(PDO::FETCH_ASSOC);
  if (!$session) throw new RuntimeException('session_not_found');
  if (empty($session['archived_at'])) throw new RuntimeException('session_must_be_archived');

  // Alle zugeordneten Jobs sperren. Ein laufender oder noch nicht abgeholter
  // Worker-Job darf niemals unter dem Worker geloescht werden.
  $stJobs = $pdo->prepare(
    "SELECT id, status FROM stu_ember_browse_jobs
      WHERE channel='console' AND recipient_uid=? AND session_id=?
      FOR UPDATE"
  );
  $stJobs->execute([$uid, $sessionId]);
  $jobs = $stJobs->fetchAll(PDO::FETCH_ASSOC) ?: [];
  $browseScreenshotPaths = [];
  foreach ($jobs as $job) {
    if (in_array((string)($job['status'] ?? ''), ['queued', 'running'], true)) {
      throw new RuntimeException('session_busy');
    }
    $jobId = (int)($job['id'] ?? 0);
    if ($jobId > 0) $browseScreenshotPaths[] = 'uploads/ember_browse/job_' . $jobId . '.png';
  }

  // Nur Medien erfassen, die dem Benutzer gehoeren und in genau dieser
  // Sitzung referenziert werden. Ob sie nachher wirklich verwaist sind, wird
  // nach dem Nachrichten-DELETE nochmals innerhalb derselben Transaktion
  // geprueft.
  $stMedia = $pdo->prepare(
    "SELECT DISTINCT cm.uuid, cm.rel_path
       FROM stu_console_media cm
       JOIN (
         SELECT m.file_uuid AS media_uuid
           FROM stu_chat_messages m
          WHERE m.channel='console' AND m.user_id=? AND m.session_id=?
            AND m.file_uuid IS NOT NULL AND m.file_uuid<>''
         UNION
         SELECT a.media_uuid
           FROM stu_console_message_attachments a
           JOIN stu_chat_messages m ON m.id=a.message_id
          WHERE m.channel='console' AND m.user_id=? AND m.session_id=?
       ) refs ON refs.media_uuid=cm.uuid
      WHERE cm.user_id=?"
  );
  $stMedia->execute([$uid, $sessionId, $uid, $sessionId, $uid]);
  $mediaCandidates = $stMedia->fetchAll(PDO::FETCH_ASSOC) ?: [];

  $counts = [
    'reactions_deleted' => 0,
    'browse_frames_deleted' => 0,
    'browse_steps_deleted' => 0,
    'browse_jobs_deleted' => 0,
    'messages_deleted' => 0,
    'media_records_deleted' => 0,
  ];

  $stReactions = $pdo->prepare(
    "DELETE r FROM stu_chat_reactions r
       JOIN stu_chat_messages m ON m.id=r.message_id
      WHERE m.channel='console' AND m.user_id=? AND m.session_id=?"
  );
  $stReactions->execute([$uid, $sessionId]);
  $counts['reactions_deleted'] = $stReactions->rowCount();

  $stFrames = $pdo->prepare(
    "DELETE f FROM stu_ember_browse_frames f
       JOIN stu_ember_browse_jobs j ON j.id=f.job_id
      WHERE j.channel='console' AND j.recipient_uid=? AND j.session_id=?"
  );
  $stFrames->execute([$uid, $sessionId]);
  $counts['browse_frames_deleted'] = $stFrames->rowCount();

  $stSteps = $pdo->prepare(
    "DELETE s FROM stu_ember_browse_steps s
       JOIN stu_ember_browse_jobs j ON j.id=s.job_id
      WHERE j.channel='console' AND j.recipient_uid=? AND j.session_id=?"
  );
  $stSteps->execute([$uid, $sessionId]);
  $counts['browse_steps_deleted'] = $stSteps->rowCount();

  $stDeleteJobs = $pdo->prepare(
    "DELETE FROM stu_ember_browse_jobs
      WHERE channel='console' AND recipient_uid=? AND session_id=?"
  );
  $stDeleteJobs->execute([$uid, $sessionId]);
  $counts['browse_jobs_deleted'] = $stDeleteJobs->rowCount();

  $stDeleteMessages = $pdo->prepare(
    "DELETE FROM stu_chat_messages
      WHERE channel='console' AND user_id=? AND session_id=?"
  );
  $stDeleteMessages->execute([$uid, $sessionId]);
  $counts['messages_deleted'] = $stDeleteMessages->rowCount();

  $orphanedMediaPaths = [];
  $stMediaRefs = $pdo->prepare(
    'SELECT '
    . '(SELECT COUNT(*) FROM stu_chat_messages WHERE file_uuid=?) + '
    . '(SELECT COUNT(*) FROM stu_console_message_attachments WHERE media_uuid=?)'
  );
  $stDeleteMedia = $pdo->prepare('DELETE FROM stu_console_media WHERE uuid=? AND user_id=? LIMIT 1');
  foreach ($mediaCandidates as $media) {
    $uuid = trim((string)($media['uuid'] ?? ''));
    if ($uuid === '') continue;
    $stMediaRefs->execute([$uuid, $uuid]);
    if ((int)$stMediaRefs->fetchColumn() > 0) continue;
    $stDeleteMedia->execute([$uuid, $uid]);
    if ($stDeleteMedia->rowCount() > 0) {
      $counts['media_records_deleted']++;
      $orphanedMediaPaths[] = (string)($media['rel_path'] ?? '');
    }
  }

  $stDeleteSession = $pdo->prepare(
    'DELETE FROM stu_console_sessions WHERE id=? AND user_id=? AND archived_at IS NOT NULL LIMIT 1'
  );
  $stDeleteSession->execute([$sessionId, $uid]);
  if ($stDeleteSession->rowCount() !== 1) throw new RuntimeException('session_delete_conflict');

  return $counts + ['file_paths' => array_merge($orphanedMediaPaths, $browseScreenshotPaths)];
}

/**
 * Loescht ausschliesslich generierte Console-Dateien unter den bekannten
 * Medien- und Browse-Wurzeln. Traversal, Symlinks nach ausserhalb und
 * beliebige Datenbankpfade werden verworfen.
 */
function coreui_console_session_delete_media_files(array $relativePaths): array {
  $root = dirname(__DIR__);
  $allowedPrefixes = ['var/console_media/', 'assets/chat_media/', 'uploads/ember_browse/'];
  $deleted = 0;
  $missing = 0;
  $failed = 0;

  foreach (array_values(array_unique($relativePaths)) as $relativePath) {
    $relativePath = str_replace('\\', '/', trim((string)$relativePath));
    if ($relativePath === '' || preg_match('~[\x00-\x1F\x7F]~', $relativePath)) {
      $failed++;
      continue;
    }
    $allowed = false;
    foreach ($allowedPrefixes as $prefix) {
      if (str_starts_with($relativePath, $prefix)) {
        $tail = substr($relativePath, strlen($prefix));
        $allowed = $tail !== '' && basename($tail) === $tail;
        break;
      }
    }
    if (!$allowed) {
      $failed++;
      continue;
    }

    $path = $root . '/' . $relativePath;
    if (!@file_exists($path)) {
      $missing++;
      continue;
    }
    $realPath = @realpath($path);
    $basePath = @realpath(dirname($path));
    if ($realPath === false || $basePath === false || dirname($realPath) !== $basePath || !@is_file($realPath)) {
      $failed++;
      continue;
    }
    if (@unlink($realPath)) $deleted++;
    else $failed++;
  }

  return ['files_deleted'=>$deleted, 'files_missing'=>$missing, 'files_failed'=>$failed];
}

function coreui_console_session_delete(PDO $pdo, int $uid, string $sessionId): array {
  if ($pdo->inTransaction()) throw new LogicException('delete_nested_transaction');
  $pdo->beginTransaction();
  try {
    $result = coreui_console_session_delete_tx($pdo, $uid, $sessionId);
    $pdo->commit();
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }

  $paths = is_array($result['file_paths'] ?? null) ? $result['file_paths'] : [];
  unset($result['file_paths']);
  $files = coreui_console_session_delete_media_files($paths);
  return $result + $files;
}
