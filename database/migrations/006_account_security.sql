SET NAMES utf8mb4;

ALTER TABLE stu_users
  ADD COLUMN IF NOT EXISTS password_changed_at DATETIME NULL AFTER password_hash,
  ADD COLUMN IF NOT EXISTS last_login_at DATETIME NULL AFTER password_changed_at;

CREATE TABLE IF NOT EXISTS stu_auth_sessions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  device_label VARCHAR(96) NOT NULL DEFAULT 'Unbekanntes Geraet',
  user_agent VARCHAR(512) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  revoked_at DATETIME NULL,
  revoked_reason VARCHAR(64) NOT NULL DEFAULT '',
  PRIMARY KEY (id),
  UNIQUE KEY uq_auth_session_token (token_hash),
  KEY idx_auth_session_user_active (user_id, revoked_at, expires_at, last_seen_at),
  CONSTRAINT fk_auth_session_user
    FOREIGN KEY (user_id) REFERENCES stu_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO stu_schema_migrations (version)
VALUES ('006_account_security')
ON DUPLICATE KEY UPDATE applied_at=applied_at;
