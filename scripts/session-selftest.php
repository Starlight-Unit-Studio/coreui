<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];

function session_test_read(string $path): string {
  $data = @file_get_contents($path);
  return is_string($data) ? $data : '';
}

function session_test_expect(bool $condition, string $message): void {
  global $failures;
  if (!$condition) $failures[] = $message;
}

$migration = session_test_read($root . '/database/migrations/003_console_sessions.sql');
$attachmentMigration = session_test_read($root . '/database/migrations/005_thinking_attachments.sql');
$store = session_test_read($root . '/api/console_session_store.php');
$messages = session_test_read($root . '/api/console_messages.php');
$stream = session_test_read($root . '/api/console_stream.php');
$chat = session_test_read($root . '/api/chat.php');
$client = session_test_read($root . '/js/console-app.js');
$worker = session_test_read($root . '/tools/ember_browse_worker.py');
$sessions = session_test_read($root . '/api/console_sessions.php');

session_test_expect(str_contains($migration, '003_console_sessions'), 'Migration 003 fehlt.');
session_test_expect(str_contains($attachmentMigration, 'stu_console_message_attachments'), 'Migration 005 fuer Mehrfachanhaenge fehlt.');
session_test_expect(str_contains($migration, 'session_id VARCHAR(40)'), 'session_id-Migration fehlt.');
session_test_expect(str_contains($migration, 'reply_to_id BIGINT'), 'reply_to_id-Migration fehlt.');
session_test_expect(str_contains($migration, 'WIEDERHERGESTELLTER VERLAUF'), 'Legacy-Verlauf wird nicht erhalten.');
session_test_expect(str_contains($migration, 'MODIFY COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP'), 'Lesemarkierungen koennen die Sitzungsreihenfolge noch veraendern.');
session_test_expect(str_contains($store, 'archived_at IS NULL'), 'Soft-Archivierung fehlt.');
session_test_expect(!str_contains($sessions, 'ORDER BY created_at ASC LIMIT 1'), 'Automatische Loeschung der aeltesten Sitzung ist noch aktiv.');
session_test_expect(str_contains($store, 'function coreui_console_session_delete_tx'), 'Transaktionale SQL-Loeschung fehlt.');
session_test_expect(str_contains($store, "throw new RuntimeException('session_must_be_archived')"), 'Aktive Sitzungen koennen dauerhaft geloescht werden.');
session_test_expect(str_contains($store, "['queued', 'running']"), 'Laufende Browse-Jobs blockieren die Loeschung nicht.');
session_test_expect(str_contains($store, 'stu_console_generation_requests'), 'Laufende Nachrichtenaktionen blockieren die Loeschung nicht.');
session_test_expect(str_contains($store, 'DELETE r FROM stu_chat_reactions'), 'Reaktionen werden beim Sitzungsloeschen nicht entfernt.');
session_test_expect(str_contains($store, 'DELETE f FROM stu_ember_browse_frames'), 'Browserframes werden beim Sitzungsloeschen nicht entfernt.');
session_test_expect(str_contains($store, 'DELETE s FROM stu_ember_browse_steps'), 'Browserschritte werden beim Sitzungsloeschen nicht entfernt.');
session_test_expect(str_contains($store, 'DELETE FROM stu_ember_browse_jobs'), 'Browserjobs werden beim Sitzungsloeschen nicht entfernt.');
session_test_expect(str_contains($store, 'DELETE FROM stu_chat_messages'), 'Nachrichten werden beim Sitzungsloeschen nicht entfernt.');
session_test_expect(str_contains($store, 'DELETE FROM stu_console_media'), 'Verwaiste Upload-Datensaetze werden beim Sitzungsloeschen nicht entfernt.');
session_test_expect(str_contains($store, 'stu_console_message_attachments'), 'Mehrfachanhaenge werden beim Sitzungsloeschen nicht beruecksichtigt.');
session_test_expect(str_contains($store, "'uploads/ember_browse/job_'"), 'Browse-Screenshots werden beim Sitzungsloeschen nicht bereinigt.');
session_test_expect(str_contains($sessions, "hash_equals('DELETE:' . \$sessionId"), 'Serverseitige Loeschbestaetigung fehlt.');
session_test_expect(!str_contains($sessions, "\$action === 'delete' || \$action === 'archive'"), 'Delete ist noch faelschlich nur ein Archiv-Alias.');

