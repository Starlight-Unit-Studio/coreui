#!/usr/bin/env bash
set -Eeuo pipefail
IFS=$'\n\t'

PROJECT_ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
CONFIG_FILE="$PROJECT_ROOT/api/config.local.php"
VERSION="$(tr -d '[:space:]' < "$PROJECT_ROOT/VERSION")"
FAILURES=0

ok() {
  printf '[OK]   %s\n' "$*"
}

fail() {
  printf '[FEHLER] %s\n' "$*" >&2
  FAILURES=$((FAILURES + 1))
}

check_command() {
  if command -v "$1" >/dev/null 2>&1; then
    ok "Befehl vorhanden: $1"
  else
    fail "Befehl fehlt: $1"
  fi
}

read_constant() {
  local key="$1"
  COREUI_READ_CONFIG="$CONFIG_FILE" COREUI_READ_KEY="$key" php -r '
    require getenv("COREUI_READ_CONFIG");
    $key = getenv("COREUI_READ_KEY");
    if (!defined($key)) exit(3);
    $value = constant($key);
    if (is_bool($value)) echo $value ? "1" : "0";
    elseif (is_scalar($value)) echo (string)$value;
  '
}

printf 'Ember CoreUI Native-Preflight, Version %s\n' "$VERSION"

UI_FILES=(
  LICENSE.de.md
  LICENSE.md
  LICENSE_HISTORY.md
  TRADEMARKS.md
  COMMUNITY_POLICY.md
  NOTICE.md
  COPYRIGHT.md
  app.html
  settings.html
  admin/index.html
  protocols.html
  css/console.css
  js/console-app.js
  js/coreui-markdown.js
  js/coreui-preferences.js
  js/settings.js
  js/admin.js
  css/admin.css
  setup.sh
  api/rag_lite.php
  api/ai_settings.php
  api/model_catalog.php
  api/models.php
  api/auth_session_store.php
  api/account_security.php
  api/account_export.php
  api/console_attachment_store.php
  api/console_session_store.php
  api/console_sessions.php
  api/console_messages.php
  api/console_action_store.php
  api/console_actions.php
  api/account_store.php
  api/profile_store.php
  api/profile.php
  api/profile_media.php
  api/knowledge_store.php
  api/knowledge.php
  database/migrations/003_console_sessions.sql
  database/migrations/004_profiles_knowledge.sql
  database/migrations/005_thinking_attachments.sql
  database/migrations/006_account_security.sql
  database/migrations/007_remove_private_studio_lore.sql
  database/migrations/008_message_actions.sql
  database/migrations/009_message_editing.sql
  scripts/session-selftest.php
  scripts/attachment-pipeline-selftest.php
  scripts/python-worker-selftest.php
  scripts/profile-knowledge-selftest.php
  scripts/account-security-selftest.php
  scripts/frontend-regression-selftest.php
  scripts/message-actions-selftest.php
  scripts/markdown-selftest.js
  scripts/branding-license-selftest.py
  docs/changelogs-txt/CHANGELOG_0_5_1_ALPHA.txt
  docs/UEBERGABEPROTOKOLL_0_5_1_ALPHA.txt
  images/starlight_unit_studios_logo_original.png
)
for ui_file in "${UI_FILES[@]}"; do
  if [[ ! -s "$PROJECT_ROOT/$ui_file" ]]; then
    fail "Ember CoreUI-Oberflaechendatei fehlt oder ist leer: $ui_file"
  fi
done
if (( FAILURES == 0 )); then
  ok 'Ember-CoreUI-Branding, Lizenzunterlagen und Einstellungsoberflaeche sind paketiert.'
fi

if find "$PROJECT_ROOT" -type f \
    \( -iname '*master*bibel*' -o -iname '*kompendium*v6*' \) -print -quit | grep -q .; then
  fail 'Privates Bibel- oder Kompendium-Material ist noch im Release enthalten.'
else
  ok 'Das Release enthaelt kein privates Bibel- oder Kompendium-Dokument.'
fi

if grep -Fq "action: 'delete_permanently'" "$PROJECT_ROOT/js/console-app.js" \
    && grep -Fq "coreui_console_session_delete_tx" "$PROJECT_ROOT/api/console_session_store.php" \
    && grep -Fq "delete_confirmation_required" "$PROJECT_ROOT/api/console_sessions.php"; then
  ok 'Archivgeschuetzte, transaktionale Sitzungsloeschung ist paketiert.'
