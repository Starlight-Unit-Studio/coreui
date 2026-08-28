SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS stu_schema_migrations (
  version VARCHAR(32) NOT NULL,
  applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stu_users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  guest_key VARCHAR(128) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  username VARCHAR(254) NULL,
  password_hash VARCHAR(255) NULL,
  is_guest TINYINT(1) NOT NULL DEFAULT 1,
  permission_level TINYINT UNSIGNED NOT NULL DEFAULT 4,
  banned_until DATETIME NULL,
  banned_reason VARCHAR(255) NOT NULL DEFAULT '',
  root_entity_granted TINYINT(1) NOT NULL DEFAULT 0,
  root_entity_granted_at DATETIME NULL,
  root_entity_granted_by_user_id BIGINT UNSIGNED NULL,
  chat_seconds_lifetime BIGINT UNSIGNED NOT NULL DEFAULT 0,
  last_chat_counted_at DATETIME NULL,
  chat_rank_current VARCHAR(32) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_username (username),
  UNIQUE KEY uq_users_guest_key (guest_key),
  KEY idx_users_permission (permission_level)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stu_characters (
  id VARCHAR(64) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  alliance_id INT UNSIGNED NULL,
  name VARCHAR(32) NOT NULL,
  name_norm VARCHAR(32) NOT NULL,
  world_id INT NOT NULL DEFAULT 1,
  portrait_index INT NOT NULL DEFAULT 0,
  portrait_path VARCHAR(255) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  gender VARCHAR(8) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_character_name_norm (name_norm),
  KEY idx_character_user (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stu_kv (
  user_id BIGINT UNSIGNED NOT NULL,
  k VARCHAR(64) NOT NULL,
  value LONGTEXT NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, k),
  KEY idx_k (k)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stu_app_settings (
  k VARCHAR(96) NOT NULL,
  value LONGTEXT NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (k)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stu_password_resets (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) NULL,
  code_hash VARCHAR(255) NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  ip VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  last_sent_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_password_reset_user (user_id, created_at),
  KEY idx_password_reset_token (token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stu_console_sessions (
  id VARCHAR(40) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(64) NOT NULL DEFAULT 'Sitzung',
  since_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  last_message_id BIGINT UNSIGNED NULL,
  last_read_message_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  archived_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_console_session_user (user_id, updated_at),
  KEY idx_console_session_active (user_id, archived_at, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stu_chat_messages (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  channel ENUM('global','alliance','console') NOT NULL DEFAULT 'console',
  alliance_id INT UNSIGNED NULL,
  session_id VARCHAR(40) NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  character_id VARCHAR(64) NOT NULL,
  character_name VARCHAR(64) NOT NULL,
  message TEXT NOT NULL,
  thinking_content MEDIUMTEXT NULL,
  image_url VARCHAR(512) NULL,
  file_uuid VARCHAR(64) NULL,
  reply_to_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_chat_scope (channel, alliance_id, id),
  KEY idx_chat_console_user (channel, user_id, id),
  KEY idx_chat_console_session (channel, user_id, session_id, id),
  KEY idx_chat_console_reply (session_id, reply_to_id, id),
  KEY idx_chat_character (character_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stu_console_media (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid VARCHAR(64) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  character_id VARCHAR(64) NULL,
  kind VARCHAR(16) NOT NULL DEFAULT 'document',
  orig_name VARCHAR(255) NOT NULL,
  stored_name VARCHAR(255) NOT NULL,
  rel_path VARCHAR(512) NOT NULL,
  public_url VARCHAR(512) NULL,
  mime_type VARCHAR(128) NOT NULL,
  file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_console_media_uuid (uuid),
  KEY idx_console_media_user (user_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stu_chat_media (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid CHAR(36) NOT NULL,
  uploader_user_id BIGINT UNSIGNED NOT NULL,
  uploader_character_id VARCHAR(64) NOT NULL DEFAULT '',
  channel ENUM('global','alliance','console') NOT NULL DEFAULT 'console',
  alliance_id INT UNSIGNED NULL,
  filename VARCHAR(120) NOT NULL,
  mime_type VARCHAR(64) NOT NULL DEFAULT 'image/jpeg',
  file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_chat_media_uuid (uuid),
  KEY idx_chat_media_user (uploader_user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stu_chat_presence (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  channel ENUM('global','alliance','console') NOT NULL DEFAULT 'console',
  alliance_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  user_id BIGINT UNSIGNED NOT NULL,
  character_id VARCHAR(64) NOT NULL,
  character_name VARCHAR(64) NOT NULL,
  afk TINYINT(1) NOT NULL DEFAULT 0,
  last_active_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_presence (channel, alliance_id, user_id, character_id),
  KEY idx_presence_seen (channel, last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stu_chat_mutes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  channel VARCHAR(32) NOT NULL DEFAULT 'global',
  alliance_id INT UNSIGNED NULL,
  character_id VARCHAR(64) NOT NULL,
  character_name VARCHAR(64) NOT NULL DEFAULT '',
  muted_by_user_id BIGINT UNSIGNED NOT NULL,
  muted_by_character_id VARCHAR(64) NOT NULL,
  reason VARCHAR(255) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_mutes_active (character_id, channel, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stu_chat_reactions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  message_id BIGINT UNSIGNED NOT NULL,
  channel ENUM('global','alliance','console') NOT NULL DEFAULT 'console',
  alliance_id INT UNSIGNED NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  character_id VARCHAR(64) NOT NULL DEFAULT '',
  emoji VARCHAR(12) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_reaction (message_id, user_id, emoji),
  KEY idx_reaction_message (message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ember_knowledge_chunks (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  source VARCHAR(64) NOT NULL,
  title VARCHAR(255) NULL,
  chunk_text MEDIUMTEXT NOT NULL,
  chunk_no INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_knowledge_source_chunk (source, chunk_no),
  FULLTEXT KEY ft_knowledge_chunk (chunk_text)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ember_memories (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  fact TEXT NOT NULL,
  relevance INT NOT NULL DEFAULT 1,
  scope ENUM('global','user','character') NOT NULL DEFAULT 'global',
  user_id BIGINT UNSIGNED NULL,
  character_id VARCHAR(64) NULL,
  fact_hash CHAR(32) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  last_used_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_memory_scope (scope, user_id, character_id),
  KEY idx_memory_hash (fact_hash),
  FULLTEXT KEY ft_memory_fact (fact)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stu_ember_reputation (
  user_id BIGINT UNSIGNED NOT NULL,
  character_id VARCHAR(64) NOT NULL,
  score INT NOT NULL DEFAULT 0,
  last_delta INT NOT NULL DEFAULT 0,
  last_reason VARCHAR(255) NULL,
  turns_counted INT UNSIGNED NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, character_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stu_ember_browse_jobs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  status ENUM('queued','running','done','error') NOT NULL DEFAULT 'queued',
  goal TEXT NOT NULL,
  start_url VARCHAR(2048) NULL,
  max_steps INT NOT NULL DEFAULT 12,
  result MEDIUMTEXT NULL,
  steps_json MEDIUMTEXT NULL,
  screenshot_path VARCHAR(512) NULL,
  error TEXT NULL,
  worker_pid INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  started_at DATETIME NULL,
  finished_at DATETIME NULL,
  channel VARCHAR(32) NOT NULL DEFAULT 'console',
  recipient_uid BIGINT UNSIGNED NULL,
  session_id VARCHAR(40) NULL,
  trigger_message_id BIGINT UNSIGNED NULL,
  trigger_user_id BIGINT UNSIGNED NULL,
  PRIMARY KEY (id),
  KEY idx_browse_status (status, id),
  KEY idx_browse_recipient (channel, recipient_uid, id),
  KEY idx_browse_console_session (channel, recipient_uid, session_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stu_ember_browse_steps (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  job_id INT UNSIGNED NOT NULL,
  channel VARCHAR(32) NOT NULL DEFAULT 'console',
  recipient_uid BIGINT UNSIGNED NULL,
  text VARCHAR(800) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_browse_step_job (job_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stu_ember_browse_frames (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  job_id INT UNSIGNED NOT NULL,
  channel VARCHAR(32) NOT NULL DEFAULT 'console',
  recipient_uid BIGINT UNSIGNED NULL,
  step INT NOT NULL DEFAULT 0,
  b64 MEDIUMTEXT NOT NULL,
  cx FLOAT NULL,
  cy FLOAT NULL,
  vw INT NULL,
  vh INT NULL,
  cursor_click TINYINT(1) NOT NULL DEFAULT 0,
  page_url VARCHAR(2048) NULL,
  frame_label VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_browse_frame_job (job_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stu_ember_py_jobs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  status ENUM('queued','running','done','error') NOT NULL DEFAULT 'queued',
  code MEDIUMTEXT NOT NULL,
  stdout MEDIUMTEXT NULL,
  stderr MEDIUMTEXT NULL,
  exit_code INT NULL,
  duration_ms INT NULL,
  channel VARCHAR(32) NOT NULL DEFAULT 'console',
  trigger_user_id BIGINT UNSIGNED NULL,
  recipient_uid BIGINT UNSIGNED NULL,
  error TEXT NULL,
  worker_pid INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  started_at DATETIME NULL,
  finished_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_py_status (status, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stu_profile_photos (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  character_id VARCHAR(64) NOT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'pending',
  pending_relpath VARCHAR(255) NULL,
  approved_relpath VARCHAR(255) NULL,
  mime_type VARCHAR(64) NULL,
  file_size BIGINT UNSIGNED NULL,
  uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reviewed_at DATETIME NULL,
  reviewed_by_user_id BIGINT UNSIGNED NULL,
  review_note VARCHAR(255) NULL,
  PRIMARY KEY (id),
  KEY idx_profile_photo_character (character_id, uploaded_at),
  KEY idx_profile_photo_user (user_id, uploaded_at),
  KEY idx_profile_photo_status (status, uploaded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stu_user_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  event_type VARCHAR(64) NOT NULL,
  event_note TEXT NULL,
  event_value LONGTEXT NULL,
  meta_json LONGTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_user_log (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stu_alliances (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(64) NOT NULL,
  created_by_user_id BIGINT UNSIGNED NOT NULL,
  created_by_character_id VARCHAR(64) NOT NULL,
  max_members INT NOT NULL DEFAULT 50,
  honor_points INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stu_mail_threads (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  subject VARCHAR(120) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_message_id BIGINT UNSIGNED NULL,
  last_at DATETIME NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stu_mail_participants (
  thread_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  last_read_message_id BIGINT UNSIGNED NULL,
  deleted TINYINT(1) NOT NULL DEFAULT 0,
  joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (thread_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stu_mail_messages (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  thread_id BIGINT UNSIGNED NOT NULL,
  sender_user_id BIGINT UNSIGNED NOT NULL,
  sender_character_id VARCHAR(64) NULL,
  body TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_mail_thread (thread_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO stu_schema_migrations (version)
VALUES ('001_core')
ON DUPLICATE KEY UPDATE applied_at = applied_at;
