<?php
declare(strict_types=1);

/* Ember CoreUI standalone configuration loader.
   Priority: existing constants, api/config.local.php, environment, defaults. */

$localConfig = __DIR__ . '/config.local.php';
if (is_file($localConfig)) {
  require_once $localConfig;
}

function coreui_env(string $key, string $default = ''): string {
  $value = getenv($key);
  return ($value === false || $value === '') ? $default : (string)$value;
}

function coreui_env_bool(string $key, bool $default): bool {
  $value = getenv($key);
  if ($value === false || $value === '') return $default;
  return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
}

function coreui_define(string $key, $value): void {
  if (!defined($key)) define($key, $value);
}

coreui_define('STU_DB_HOST', coreui_env('COREUI_DB_HOST', '127.0.0.1'));
coreui_define('STU_DB_PORT', (int)coreui_env('COREUI_DB_PORT', '13306'));
coreui_define('STU_DB_NAME', coreui_env('COREUI_DB_NAME', 'ember_coreui'));
coreui_define('STU_DB_USER', coreui_env('COREUI_DB_USER', 'ember_coreui'));
coreui_define('STU_DB_PASS', coreui_env('COREUI_DB_PASS', 'change_me'));

coreui_define('STU_DEBUG', coreui_env_bool('COREUI_DEBUG', false));
coreui_define('STU_MAX_VALUE_BYTES', 1024 * 1024);
coreui_define('STU_ALLOW_REGISTRATION', coreui_env_bool('COREUI_ALLOW_REGISTRATION', false));
coreui_define('STU_SESSION_NAME', coreui_env('COREUI_SESSION_NAME', 'EMBERCOREUISESSID'));
coreui_define('STU_SESSION_COOKIE_PATH', coreui_env('COREUI_COOKIE_PATH'));

coreui_define('STU_MAIL_ENABLED', coreui_env_bool('COREUI_MAIL_ENABLED', false));
coreui_define('STU_SMTP_HOST', coreui_env('COREUI_SMTP_HOST'));
coreui_define('STU_SMTP_PORT', (int)coreui_env('COREUI_SMTP_PORT', '587'));
coreui_define('STU_SMTP_USER', coreui_env('COREUI_SMTP_USER'));
coreui_define('STU_SMTP_PASS', coreui_env('COREUI_SMTP_PASS'));
coreui_define('STU_MAIL_FROM', coreui_env('COREUI_MAIL_FROM'));
coreui_define('STU_MAIL_FROM_NAME', coreui_env('COREUI_MAIL_FROM_NAME', 'Ember CoreUI'));
coreui_define('STU_PUBLIC_BASE_URL', rtrim(coreui_env('COREUI_PUBLIC_URL'), '/'));
coreui_define('STU_MAIL_DEBUG', false);

