SET NAMES utf8mb4;

ALTER TABLE stu_user_ai_settings
  ADD COLUMN IF NOT EXISTS thinking_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER memory_enabled;

CREATE TABLE IF NOT EXISTS stu_console_message_attachments (
  message_id BIGINT UNSIGNED NOT NULL,
  media_uuid VARCHAR(64) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  position TINYINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (message_id, media_uuid),
  UNIQUE KEY uq_console_message_attachment_position (message_id, position),
  KEY idx_console_message_attachment_user (user_id, message_id),
  KEY idx_console_message_attachment_media (media_uuid),
  CONSTRAINT chk_console_message_attachment_position CHECK (position BETWEEN 0 AND 9),
  CONSTRAINT fk_console_message_attachment_message
    FOREIGN KEY (message_id) REFERENCES stu_chat_messages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* Bestehende Einzelanhaenge verlustfrei in die neue 1:n-Zuordnung uebernehmen. */
INSERT IGNORE INTO stu_console_message_attachments
  (message_id, media_uuid, user_id, position, created_at)
SELECT m.id, m.file_uuid, m.user_id, 0, m.created_at
  FROM stu_chat_messages m
  JOIN stu_console_media cm ON cm.uuid=m.file_uuid AND cm.user_id=m.user_id
 WHERE m.channel='console' AND m.file_uuid IS NOT NULL AND m.file_uuid<>'';

INSERT INTO stu_schema_migrations (version)
VALUES ('005_thinking_attachments')
ON DUPLICATE KEY UPDATE applied_at=applied_at;