else
  fail 'Die sichere Sitzungsloeschung ist unvollstaendig paketiert.'
fi

if grep -Fq "model_override: aiModelOverride" "$PROJECT_ROOT/js/settings.js" \
    && grep -Fq "coreui_private_knowledge_block" "$PROJECT_ROOT/api/chat.php" \
    && grep -Fq "user_create" "$PROJECT_ROOT/api/admin.php"; then
  ok 'Funktionale Modellwahl, privates RAG-Lite und Benutzeranlage sind paketiert.'
else
  fail 'Mindestens eine neue 0.4.0-Funktion ist nicht vollstaendig verdrahtet.'
fi

if grep -Fq "thinking_enabled: aiThinkingEnabled" "$PROJECT_ROOT/js/settings.js" \
    && grep -Fq "'think'    => ember_thinking_enabled()" "$PROJECT_ROOT/api/console_stream.php" \
    && grep -Fq 'MAX_MESSAGE_ATTACHMENTS = 10' "$PROJECT_ROOT/js/console-app.js" \
    && grep -Fq 'stu_console_message_attachments' "$PROJECT_ROOT/database/migrations/005_thinking_attachments.sql"; then
  ok 'Thinking-Schalter und bis zu zehn persistente Nachrichtenanhaenge sind paketiert.'
else
  fail 'Thinking-Schalter oder Mehrfachanhaenge sind unvollstaendig paketiert.'
fi

if grep -Fq 'coreui_ollama_model_exists' "$PROJECT_ROOT/api/user_settings.php" \
    && grep -Fq 'password_change' "$PROJECT_ROOT/api/account_security.php" \
    && grep -Fq 'thinking_content_exported' "$PROJECT_ROOT/api/account_export.php" \
    && grep -Fq 'loadAccountSecurity()' "$PROJECT_ROOT/js/settings.js"; then
  ok 'Kontosicherheit, sicherer Datenexport und validierte Ollama-Modellwahl sind paketiert.'
else
  fail 'Mindestens eine neue 0.4.1-Funktion ist nicht vollstaendig verdrahtet.'
fi

for command_name in curl docker mariadb nginx pdfinfo pdftoppm pdftotext php; do
  check_command "$command_name"
done

if [[ ! -f "$CONFIG_FILE" ]]; then
  fail 'api/config.local.php fehlt. Fuehre scripts/install.sh aus.'
else
  ok 'Lokale Konfiguration vorhanden.'
fi

