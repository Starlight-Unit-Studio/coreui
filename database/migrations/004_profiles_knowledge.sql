SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS stu_coreui_profiles (
  user_id BIGINT UNSIGNED NOT NULL,
  display_name VARCHAR(64) NOT NULL,
  assistant_name VARCHAR(64) NOT NULL DEFAULT 'Ember',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  CONSTRAINT fk_coreui_profile_user
    FOREIGN KEY (user_id) REFERENCES stu_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stu_coreui_profile_media (
  uuid CHAR(32) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  slot ENUM('user','assistant') NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  stored_name VARCHAR(96) NOT NULL,
  mime_type VARCHAR(64) NOT NULL DEFAULT 'image/png',
  file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
  width_px INT UNSIGNED NOT NULL DEFAULT 0,
  height_px INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (uuid),
  UNIQUE KEY uq_coreui_profile_media_slot (user_id, slot),
  KEY idx_coreui_profile_media_user (user_id, created_at),
  CONSTRAINT fk_coreui_profile_media_user
    FOREIGN KEY (user_id) REFERENCES stu_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stu_user_knowledge_sources (
  uuid CHAR(32) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(160) NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  stored_name VARCHAR(96) NOT NULL,
  mime_type VARCHAR(128) NOT NULL,
  file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
  char_count INT UNSIGNED NOT NULL DEFAULT 0,
  chunk_count INT UNSIGNED NOT NULL DEFAULT 0,
  status ENUM('processing','ready','error') NOT NULL DEFAULT 'processing',
  error_message VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (uuid),
  KEY idx_user_knowledge_sources (user_id, updated_at),
  CONSTRAINT fk_user_knowledge_source_user
    FOREIGN KEY (user_id) REFERENCES stu_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stu_user_knowledge_chunks (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  source_uuid CHAR(32) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(160) NOT NULL,
  chunk_no INT UNSIGNED NOT NULL,
  chunk_text MEDIUMTEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_user_knowledge_chunk (source_uuid, chunk_no),
  KEY idx_user_knowledge_chunks_user (user_id, source_uuid, chunk_no),
  FULLTEXT KEY ft_user_knowledge_chunk (chunk_text),
  CONSTRAINT fk_user_knowledge_chunk_source
    FOREIGN KEY (source_uuid) REFERENCES stu_user_knowledge_sources(uuid) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO stu_app_settings (k, value, updated_at) VALUES
  ('knowledge_max_sources_per_user', '40', NOW()),
  ('knowledge_max_file_mb', '20', NOW()),
  ('knowledge_max_total_chars_per_user', '5000000', NOW())
ON DUPLICATE KEY UPDATE k = VALUES(k);

INSERT INTO stu_schema_migrations (version) VALUES ('004_profiles_knowledge')
ON DUPLICATE KEY UPDATE version = VALUES(version);
