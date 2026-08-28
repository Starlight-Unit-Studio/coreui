SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS stu_user_ai_settings (
  user_id BIGINT UNSIGNED NOT NULL,
  system_prompt MEDIUMTEXT NULL,
  memory_enabled TINYINT(1) NOT NULL DEFAULT 1,
  memory_limit TINYINT UNSIGNED NOT NULL DEFAULT 16,
  num_predict INT UNSIGNED NOT NULL DEFAULT 6500,
  temperature DECIMAL(4,2) NOT NULL DEFAULT 1.00,
  provider VARCHAR(32) NOT NULL DEFAULT 'local',
  model_override VARCHAR(160) NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  CONSTRAINT fk_user_ai_settings_user
    FOREIGN KEY (user_id) REFERENCES stu_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stu_admin_audit (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  actor_user_id BIGINT UNSIGNED NOT NULL,
  action_name VARCHAR(96) NOT NULL,
  target_type VARCHAR(48) NOT NULL DEFAULT '',
  target_id VARCHAR(96) NOT NULL DEFAULT '',
  detail_json LONGTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_admin_audit_actor (actor_user_id, created_at),
  KEY idx_admin_audit_action (action_name, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE stu_chat_messages MODIFY COLUMN message MEDIUMTEXT NOT NULL;

INSERT INTO stu_app_settings (k, value, updated_at) VALUES
  ('registration_enabled', '0', NOW()),
  ('maintenance_enabled', '0', NOW()),
  ('maintenance_message', 'Ember CoreUI wird gerade gewartet. Bitte versuche es später erneut.', NOW()),
  ('memory_default_enabled', '1', NOW()),
  ('user_system_prompt_max_chars', '6000', NOW()),
  ('tool_web_enabled', '1', NOW()),
  ('tool_browse_enabled', '1', NOW()),
  ('tool_python_enabled', '1', NOW()),
  ('external_api_enabled', '0', NOW()),
  ('external_api_label', 'OpenAI-kompatibel', NOW()),
  ('external_api_base_url', '', NOW()),
  ('external_api_model', '', NOW())
ON DUPLICATE KEY UPDATE k = VALUES(k);

INSERT INTO stu_schema_migrations (version)
VALUES ('002_coreui_management')
ON DUPLICATE KEY UPDATE applied_at = applied_at;