session_test_expect(str_contains($messages, "m.user_id=? AND m.session_id=?"), 'History ist nicht exakt sitzungsgefiltert.');
session_test_expect(str_contains($stream, 'AND session_id = ? AND reply_to_id = ?'), 'Stream-Idempotenz ist nicht turngenau.');
session_test_expect(str_contains($stream, "coreui_console_session_require"), 'Stream prueft den Sitzungseigentuemer nicht.');
session_test_expect(str_contains($chat, "coreui_console_session_normalize_id(\$body['session_id']"), 'Sendepfad verlangt keine session_id.');
session_test_expect(str_contains($chat, "AND user_id=? AND session_id=?"), 'Modellkontext ist nicht sitzungsgebunden.');
session_test_expect(str_contains($chat, "\$cmdRes = (\$channel === 'console')"), 'Kanalweite Game-Kommandos sind in privaten Sitzungen noch aktiv.');
session_test_expect(str_contains($chat, "\$channel !== 'console' && \$cmdLine !== ''"), 'Generierte Game-Kommandos sind im Console-Fallback noch aktiv.');

session_test_expect(str_contains($client, 'activeSessionId'), 'Client verwendet keine stabile Sitzungs-ID.');
session_test_expect(!str_contains($client, 'activeIdx'), 'Client verwendet noch den instabilen Listenindex.');
session_test_expect(!str_contains($client, 'since - 80'), 'Client verwendet noch das fehlerhafte Demo-Verlaufsfenster.');
session_test_expect(str_contains($client, '/console_messages.php?session_id='), 'Client nutzt nicht den isolierten History-Endpunkt.');
session_test_expect(str_contains($client, 'session_id:   sessionId'), 'Client sendet keine session_id.');
session_test_expect(str_contains($client, 'MAX_MESSAGE_ATTACHMENTS = 10'), 'Client begrenzt Nachrichtenanhaenge nicht auf zehn.');
session_test_expect(str_contains($client, 'attachment_uuids:'), 'Client sendet die persistente Anhangliste nicht.');
session_test_expect(str_contains($client, "Number(m.reply_to_id) === Number(userMsgId)"), 'Client ordnet Antworten nicht dem exakten Turn zu.');
session_test_expect(str_contains($client, "Number(m.reply_to_id) === pendingTurnId"), 'Hintergrund-Poll kann noch eine fremde Antwort als eigenen Turn abschliessen.');
session_test_expect(str_contains($client, 'Der zuletzt bestaetigte Verlauf bleibt sichtbar.'), 'Ein Ladefehler kann den bestaetigten Verlauf noch optisch verdraengen.');
session_test_expect(str_contains($client, 'sessionArchiveToggle'), 'Archivansicht fehlt im Client.');
session_test_expect(str_contains($client, "action: 'restore'"), 'Wiederherstellung archivierter Sitzungen fehlt.');
session_test_expect(str_contains($client, "action: 'delete_permanently'"), 'Endgueltige Loeschaktion fehlt im Client.');
session_test_expect(str_contains($client, "permanentlyDelete.textContent = 'ENDGÜLTIG LÖSCHEN'"), 'Loeschschaltflaeche fehlt in der Archivansicht.');
session_test_expect(str_contains($client, "proof !== 'LÖSCHEN' && proof !== 'LOESCHEN'"), 'Zweite Loeschbestaetigung fehlt im Client.');
session_test_expect(str_contains($worker, 'trigger_message_id'), 'Browse-Worker verliert die Turn-Referenz.');
session_test_expect(str_contains($worker, 'session_id=session_id'), 'Browse-Worker verliert die Sitzungs-ID.');