coreui_define('STU_EMBER_ENABLED', coreui_env_bool('COREUI_EMBER_ENABLED', true));
coreui_define('STU_EMBER_USER_ID', (int)coreui_env('COREUI_EMBER_USER_ID', '1'));
coreui_define('STU_EMBER_CHARACTER_ID', coreui_env('COREUI_EMBER_CHARACTER_ID', 'ember'));
coreui_define('STU_EMBER_CHARACTER_NAME', coreui_env('COREUI_EMBER_CHARACTER_NAME', 'Ember'));
coreui_define('STU_EMBER_MODEL', coreui_env('COREUI_EMBER_MODEL', 'ember-coreui:latest'));
coreui_define('STU_EMBER_OLLAMA_URL', coreui_env('COREUI_OLLAMA_URL', 'http://127.0.0.1:11434/api/chat'));
coreui_define('STU_EMBER_LOCK_NAMESPACE', coreui_env('COREUI_LOCK_NAMESPACE', 'ember_coreui'));
coreui_define('STU_EMBER_KEEP_ALIVE', coreui_env('COREUI_EMBER_KEEP_ALIVE', '15m'));
coreui_define('STU_EMBER_NUM_THREAD', (int)coreui_env('COREUI_EMBER_NUM_THREAD', '12'));
coreui_define('STU_EMBER_NUM_PREDICT', (int)coreui_env('COREUI_EMBER_NUM_PREDICT', '6500'));
coreui_define('STU_EMBER_NUM_CTX', (int)coreui_env('COREUI_EMBER_NUM_CTX', '32768'));
coreui_define('STU_EMBER_TEMPERATURE', (float)coreui_env('COREUI_EMBER_TEMPERATURE', '1.0'));
coreui_define('STU_EMBER_TOP_P', (float)coreui_env('COREUI_EMBER_TOP_P', '0.95'));
coreui_define('STU_EMBER_TOP_K', (int)coreui_env('COREUI_EMBER_TOP_K', '64'));
coreui_define('STU_EMBER_REPEAT_PENALTY', (float)coreui_env('COREUI_EMBER_REPEAT_PENALTY', '1.08'));
coreui_define('STU_EMBER_REPEAT_LAST_N', (int)coreui_env('COREUI_EMBER_REPEAT_LAST_N', '64'));
coreui_define('STU_EMBER_TIMEOUT', (int)coreui_env('COREUI_EMBER_TIMEOUT', '600'));
coreui_define('STU_EMBER_TIMEOUT_RETRY', (int)coreui_env('COREUI_EMBER_TIMEOUT_RETRY', '600'));
coreui_define('STU_EMBER_MAX_REPLY_CHARS', (int)coreui_env('COREUI_EMBER_MAX_REPLY_CHARS', '60000'));
coreui_define('STU_EMBER_ATTACH_MAX_CHARS', (int)coreui_env('COREUI_EMBER_ATTACH_MAX_CHARS', '4000'));
coreui_define('STU_EMBER_FAIL_MESSAGE', 'Die Verbindung zum lokalen Kern ist gerade nicht stabil.');
coreui_define('STU_EMBER_TZ', coreui_env('COREUI_TIMEZONE', 'Europe/Berlin'));
coreui_define('STU_EMBER_CACHE_DIR', dirname(__DIR__) . '/var/cache');

coreui_define('STU_EMBER_MEMORY_ENABLED', coreui_env_bool('COREUI_MEMORY_ENABLED', true));
coreui_define('STU_EMBER_MEMORY_LIMIT', (int)coreui_env('COREUI_MEMORY_LIMIT', '16'));
coreui_define('STU_EMBER_REFLECT_ENABLED', coreui_env_bool('COREUI_REFLECT_ENABLED', true));
coreui_define('STU_EMBER_REFLECT_EVERY_N', (int)coreui_env('COREUI_REFLECT_EVERY_N', '4'));
coreui_define('STU_EMBER_REFLECT_TIMEOUT', (int)coreui_env('COREUI_REFLECT_TIMEOUT', '90'));
coreui_define('STU_EMBER_REFLECT_MAX_FACT_LEN', (int)coreui_env('COREUI_REFLECT_MAX_FACT_LEN', '240'));
coreui_define('STU_EMBER_REFLECT_MODEL', coreui_env('COREUI_REFLECT_MODEL'));

coreui_define('STU_EMBER_LORE_ENABLED', coreui_env_bool('COREUI_LORE_ENABLED', true));
coreui_define('STU_EMBER_LORE_LIMIT', (int)coreui_env('COREUI_LORE_LIMIT', '5'));
coreui_define('STU_EMBER_LORE_MAX_CHARS', (int)coreui_env('COREUI_LORE_MAX_CHARS', '1800'));
coreui_define('STU_EMBER_LORE_MIN_QUERY_CHARS', (int)coreui_env('COREUI_LORE_MIN_QUERY_CHARS', '3'));
// Studio-internes Kanonmaterial wird niemals mit Ember CoreUI ausgeliefert.
// Operatoren koennen eigene, rechtmaessig bereitgestellte Quellen separat
// importieren. Das oeffentliche Release enthaelt keine voreingestellten Pfade.
coreui_define('STU_EMBER_LORE_DOCS', []);

