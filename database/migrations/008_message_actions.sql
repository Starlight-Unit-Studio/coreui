SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS stu_console_generation_requests (
  id CHAR(32) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  session_id VARCHAR(40) NOT NULL,
  trigger_message_id BIGINT UNSIGNED NOT NULL,
  source_response_id BIGINT UNSIGNED NOT NULL,
  response_floor_id BIGINT UNSIGNED NOT NULL,
  mode ENUM('regenerate','continue') NOT NULL,
  status ENUM('issued','running','done','error','expired') NOT NULL DEFAULT 'issued',
  response_message_id BIGINT UNSIGNED NULL,
  browse_job_id INT UNSIGNED NULL,
  error_code VARCHAR(64) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  started_at DATETIME NULL,
  finished_at DATETIME NULL,
  expires_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_console_generation_user_session (user_id, session_id, status, created_at),
  KEY idx_console_generation_response (response_message_id),
  KEY idx_console_generation_browse (browse_job_id),
  CONSTRAINT fk_console_generation_user
    FOREIGN KEY (user_id) REFERENCES stu_users(id) ON DELETE CASCADE,
  CONSTRAINT fk_console_generation_session
    FOREIGN KEY (session_id) REFERENCES stu_console_sessions(id) ON DELETE CASCADE,
  CONSTRAINT fk_console_generation_trigger
    FOREIGN KEY (trigger_message_id) REFERENCES stu_chat_messages(id) ON DELETE CASCADE,
  CONSTRAINT fk_console_generation_source
    FOREIGN KEY (source_response_id) REFERENCES stu_chat_messages(id) ON DELETE CASCADE,
  CONSTRAINT fk_console_generation_response
    FOREIGN KEY (response_message_id) REFERENCES stu_chat_messages(id) ON DELETE SET NULL,
  CONSTRAINT fk_console_generation_browse
    FOREIGN KEY (browse_job_id) REFERENCES stu_ember_browse_jobs(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO stu_schema_migrations (version)
VALUES ('008_message_actions')
ON DUPLICATE KEY UPDATE applied_at=applied_at;