if command -v php >/dev/null 2>&1; then
  for extension_name in curl dom gd mbstring pdo_mysql zip; do
    if php -m | grep -Fxq "$extension_name"; then
      ok "PHP-Erweiterung aktiv: $extension_name"
    else
      fail "PHP-Erweiterung fehlt: $extension_name"
    fi
  done


  if find "$PROJECT_ROOT/api" "$PROJECT_ROOT/scripts" "$PROJECT_ROOT/tools" \
      -type f -name '*.php' -print0 \
      | xargs -0 -r -n1 php -l >/dev/null; then
    ok 'Alle paketierten PHP-Dateien bestehen den Syntaxcheck.'
  else
    fail 'Mindestens eine paketierte PHP-Datei hat einen Syntaxfehler.'
  fi

  thinking_selftest=''
  if thinking_selftest="$(runuser -u www-data -- php "$PROJECT_ROOT/scripts/thinking-sanitize-selftest.php" 2>&1)"; then
    thinking_selftest="${thinking_selftest//$'\r'/}"
    ok "$thinking_selftest"
  else
    thinking_selftest="${thinking_selftest//$'\r'/}"
    fail "$thinking_selftest"
  fi

  reply_selftest=''
  if reply_selftest="$(runuser -u www-data -- php "$PROJECT_ROOT/scripts/reply-pipeline-selftest.php" 2>&1)"; then
    reply_selftest="${reply_selftest//$'\r'/}"
    ok "$reply_selftest"
  else
    reply_selftest="${reply_selftest//$'\r'/}"
    fail "$reply_selftest"
  fi

  session_selftest=''
  if session_selftest="$(runuser -u www-data -- php "$PROJECT_ROOT/scripts/session-selftest.php" 2>&1)"; then
    session_selftest="${session_selftest//$'\r'/}"
    ok "$session_selftest"
  else
    session_selftest="${session_selftest//$'\r'/}"
    fail "$session_selftest"
  fi

  profile_knowledge_selftest=''
  if profile_knowledge_selftest="$(runuser -u www-data -- php "$PROJECT_ROOT/scripts/profile-knowledge-selftest.php" 2>&1)"; then
    profile_knowledge_selftest="${profile_knowledge_selftest//$'\r'/}"
    ok "$profile_knowledge_selftest"
  else
    profile_knowledge_selftest="${profile_knowledge_selftest//$'\r'/}"
    fail "$profile_knowledge_selftest"
  fi

  attachment_pipeline_selftest=''
  if attachment_pipeline_selftest="$(runuser -u www-data -- php "$PROJECT_ROOT/scripts/attachment-pipeline-selftest.php" 2>&1)"; then
    attachment_pipeline_selftest="${attachment_pipeline_selftest//$'\r'/}"
    ok "$attachment_pipeline_selftest"
  else
    attachment_pipeline_selftest="${attachment_pipeline_selftest//$'\r'/}"
    fail "$attachment_pipeline_selftest"
  fi

  python_worker_selftest=''
  if python_worker_selftest="$(runuser -u www-data -- php "$PROJECT_ROOT/scripts/python-worker-selftest.php" 2>&1)"; then
    python_worker_selftest="${python_worker_selftest//$'\r'/}"
    ok "$python_worker_selftest"
  else
    python_worker_selftest="${python_worker_selftest//$'\r'/}"
    fail "$python_worker_selftest"
  fi

  account_security_selftest=''
  if account_security_selftest="$(runuser -u www-data -- php "$PROJECT_ROOT/scripts/account-security-selftest.php" 2>&1)"; then
    account_security_selftest="${account_security_selftest//$'\r'/}"
    ok "$account_security_selftest"
  else
    account_security_selftest="${account_security_selftest//$'\r'/}"
    fail "$account_security_selftest"
  fi

  logo_selftest=''
  if logo_selftest="$(runuser -u www-data -- php "$PROJECT_ROOT/scripts/logo-alpha-selftest.php" 2>&1)"; then
    logo_selftest="${logo_selftest//$'\r'/}"
    ok "$logo_selftest"
  else
    logo_selftest="${logo_selftest//$'\r'/}"
    fail "$logo_selftest"
  fi

  frontend_selftest=''
  if frontend_selftest="$(runuser -u www-data -- php "$PROJECT_ROOT/scripts/frontend-regression-selftest.php" 2>&1)"; then
    frontend_selftest="${frontend_selftest//$'\r'/}"
    ok "$frontend_selftest"
  else
    frontend_selftest="${frontend_selftest//$'\r'/}"
    fail "$frontend_selftest"
  fi

  message_actions_selftest=''
  if message_actions_selftest="$(runuser -u www-data -- php "$PROJECT_ROOT/scripts/message-actions-selftest.php" 2>&1)"; then
    message_actions_selftest="${message_actions_selftest//$'\r'/}"
    ok "$message_actions_selftest"
  else
    message_actions_selftest="${message_actions_selftest//$'\r'/}"
    fail "$message_actions_selftest"
  fi

  if [[ -f "$CONFIG_FILE" ]]; then
    if php -r "require '$PROJECT_ROOT/api/db.php'; echo (int)stu_pdo()->query('SELECT COUNT(*) FROM stu_schema_migrations')->fetchColumn();" >/dev/null 2>&1; then
      ok 'MariaDB-Verbindung und Core-Schema funktionieren.'
    else
      fail 'MariaDB-Verbindung oder Core-Schema fehlgeschlagen.'
    fi

    if php -r "require '$PROJECT_ROOT/api/db.php'; \$p=stu_pdo(); \$p->query('SELECT user_id FROM stu_user_ai_settings LIMIT 1'); \$p->query('SELECT id FROM stu_admin_audit LIMIT 1');" >/dev/null 2>&1; then
      ok 'Migration 002: Nutzer-KI-Einstellungen und Admin-Audit sind bereit.'
    else
      fail 'Migration 002 fehlt.'
    fi

    if php -r "require '$PROJECT_ROOT/api/db.php'; \$p=stu_pdo(); \$p->query('SELECT last_message_id,last_read_message_id,archived_at FROM stu_console_sessions LIMIT 0'); \$p->query('SELECT session_id,reply_to_id FROM stu_chat_messages LIMIT 0'); \$p->query('SELECT session_id,trigger_message_id FROM stu_ember_browse_jobs LIMIT 0');" >/dev/null 2>&1; then
      ok 'Migration 003: echte Sitzungen und Turn-Zuordnung sind bereit.'
    else
      fail 'Migration 003 fehlt.'
    fi

    if php -r "require '$PROJECT_ROOT/api/db.php'; \$p=stu_pdo(); \$p->query('SELECT user_id,display_name,assistant_name FROM stu_coreui_profiles LIMIT 0'); \$p->query('SELECT user_id,slot FROM stu_coreui_profile_media LIMIT 0'); \$p->query('SELECT uuid,user_id,status FROM stu_user_knowledge_sources LIMIT 0'); \$p->query('SELECT source_uuid,user_id,chunk_text FROM stu_user_knowledge_chunks LIMIT 0');" >/dev/null 2>&1; then
      ok 'Migration 004: Profile, CoreAI-Identitaet und privates RAG-Lite sind bereit.'
    else
      fail 'Migration 004 fehlt.'
    fi

    if php -r "require '$PROJECT_ROOT/api/db.php'; \$p=stu_pdo(); \$p->query('SELECT thinking_enabled FROM stu_user_ai_settings LIMIT 0'); \$p->query('SELECT message_id,media_uuid,user_id,position FROM stu_console_message_attachments LIMIT 0');" >/dev/null 2>&1; then
      ok 'Migration 005: Thinking-Wahl und persistente Mehrfachanhaenge sind bereit.'
    else
      fail 'Migration 005 fehlt.'
    fi

    if php -r "require '$PROJECT_ROOT/api/db.php'; \$p=stu_pdo(); \$p->query('SELECT password_changed_at,last_login_at FROM stu_users LIMIT 0'); \$p->query('SELECT token_hash,expires_at,revoked_at FROM stu_auth_sessions LIMIT 0');" >/dev/null 2>&1; then
      ok 'Migration 006: widerrufbare Anmeldungen und Passwortzeitpunkte sind bereit.'
    else
      fail 'Migration 006 fehlt.'
    fi

    if php -r "require '$PROJECT_ROOT/api/db.php'; \$p=stu_pdo(); \$s=\$p->prepare('SELECT COUNT(*) FROM ember_knowledge_chunks WHERE source IN (?,?)'); \$s->execute(['bibel_v10_4','kompendium_v6']); exit((int)\$s->fetchColumn() === 0 ? 0 : 1);" >/dev/null 2>&1; then
      ok 'Migration 007: versehentlich importiertes privates Studio-Lore ist entfernt.'
    else
      fail 'Migration 007 fehlt oder privates Studio-Lore liegt noch in der Datenbank.'
    fi

    if php -r "require '$PROJECT_ROOT/api/db.php'; \$p=stu_pdo(); \$p->query('SELECT response_floor_id,mode,status,response_message_id,browse_job_id FROM stu_console_generation_requests LIMIT 0');" >/dev/null 2>&1; then
      ok 'Migration 008: Feedback, Alternativen und Fortsetzungen sind bereit.'
    else
      fail 'Migration 008 fehlt.'
    fi

    if php -r "require '$PROJECT_ROOT/api/db.php'; \$p=stu_pdo(); \$p->query('SELECT revision_no,superseded_message_count FROM stu_console_message_revisions LIMIT 0'); \$m=\$p->query(\"SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='stu_console_generation_requests' AND COLUMN_NAME='mode'\")->fetchColumn(); exit(str_contains((string)\$m,\"'edit'\") ? 0 : 1);" >/dev/null 2>&1; then
      ok 'Migration 009: echte Nachrichtenbearbeitung und Kontext-Neustart sind bereit.'
    else
      fail 'Migration 009 fehlt.'
    fi

    if systemctl is-active --quiet ember-coreui-python.service; then
      ok 'Ember CoreUI-Python-Worker ist aktiv.'
    else
      fail 'Ember CoreUI-Python-Worker ist nicht aktiv.'
    fi

    MODEL_NAME="$(read_constant STU_EMBER_MODEL || true)"
    if [[ -n "$MODEL_NAME" ]] && command -v ollama >/dev/null 2>&1 && ollama show "$MODEL_NAME" >/dev/null 2>&1; then
      ok "Ollama-Modell vorhanden: $MODEL_NAME"
    else
      fail "Ollama-Modell fehlt oder Ollama ist nicht erreichbar: ${MODEL_NAME:-unbekannt}"
    fi

    WEB_ENABLED="$(read_constant STU_EMBER_WEB_ENABLED || printf '0')"
    WEB_URL="$(read_constant STU_EMBER_WEB_URL || true)"
    if [[ "$WEB_ENABLED" == '1' ]]; then
      WEB_TEST_URL="$WEB_URL"
      if [[ "$WEB_TEST_URL" == *\?* ]]; then
        WEB_TEST_URL+='&q=ember&format=json'
      else
        WEB_TEST_URL+='?q=ember&format=json'
      fi
      if curl -fsS --max-time 8 "$WEB_TEST_URL" >/dev/null; then
        ok 'SearXNG JSON-Suche erreichbar.'
      else
        fail 'SearXNG JSON-Suche nicht erreichbar.'
      fi
    fi

    BROWSE_ENABLED="$(read_constant STU_EMBER_BROWSE_ENABLED || printf '0')"
    if [[ "$BROWSE_ENABLED" == '1' ]]; then
      if systemctl is-active --quiet ember-coreui-browse.service; then
        ok 'Browse-Worker ist aktiv.'
      else
        fail 'Browse-Worker ist nicht aktiv.'
      fi
      frame_table_ready=0
      for _frame_try in {1..15}; do
        if php -r "require '$PROJECT_ROOT/api/db.php'; stu_pdo()->query('SELECT id FROM stu_ember_browse_frames LIMIT 1');" >/dev/null 2>&1; then
          frame_table_ready=1
          break
        fi
        sleep 1
      done
      if (( frame_table_ready == 1 )); then
        ok 'Private Live-Browser-Frame-Tabelle ist bereit.'
      else
        fail 'Private Live-Browser-Frame-Tabelle fehlt. Browse-Worker neu starten.'
      fi
    fi
  fi