coreui_define('STU_EMBER_WEB_ENABLED', coreui_env_bool('COREUI_WEB_ENABLED', true));
coreui_define('STU_EMBER_WEB_URL', coreui_env('COREUI_SEARXNG_URL', 'http://127.0.0.1:18889/search'));
coreui_define('STU_EMBER_WEB_LIMIT', (int)coreui_env('COREUI_WEB_LIMIT', '5'));
coreui_define('STU_EMBER_WEB_TIMEOUT', (int)coreui_env('COREUI_WEB_TIMEOUT', '15'));
coreui_define('STU_EMBER_WEB_MAX_CHARS', (int)coreui_env('COREUI_WEB_MAX_CHARS', '2000'));
coreui_define('STU_EMBER_WEB_SNIPPET_CHARS', (int)coreui_env('COREUI_WEB_SNIPPET_CHARS', '300'));
coreui_define('STU_EMBER_BROWSE_ENABLED', coreui_env_bool('COREUI_BROWSE_ENABLED', true));
coreui_define('STU_EMBER_BROWSE_MAX_STEPS', (int)coreui_env('COREUI_BROWSE_MAX_STEPS', '12'));
coreui_define('STU_EMBER_BROWSE_AGENT_TIMEOUT', (int)coreui_env('COREUI_BROWSE_AGENT_TIMEOUT', '420'));
coreui_define('STU_EMBER_BROWSE_REPORT_TIMEOUT', (int)coreui_env('COREUI_BROWSE_REPORT_TIMEOUT', '420'));
coreui_define('STU_EMBER_BROWSE_AGENT_RETRIES', (int)coreui_env('COREUI_BROWSE_AGENT_RETRIES', '0'));
coreui_define('STU_EMBER_BROWSE_FRAME_QUALITY', (int)coreui_env('COREUI_BROWSE_FRAME_QUALITY', '45'));
coreui_define('STU_EMBER_BROWSE_FRAME_BURST', (int)coreui_env('COREUI_BROWSE_FRAME_BURST', '3'));
coreui_define('STU_EMBER_BROWSE_FRAME_INTERVAL_MS', (int)coreui_env('COREUI_BROWSE_FRAME_INTERVAL_MS', '240'));
coreui_define('STU_EMBER_BROWSE_FRAME_RETENTION_HOURS', (int)coreui_env('COREUI_BROWSE_FRAME_RETENTION_HOURS', '24'));
coreui_define('STU_EMBER_BROWSE_SCREENSHOT_DIR', dirname(__DIR__) . '/uploads/ember_browse');

coreui_define('STU_EMBER_PY_ENABLED', coreui_env_bool('COREUI_PY_ENABLED', true));
coreui_define('STU_EMBER_PY_WAIT', (int)coreui_env('COREUI_PY_WAIT', '90'));
coreui_define('STU_EMBER_PY_MAX_CHARS', (int)coreui_env('COREUI_PY_MAX_CHARS', '2000'));
coreui_define('STU_EMBER_PY_MAX_CODE_CHARS', (int)coreui_env('COREUI_PY_MAX_CODE_CHARS', '12000'));
coreui_define('STU_EMBER_PY_WORKDIR', dirname(__DIR__) . '/var/ember_py');

coreui_define('STU_EMBER_VIDEO_FRAMES', (int)coreui_env('COREUI_VIDEO_FRAMES', '6'));
coreui_define('STU_EMBER_VIDEO_FRAME_PX', (int)coreui_env('COREUI_VIDEO_FRAME_PX', '512'));
coreui_define('STU_EMBER_VIDEO_FRAME_DIR', coreui_env(
  'COREUI_VIDEO_FRAME_DIR',
  dirname(__DIR__) . '/var/ember_frames'
));
coreui_define('STU_EMBER_PDF_MAX_PAGES', (int)coreui_env('COREUI_PDF_MAX_PAGES', '4'));
coreui_define('STU_EMBER_PDF_PAGE_PX', (int)coreui_env('COREUI_PDF_PAGE_PX', '1024'));
coreui_define('STU_EMBER_PDF_PAGE_DIR', coreui_env(
  'COREUI_PDF_PAGE_DIR',
  dirname(__DIR__) . '/var/pdf_pages'
));
coreui_define('STU_EXTERNAL_API_KEY', coreui_env('COREUI_EXTERNAL_API_KEY'));
coreui_define('STU_EMBER_REPUTATION_ENABLED', coreui_env_bool('COREUI_REPUTATION_ENABLED', true));
coreui_define('STU_EMBER_AUTOMOD', false);
coreui_define('STU_EMBER_TOOLS', true);
