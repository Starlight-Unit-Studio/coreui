SET NAMES utf8mb4;

ALTER TABLE stu_console_sessions
  MODIFY COLUMN id VARCHAR(40) NOT NULL,
  MODIFY COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ADD COLUMN IF NOT EXISTS last_message_id BIGINT UNSIGNED NULL AFTER since_id,
  ADD COLUMN IF NOT EXISTS last_read_message_id BIGINT UNSIGNED NULL AFTER last_message_id,
  ADD COLUMN IF NOT EXISTS archived_at DATETIME NULL AFTER updated_at;

ALTER TABLE stu_console_sessions
  ADD INDEX IF NOT EXISTS idx_console_session_active (user_id, archived_at, updated_at);

ALTER TABLE stu_chat_messages
  ADD COLUMN IF NOT EXISTS session_id VARCHAR(40) NULL AFTER alliance_id,
  ADD COLUMN IF NOT EXISTS reply_to_id BIGINT UNSIGNED NULL AFTER file_uuid;

ALTER TABLE stu_chat_messages
  ADD INDEX IF NOT EXISTS idx_chat_console_session (channel, user_id, session_id, id),
  ADD INDEX IF NOT EXISTS idx_chat_console_reply (session_id, reply_to_id, id);

ALTER TABLE stu_ember_browse_jobs
  ADD COLUMN IF NOT EXISTS session_id VARCHAR(40) NULL AFTER recipient_uid,
  ADD COLUMN IF NOT EXISTS trigger_message_id BIGINT UNSIGNED NULL AFTER session_id;

ALTER TABLE stu_ember_browse_jobs
  ADD INDEX IF NOT EXISTS idx_browse_console_session (channel, recipient_uid, session_id, id);

/*
 * 0.3.0 und aelter besassen nur Zeiger-Sitzungen. Eine verlaessliche Trennung
 * der alten Nachrichten ist nicht mehr rekonstruierbar. Daher wird pro
 * Benutzer ein verlustfreier LEGACY-VERLAUF angelegt.
 */
INSERT INTO stu_console_sessions
  (id, user_id, title, since_id, last_message_id, last_read_message_id,
   created_at, updated_at, archived_at)
SELECT CONCAT('legacy_u_', LPAD(LOWER(HEX(m.user_id)), 20, '0')),
       m.user_id,
       'WIEDERHERGESTELLTER VERLAUF',
       MAX(m.id), MAX(m.id), MAX(m.id), MIN(m.created_at), MAX(m.created_at), NULL
  FROM stu_chat_messages m
 WHERE m.channel='console' AND m.session_id IS NULL
 GROUP BY m.user_id
ON DUPLICATE KEY UPDATE
  last_message_id=GREATEST(COALESCE(last_message_id,0),VALUES(last_message_id)),
  since_id=GREATEST(COALESCE(since_id,0),VALUES(since_id)),
  updated_at=GREATEST(updated_at,VALUES(updated_at)),
  archived_at=NULL;

UPDATE stu_chat_messages
   SET session_id=CONCAT('legacy_u_', LPAD(LOWER(HEX(user_id)), 20, '0'))
 WHERE channel='console' AND session_id IS NULL;

/* Alte Demo-Zeiger hatten keine echte Nachrichtenzuordnung und bleiben archiviert erhalten. */
UPDATE stu_console_sessions s
LEFT JOIN stu_chat_messages m
       ON m.channel='console' AND m.user_id=s.user_id AND m.session_id=s.id
   SET s.archived_at=COALESCE(s.archived_at,NOW())
 WHERE m.id IS NULL
   AND s.id NOT LIKE 'legacy_u_%';

UPDATE stu_console_sessions s
JOIN (
  SELECT session_id, user_id, MAX(id) AS max_id, MAX(created_at) AS max_created
    FROM stu_chat_messages
   WHERE channel='console' AND session_id IS NOT NULL
   GROUP BY session_id, user_id
) x ON x.session_id=s.id AND x.user_id=s.user_id
   SET s.last_message_id=x.max_id,
       s.since_id=GREATEST(COALESCE(s.since_id,0),x.max_id),
       s.updated_at=GREATEST(s.updated_at,x.max_created);

/* Bestehende Antworten bestmoeglich mit dem vorherigen User-Turn verknuepfen. */
UPDATE stu_chat_messages e
JOIN (
  SELECT e2.id AS ember_id, MAX(u.id) AS user_message_id
    FROM stu_chat_messages e2
    JOIN stu_chat_messages u
      ON u.channel='console'
     AND u.user_id=e2.user_id
     AND u.session_id=e2.session_id
     AND u.id<e2.id
     AND LOWER(u.character_name) NOT IN ('ember','system')
   WHERE e2.channel='console'
     AND e2.session_id IS NOT NULL
     AND LOWER(e2.character_name)='ember'
     AND e2.reply_to_id IS NULL
   GROUP BY e2.id
) paired ON paired.ember_id=e.id
   SET e.reply_to_id=paired.user_message_id
 WHERE e.reply_to_id IS NULL;

INSERT INTO stu_schema_migrations (version)
VALUES ('003_console_sessions')
ON DUPLICATE KEY UPDATE applied_at=applied_at;