fi

if nginx -t >/dev/null 2>&1; then
  ok 'Nginx-Konfiguration ist gueltig.'
else
  fail 'Nginx-Konfiguration ist ungueltig.'
fi

for writable_dir in \
  "$PROJECT_ROOT/logs" \
  "$PROJECT_ROOT/var/console_media" \
  "$PROJECT_ROOT/var/profile_media" \
  "$PROJECT_ROOT/var/knowledge_uploads" \
  "$PROJECT_ROOT/var/ember_py" \
  "$PROJECT_ROOT/var/ember_frames" \
  "$PROJECT_ROOT/var/pdf_pages" \
  "$PROJECT_ROOT/uploads/ember_browse" \
  "$PROJECT_ROOT/assets/chat_media" \
  "$PROJECT_ROOT/assets/profile_photos/pending" \
  "$PROJECT_ROOT/assets/profile_photos/approved"; do
  if [[ -d "$writable_dir" ]] && runuser -u www-data -- test -w "$writable_dir"; then
    ok "PHP-Schreibpfad bereit: ${writable_dir#"$PROJECT_ROOT"/}"
  else
    fail "PHP-Schreibpfad nicht bereit: ${writable_dir#"$PROJECT_ROOT"/}"
  fi
done

if command -v php >/dev/null 2>&1; then
  pdf_selftest=''
  if pdf_selftest="$(runuser -u www-data -- php "$PROJECT_ROOT/scripts/pdf-selftest.php" 2>&1)"; then
    pdf_selftest="${pdf_selftest//$'\r'/}"
    ok "$pdf_selftest"
  else
    pdf_selftest="${pdf_selftest//$'\r'/}"
    fail "$pdf_selftest"
  fi
fi

if (( FAILURES > 0 )); then
  printf '\nPreflight fehlgeschlagen: %d Problem(e).\n' "$FAILURES" >&2
  exit 1
fi

printf '\nPreflight erfolgreich. Ember CoreUI ist betriebsbereit.\n'
