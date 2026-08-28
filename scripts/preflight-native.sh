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
  app.html
  settings.html
  admin/index.html
  protocols.html
  css/console.css
  js/console-app.js
  js/coreui-preferences.js
  js/settings.js
  js/admin.js
  css/admin.css
  api/console_session_store.php
  api/console_sessions.php
  api/console_messages.php
  database/migrations/003_console_sessions.sql
  scripts/session-selftest.php
  images/starlight_unit_studios_logo_transparent_v030.png
)
for ui_file in "${UI_FILES[@]}"; do
  if [[ ! -s "$PROJECT_ROOT/$ui_file" ]]; then
    fail "CoreUI-Oberflaechendatei fehlt oder ist leer: $ui_file"
  fi
done
if (( FAILURES == 0 )); then
  ok 'Studio-Branding und Einstellungsoberflaeche sind paketiert.'
fi

if grep -Fq "action: 'delete_permanently'" "$PROJECT_ROOT/js/console-app.js" \
    && grep -Fq "coreui_console_session_delete_tx" "$PROJECT_ROOT/api/console_session_store.php" \
    && grep -Fq "delete_confirmation_required" "$PROJECT_ROOT/api/console_sessions.php"; then
  ok 'Archivgeschuetzte, transaktionale Sitzungsloeschung ist paketiert.'
else
  fail 'Die sichere Sitzungsloeschung ist unvollstaendig paketiert.'
fi

for command_name in curl mariadb nginx pdfinfo pdftoppm pdftotext php; do
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

  logo_selftest=''
  if logo_selftest="$(runuser -u www-data -- php "$PROJECT_ROOT/scripts/logo-alpha-selftest.php" 2>&1)"; then
    logo_selftest="${logo_selftest//$'\r'/}"
    ok "$logo_selftest"
  else
    logo_selftest="${logo_selftest//$'\r'/}"
    fail "$logo_selftest"
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
