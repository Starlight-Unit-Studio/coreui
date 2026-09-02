SET NAMES utf8mb4;

ALTER TABLE stu_chat_reactions
  MODIFY channel ENUM('global','alliance','console') NOT NULL DEFAULT 'console',
  MODIFY emoji VARCHAR(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL;

ALTER TABLE stu_console_generation_requests
  MODIFY mode ENUM('regenerate','continue','edit') NOT NULL;

CREATE TABLE IF NOT EXISTS stu_console_message_revisions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  session_id VARCHAR(40) NOT NULL,
  message_id BIGINT UNSIGNED NOT NULL,
  revision_no INT UNSIGNED NOT NULL,
  previous_message TEXT NOT NULL,
  revised_message TEXT NOT NULL,
  superseded_message_count INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_console_message_revision (message_id, revision_no),
  KEY idx_console_revision_user_session (user_id, session_id, created_at),
  CONSTRAINT fk_console_revision_user
    FOREIGN KEY (user_id) REFERENCES stu_users(id) ON DELETE CASCADE,
  CONSTRAINT fk_console_revision_session
    FOREIGN KEY (session_id) REFERENCES stu_console_sessions(id) ON DELETE CASCADE,
  CONSTRAINT fk_console_revision_message
    FOREIGN KEY (message_id) REFERENCES stu_chat_messages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO stu_schema_migrations (version)
VALUES ('009_message_editing')
ON DUPLICATE KEY UPDATE applied_at=applied_at;