$config = $root . '/api/config.local.php';
if (is_file($config)) {
  try {
    require_once $root . '/api/db.php';
    require_once $root . '/api/console_session_store.php';
    $pdo = stu_pdo();
    $pdo->query('SELECT last_message_id, last_read_message_id, archived_at FROM stu_console_sessions LIMIT 0');
    $pdo->query('SELECT session_id, reply_to_id FROM stu_chat_messages LIMIT 0');
    $pdo->query('SELECT session_id, trigger_message_id FROM stu_ember_browse_jobs LIMIT 0');
    $st = $pdo->prepare('SELECT COUNT(*) FROM stu_schema_migrations WHERE version=?');
    $st->execute(['003_console_sessions']);
    session_test_expect((int)$st->fetchColumn() === 1, 'Migration 003 ist in der Datenbank nicht registriert.');
    $st->execute(['005_thinking_attachments']);
    session_test_expect((int)$st->fetchColumn() === 1, 'Migration 005 ist in der Datenbank nicht registriert.');

    // Funktionaler DB-Test mit vollstaendigem Rollback. Keine Testzeile bleibt
    // in der produktiven Ember CoreUI-Datenbank bestehen.
    $pdo->beginTransaction();
    try {
      $suffix = bin2hex(random_bytes(6));
      $testUid = random_int(1500000000, 1900000000);
      $sessionA = 'cs_test_a_' . $suffix;
      $sessionB = 'cs_test_b_' . $suffix;
      $stSession = $pdo->prepare(
        'INSERT INTO stu_console_sessions '
        . '(id,user_id,title,since_id,last_message_id,last_read_message_id,created_at,updated_at,archived_at) '
        . 'VALUES (?,?,?,0,NULL,NULL,NOW(),?,NULL)'
      );
      $fixedUpdated = '2001-02-03 04:05:06';
      $stSession->execute([$sessionA, $testUid, 'SELFTEST A', $fixedUpdated]);
      $stSession->execute([$sessionB, $testUid, 'SELFTEST B', '2001-02-02 04:05:06']);

      $stMessage = $pdo->prepare(
        'INSERT INTO stu_chat_messages '
        . '(channel,alliance_id,session_id,user_id,character_id,character_name,message,reply_to_id,created_at) '
        . "VALUES ('console',NULL,?,?,?,?,?,?,NOW())"
      );
      $stMessage->execute([$sessionA, $testUid, 'selftest-user', 'Selftest', '@Ember A', null]);
      $turnA = (int)$pdo->lastInsertId();
      $stMessage->execute([$sessionA, $testUid, 'ember', 'Ember', 'Antwort A', $turnA]);
      $replyA = (int)$pdo->lastInsertId();
      $stMessage->execute([$sessionB, $testUid, 'selftest-user', 'Selftest', '@Ember B', null]);

      coreui_console_session_touch($pdo, $testUid, $sessionA, $replyA);
      $pdo->prepare('UPDATE stu_console_sessions SET updated_at=? WHERE id=?')
        ->execute([$fixedUpdated, $sessionA]);

      $listed = coreui_console_session_list($pdo, $testUid, false);
      $byId = [];
      foreach ($listed as $item) $byId[(string)$item['id']] = $item;
      session_test_expect((int)($byId[$sessionA]['message_count'] ?? -1) === 2, 'DB-Test: Sitzung A zaehlt fremde oder zu wenige Nachrichten.');
      session_test_expect((int)($byId[$sessionB]['message_count'] ?? -1) === 1, 'DB-Test: Sitzung B zaehlt fremde oder zu wenige Nachrichten.');

      coreui_console_session_mark_read($pdo, $testUid, $sessionA, $replyA);
      $stUpdated = $pdo->prepare('SELECT updated_at,last_read_message_id FROM stu_console_sessions WHERE id=?');
      $stUpdated->execute([$sessionA]);
      $readState = $stUpdated->fetch(PDO::FETCH_ASSOC) ?: [];
      session_test_expect((string)($readState['updated_at'] ?? '') === $fixedUpdated, 'DB-Test: Lesemarkierung sortiert die Sitzung um.');
      session_test_expect((int)($readState['last_read_message_id'] ?? 0) === $replyA, 'DB-Test: Lesemarkierung wurde nicht gespeichert.');

      $stExactReply = $pdo->prepare(
        "SELECT COUNT(*) FROM stu_chat_messages WHERE channel='console' AND user_id=? "
        . 'AND session_id=? AND reply_to_id=?'
      );
      $stExactReply->execute([$testUid, $sessionA, $turnA]);
      session_test_expect((int)$stExactReply->fetchColumn() === 1, 'DB-Test: Antwort ist nicht exakt dem Ursprungsturn zugeordnet.');
      $stExactReply->execute([$testUid, $sessionB, $turnA]);
      session_test_expect((int)$stExactReply->fetchColumn() === 0, 'DB-Test: Antwort ist in einer fremden Sitzung sichtbar.');

      coreui_console_session_archive($pdo, $testUid, $sessionA);
      $archivedHidden = false;
      try {
        coreui_console_session_require($pdo, $testUid, $sessionA);
      } catch (Throwable $eArchived) {
        $archivedHidden = true;
      }
      session_test_expect($archivedHidden, 'DB-Test: Archivierte Sitzung bleibt als aktiv abrufbar.');
      coreui_console_session_restore($pdo, $testUid, $sessionA);
      $restored = coreui_console_session_require($pdo, $testUid, $sessionA);
      session_test_expect(empty($restored['archived_at']), 'DB-Test: Sitzung wurde nicht wiederhergestellt.');

      $activeDeleteRejected = false;
      try {
        coreui_console_session_delete_tx($pdo, $testUid, $sessionA);
      } catch (RuntimeException $eActiveDelete) {
        $activeDeleteRejected = $eActiveDelete->getMessage() === 'session_must_be_archived';
      }
      session_test_expect($activeDeleteRejected, 'DB-Test: Eine aktive Sitzung konnte dauerhaft geloescht werden.');

      $mediaUuid = bin2hex(random_bytes(16));
      $mediaUuidTwo = bin2hex(random_bytes(16));
      $stMedia = $pdo->prepare(
        'INSERT INTO stu_console_media '
        . '(uuid,user_id,character_id,kind,orig_name,stored_name,rel_path,public_url,mime_type,file_size,created_at) '
        . "VALUES (?,?,?,'document','selftest.txt',?,? ,NULL,'text/plain',8,NOW())"
      );
      $storedName = $mediaUuid . '.txt';
      $storedNameTwo = $mediaUuidTwo . '.txt';
      $stMedia->execute([$mediaUuid, $testUid, 'selftest-user', $storedName, 'var/console_media/' . $storedName]);
      $stMedia->execute([$mediaUuidTwo, $testUid, 'selftest-user', $storedNameTwo, 'var/console_media/' . $storedNameTwo]);
      $pdo->prepare('UPDATE stu_chat_messages SET file_uuid=? WHERE id=?')->execute([$mediaUuid, $turnA]);
      $stMessageAttachment = $pdo->prepare(
        'INSERT INTO stu_console_message_attachments '
        . '(message_id,media_uuid,user_id,position,created_at) VALUES (?,?,?,?,NOW())'
      );
      $stMessageAttachment->execute([$turnA, $mediaUuid, $testUid, 0]);
      $stMessageAttachment->execute([$turnA, $mediaUuidTwo, $testUid, 1]);
      $stAttachmentCount = $pdo->prepare('SELECT COUNT(*) FROM stu_console_message_attachments WHERE message_id=?');
      $stAttachmentCount->execute([$turnA]);
      session_test_expect((int)$stAttachmentCount->fetchColumn() === 2, 'DB-Test: Mehrfachanhaenge wurden nicht gemeinsam gespeichert.');

      $stReaction = $pdo->prepare(
        "INSERT INTO stu_chat_reactions (message_id,channel,alliance_id,user_id,character_id,emoji,created_at)
         VALUES (?,'console',NULL,?,?,'ok',NOW())"
      );
      $stReaction->execute([$turnA, $testUid, 'selftest-user']);
      $reactionId = (int)$pdo->lastInsertId();

      $stJob = $pdo->prepare(
        "INSERT INTO stu_ember_browse_jobs
          (status,goal,max_steps,channel,recipient_uid,session_id,trigger_message_id,trigger_user_id,created_at)
         VALUES ('running','Sitzungs-Loeschtest',1,'console',?,?,?,?,NOW())"
      );
      $stJob->execute([$testUid, $sessionA, $turnA, $testUid]);
      $jobId = (int)$pdo->lastInsertId();
      $pdo->prepare(
        "INSERT INTO stu_ember_browse_steps (job_id,channel,recipient_uid,text,created_at)
         VALUES (?,'console',?,'Selftest-Schritt',NOW())"
      )->execute([$jobId, $testUid]);
      $pdo->prepare(
        "INSERT INTO stu_ember_browse_frames
          (job_id,channel,recipient_uid,step,b64,cursor_click,created_at)
         VALUES (?,'console',?,1,'c2VsZnRlc3Q=',0,NOW())"
      )->execute([$jobId, $testUid]);

      coreui_console_session_archive($pdo, $testUid, $sessionA);
      $busyDeleteRejected = false;
      try {
        coreui_console_session_delete_tx($pdo, $testUid, $sessionA);
      } catch (RuntimeException $eBusyDelete) {
        $busyDeleteRejected = $eBusyDelete->getMessage() === 'session_busy';
      }
      session_test_expect($busyDeleteRejected, 'DB-Test: Eine Sitzung mit laufendem Browse-Job konnte geloescht werden.');

      $pdo->prepare("UPDATE stu_ember_browse_jobs SET status='done',finished_at=NOW() WHERE id=?")
        ->execute([$jobId]);
      $deleteResult = coreui_console_session_delete_tx($pdo, $testUid, $sessionA);
      session_test_expect((int)($deleteResult['messages_deleted'] ?? -1) === 2, 'DB-Test: Sitzungsnachrichten wurden nicht vollstaendig geloescht.');
      session_test_expect((int)($deleteResult['reactions_deleted'] ?? -1) === 1, 'DB-Test: Sitzungsreaktion wurde nicht geloescht.');
      session_test_expect((int)($deleteResult['browse_jobs_deleted'] ?? -1) === 1, 'DB-Test: Browserjob wurde nicht geloescht.');
      session_test_expect((int)($deleteResult['browse_steps_deleted'] ?? -1) === 1, 'DB-Test: Browserschritt wurde nicht geloescht.');
      session_test_expect((int)($deleteResult['browse_frames_deleted'] ?? -1) === 1, 'DB-Test: Browserframe wurde nicht geloescht.');
      session_test_expect((int)($deleteResult['media_records_deleted'] ?? -1) === 2, 'DB-Test: Mehrere verwaiste Upload-Datensaetze wurden nicht geloescht.');
      $deleteFilePaths = is_array($deleteResult['file_paths'] ?? null) ? $deleteResult['file_paths'] : [];
      session_test_expect(in_array('var/console_media/' . $storedName, $deleteFilePaths, true), 'DB-Test: Verwaiste Upload-Datei wurde nicht zur Bereinigung vorgemerkt.');
      session_test_expect(in_array('var/console_media/' . $storedNameTwo, $deleteFilePaths, true), 'DB-Test: Zweite Upload-Datei wurde nicht zur Bereinigung vorgemerkt.');
      session_test_expect(in_array('uploads/ember_browse/job_' . $jobId . '.png', $deleteFilePaths, true), 'DB-Test: Browse-Screenshot wurde nicht zur Bereinigung vorgemerkt.');

      $stGone = $pdo->prepare('SELECT COUNT(*) FROM stu_console_sessions WHERE id=? AND user_id=?');
      $stGone->execute([$sessionA, $testUid]);
      session_test_expect((int)$stGone->fetchColumn() === 0, 'DB-Test: Sitzungszeile blieb nach der Loeschung erhalten.');
      $stGone->execute([$sessionB, $testUid]);
      session_test_expect((int)$stGone->fetchColumn() === 1, 'DB-Test: Fremde Sitzung wurde mitgeloescht.');

      $stMessageCount = $pdo->prepare("SELECT COUNT(*) FROM stu_chat_messages WHERE channel='console' AND user_id=? AND session_id=?");
      $stMessageCount->execute([$testUid, $sessionA]);
      session_test_expect((int)$stMessageCount->fetchColumn() === 0, 'DB-Test: Nachrichtenreste der geloeschten Sitzung sind vorhanden.');
      $stMessageCount->execute([$testUid, $sessionB]);
      session_test_expect((int)$stMessageCount->fetchColumn() === 1, 'DB-Test: Nachricht einer anderen Sitzung wurde mitgeloescht.');
      session_test_expect((int)$pdo->query('SELECT COUNT(*) FROM stu_chat_reactions WHERE id=' . $reactionId)->fetchColumn() === 0, 'DB-Test: Reaktion blieb erhalten.');
      session_test_expect((int)$pdo->query('SELECT COUNT(*) FROM stu_ember_browse_jobs WHERE id=' . $jobId)->fetchColumn() === 0, 'DB-Test: Browserjob blieb erhalten.');
      $stMediaGone = $pdo->prepare('SELECT COUNT(*) FROM stu_console_media WHERE uuid=?');
      $stMediaGone->execute([$mediaUuid]);
      session_test_expect((int)$stMediaGone->fetchColumn() === 0, 'DB-Test: Upload-Datensatz blieb erhalten.');
      $stMediaGone->execute([$mediaUuidTwo]);
      session_test_expect((int)$stMediaGone->fetchColumn() === 0, 'DB-Test: Zweiter Upload-Datensatz blieb erhalten.');
      $stAttachmentGone = $pdo->prepare('SELECT COUNT(*) FROM stu_console_message_attachments WHERE message_id=?');
      $stAttachmentGone->execute([$turnA]);
      session_test_expect((int)$stAttachmentGone->fetchColumn() === 0, 'DB-Test: Anhang-Zuordnung blieb erhalten.');
    } finally {
      if ($pdo->inTransaction()) $pdo->rollBack();
    }
  } catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    $failures[] = 'Sitzungsschema nicht bereit: ' . $e->getMessage();
  }
}

if ($failures) {
  fwrite(STDERR, "Sitzungs-Selftest fehlgeschlagen:\n- " . implode("\n- ", $failures) . "\n");
  exit(1);
}

echo 'Sitzungs-Selftest: echte Scopes, Turn-Zuordnung, Mehrfachanhaenge, Archiv-Restore, SQL-Loeschung und Client-Rennen abgesichert.';
