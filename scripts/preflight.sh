#!/usr/bin/env bash
set -Eeuo pipefail
IFS=$'\n\t'

PROJECT_ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
VERSION="$(tr -d '[:space:]' < "$PROJECT_ROOT/VERSION")"
COMPOSE_FILE="$PROJECT_ROOT/compose.yaml"
ENV_FILE="$PROJECT_ROOT/var/compose.env"
CONFIG_FILE="$PROJECT_ROOT/api/config.local.php"
COMPOSE_PROJECT='ember-coreui'
COMPOSE_CMD=()
FAILURES=0

ok() {
  printf '[OK]     %s\n' "$*"
}

warn() {
  printf '[WARN]   %s\n' "$*" >&2
}

fail() {
  printf '[FEHLER] %s\n' "$*" >&2
  FAILURES=$((FAILURES + 1))
}

is_enabled() {
  case "${1:-}" in
    1|true|TRUE|yes|YES|on|ON) return 0 ;;
    *) return 1 ;;
  esac
}

compose() {
  "${COMPOSE_CMD[@]}" \
    --project-name "$COMPOSE_PROJECT" \
    --env-file "$ENV_FILE" \
    --file "$COMPOSE_FILE" \
    "$@"
}

service_running() {
  local service="$1"
  local container_id
  container_id="$(compose ps -q "$service" 2>/dev/null || true)"
  [[ -n "$container_id" ]] || return 1
  [[ "$(docker inspect --format '{{.State.Running}}' "$container_id" 2>/dev/null || true)" == 'true' ]]
}

check_service() {
  local service="$1"
  local label="$2"
  if service_running "$service"; then
    ok "$label laeuft im eigenen Compose-Projekt."
  else
    fail "$label laeuft nicht."
  fi
}

printf 'Ember CoreUI Compose-Preflight, Version %s\n\n' "$VERSION"

if [[ ! -f "$ENV_FILE" ]]; then
  fail 'var/compose.env fehlt. Fuehre scripts/install.sh aus.'
fi
if [[ ! -f "$CONFIG_FILE" ]]; then
  fail 'api/config.local.php fehlt. Fuehre scripts/install.sh aus.'
fi
if [[ ! -f "$COMPOSE_FILE" ]]; then
  fail 'compose.yaml fehlt.'
fi

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
  css/admin.css
  js/console-app.js
  js/coreui-preferences.js
  js/settings.js
  js/admin.js
  setup.sh
  api/rag_lite.php
  api/user_settings.php
  api/model_catalog.php
  api/models.php
  api/auth_session_store.php
  api/account_security.php
  api/account_export.php
  api/admin.php
  api/ai_settings.php
  api/account_store.php
  api/profile_store.php
  api/profile.php
  api/profile_media.php
  api/knowledge_store.php
  api/knowledge.php
  api/console_session_store.php
  api/console_attachment_store.php
  api/console_sessions.php
  api/console_messages.php
  database/migrations/002_coreui_management.sql
  database/migrations/003_console_sessions.sql
  database/migrations/004_profiles_knowledge.sql
  database/migrations/005_thinking_attachments.sql
  database/migrations/006_account_security.sql
  database/migrations/007_remove_private_studio_lore.sql
  scripts/session-selftest.php
  scripts/attachment-pipeline-selftest.php
  scripts/python-worker-selftest.php
  scripts/profile-knowledge-selftest.php
  scripts/account-security-selftest.php
  scripts/branding-license-selftest.py
  docker/pyworker/Dockerfile
  docker/pyworker/entrypoint.sh
  images/starlight_unit_studios_logo_transparent_v030.png
)
for ui_file in "${UI_FILES[@]}"; do
  if [[ ! -s "$PROJECT_ROOT/$ui_file" ]]; then
    fail "Ember CoreUI-Oberflaechendatei fehlt oder ist leer: $ui_file"
  fi
done
if (( FAILURES == 0 )); then
  ok 'Ember-CoreUI-Branding, Lizenzunterlagen, KI-Einstellungen und Admin Core sind paketiert.'
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

if grep -Fq "sse_send('token'" "$PROJECT_ROOT/api/console_stream.php"; then
  fail 'Der SSE-Endpunkt sendet noch ungepruefte Modell-Tokens an den Browser.'
else
  ok 'SSE-Ausgaben passieren erst nach der serverseitigen Antwortpruefung.'
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

if (( FAILURES > 0 )); then
  printf '\nPreflight vorzeitig beendet: %d Problem(e).\n' "$FAILURES" >&2
  exit 1
fi

# Die Datei wird ausschliesslich vom Installer mit validierten einzeiligen Werten erzeugt.
set -a
# shellcheck disable=SC1090
source "$ENV_FILE"
set +a

if command -v docker >/dev/null 2>&1 && docker info >/dev/null 2>&1; then
  ok 'Docker-Daemon ist erreichbar.'
else
  fail 'Docker-Daemon ist nicht erreichbar.'
fi

if docker compose version >/dev/null 2>&1; then
  COMPOSE_CMD=(docker compose)
  ok 'Docker Compose v2 ist vorhanden.'
elif command -v docker-compose >/dev/null 2>&1; then
  COMPOSE_CMD=(docker-compose)
  ok 'Docker Compose ist vorhanden.'
else
  fail 'Docker Compose fehlt.'
fi

if (( ${#COMPOSE_CMD[@]} > 0 )); then
  if compose config >/dev/null 2>&1; then
    ok 'Compose-Konfiguration ist gueltig.'
  else
    fail 'Compose-Konfiguration ist ungueltig.'
  fi

  check_service database 'Ember CoreUI-MariaDB'
  check_service php 'Ember CoreUI-PHP-FPM'
  check_service web 'Ember CoreUI-Nginx'
  check_service pyworker 'Ember CoreUI-Python-Worker'
  if is_enabled "${COREUI_INSTALL_SEARXNG:-0}"; then
    check_service searxng 'Ember CoreUI-SearXNG'
  fi
  if is_enabled "${COREUI_INSTALL_BROWSE:-0}"; then
    check_service browse 'Ember CoreUI-Browse-Worker'
  fi

  if compose exec -T php php -r \
      'require "/var/www/coreui/api/db.php"; echo (int)stu_pdo()->query("SELECT COUNT(*) FROM stu_schema_migrations")->fetchColumn();' \
      >/dev/null 2>&1; then
    ok 'Eigene MariaDB-Verbindung und Ember CoreUI-Schema funktionieren.'
  else
    fail 'Eigene MariaDB-Verbindung oder Ember CoreUI-Schema ist fehlerhaft.'
  fi

  if compose exec -T php php -r \
      'require "/var/www/coreui/api/db.php"; $p=stu_pdo(); $p->query("SELECT user_id FROM stu_user_ai_settings LIMIT 1"); $p->query("SELECT id FROM stu_admin_audit LIMIT 1");' \
      >/dev/null 2>&1; then
    ok 'Migration 002: Nutzer-KI-Einstellungen und Admin-Audit sind bereit.'
  else
    fail 'Migration 002 fehlt. Fuehre scripts/stack.sh migrate aus.'
  fi

  if compose exec -T php php -r \
      'require "/var/www/coreui/api/db.php"; $p=stu_pdo(); $p->query("SELECT last_message_id,last_read_message_id,archived_at FROM stu_console_sessions LIMIT 0"); $p->query("SELECT session_id,reply_to_id FROM stu_chat_messages LIMIT 0"); $p->query("SELECT session_id,trigger_message_id FROM stu_ember_browse_jobs LIMIT 0");' \
      >/dev/null 2>&1; then
    ok 'Migration 003: echte Sitzungen und Turn-Zuordnung sind bereit.'
  else
    fail 'Migration 003 fehlt. Fuehre scripts/stack.sh migrate aus.'
  fi

  if compose exec -T php php -r \
      'require "/var/www/coreui/api/db.php"; $p=stu_pdo(); $p->query("SELECT user_id,display_name,assistant_name FROM stu_coreui_profiles LIMIT 0"); $p->query("SELECT user_id,slot FROM stu_coreui_profile_media LIMIT 0"); $p->query("SELECT uuid,user_id,status FROM stu_user_knowledge_sources LIMIT 0"); $p->query("SELECT source_uuid,user_id,chunk_text FROM stu_user_knowledge_chunks LIMIT 0");' \
      >/dev/null 2>&1; then
    ok 'Migration 004: Profile, CoreAI-Identitaet und privates RAG-Lite sind bereit.'
  else
    fail 'Migration 004 fehlt. Fuehre scripts/stack.sh migrate aus.'
  fi

  if compose exec -T php php -r \
      'require "/var/www/coreui/api/db.php"; $p=stu_pdo(); $p->query("SELECT thinking_enabled FROM stu_user_ai_settings LIMIT 0"); $p->query("SELECT message_id,media_uuid,user_id,position FROM stu_console_message_attachments LIMIT 0");' \
      >/dev/null 2>&1; then
    ok 'Migration 005: Thinking-Wahl und persistente Mehrfachanhaenge sind bereit.'
  else
    fail 'Migration 005 fehlt. Fuehre scripts/stack.sh migrate aus.'
  fi

  if compose exec -T php php -r \
      'require "/var/www/coreui/api/db.php"; $p=stu_pdo(); $p->query("SELECT password_changed_at,last_login_at FROM stu_users LIMIT 0"); $p->query("SELECT token_hash,expires_at,revoked_at FROM stu_auth_sessions LIMIT 0");' \
      >/dev/null 2>&1; then
    ok 'Migration 006: widerrufbare Anmeldungen und Passwortzeitpunkte sind bereit.'
  else
    fail 'Migration 006 fehlt. Fuehre scripts/stack.sh migrate aus.'
  fi

  if compose exec -T php php -r \
      'require "/var/www/coreui/api/db.php"; $p=stu_pdo(); $s=$p->prepare("SELECT COUNT(*) FROM ember_knowledge_chunks WHERE source IN (?,?)"); $s->execute(["bibel_v10_4","kompendium_v6"]); exit((int)$s->fetchColumn() === 0 ? 0 : 1);' \
      >/dev/null 2>&1; then
    ok 'Migration 007: versehentlich importiertes privates Studio-Lore ist entfernt.'
  else
    fail 'Migration 007 fehlt oder privates Studio-Lore liegt noch in der Datenbank.'
  fi

  for extension_name in curl dom gd mbstring pdo_mysql zip; do
    if compose exec -T php php -m 2>/dev/null | grep -Fxq "$extension_name"; then
      ok "PHP-Erweiterung aktiv: $extension_name"
    else
      fail "PHP-Erweiterung fehlt: $extension_name"
    fi
  done

  if compose exec -T php sh -ec \
      'find api scripts tools -type f -name "*.php" -print0 | xargs -0 -r -n1 php -l >/dev/null'; then
    ok 'Alle paketierten PHP-Dateien bestehen den Syntaxcheck.'
  else
    fail 'Mindestens eine paketierte PHP-Datei hat einen Syntaxfehler.'
  fi

  thinking_selftest=''
  if thinking_selftest="$(compose exec -T -u 33:33 php php scripts/thinking-sanitize-selftest.php 2>&1)"; then
    thinking_selftest="${thinking_selftest//$'\r'/}"
    ok "$thinking_selftest"
  else
    thinking_selftest="${thinking_selftest//$'\r'/}"
    fail "$thinking_selftest"
  fi

  reply_selftest=''
  if reply_selftest="$(compose exec -T -u 33:33 php php scripts/reply-pipeline-selftest.php 2>&1)"; then
    reply_selftest="${reply_selftest//$'\r'/}"
    ok "$reply_selftest"
  else
    reply_selftest="${reply_selftest//$'\r'/}"
    fail "$reply_selftest"
  fi

  session_selftest=''
  if session_selftest="$(compose exec -T -u 33:33 php php scripts/session-selftest.php 2>&1)"; then
    session_selftest="${session_selftest//$'\r'/}"
    ok "$session_selftest"
  else
    session_selftest="${session_selftest//$'\r'/}"
    fail "$session_selftest"
  fi

  profile_knowledge_selftest=''
  if profile_knowledge_selftest="$(compose exec -T -u 33:33 php php scripts/profile-knowledge-selftest.php 2>&1)"; then
    profile_knowledge_selftest="${profile_knowledge_selftest//$'\r'/}"
    ok "$profile_knowledge_selftest"
  else
    profile_knowledge_selftest="${profile_knowledge_selftest//$'\r'/}"
    fail "$profile_knowledge_selftest"
  fi

  attachment_pipeline_selftest=''
  if attachment_pipeline_selftest="$(compose exec -T -u 33:33 php php scripts/attachment-pipeline-selftest.php 2>&1)"; then
    attachment_pipeline_selftest="${attachment_pipeline_selftest//$'\r'/}"
    ok "$attachment_pipeline_selftest"
  else
    attachment_pipeline_selftest="${attachment_pipeline_selftest//$'\r'/}"
    fail "$attachment_pipeline_selftest"
  fi

  python_worker_selftest=''
  if python_worker_selftest="$(compose exec -T -u 33:33 php php scripts/python-worker-selftest.php 2>&1)"; then
    python_worker_selftest="${python_worker_selftest//$'\r'/}"
    ok "$python_worker_selftest"
  else
    python_worker_selftest="${python_worker_selftest//$'\r'/}"
    fail "$python_worker_selftest"
  fi

  account_security_selftest=''
  if account_security_selftest="$(compose exec -T -u 33:33 php php scripts/account-security-selftest.php 2>&1)"; then
    account_security_selftest="${account_security_selftest//$'\r'/}"
    ok "$account_security_selftest"
  else
    account_security_selftest="${account_security_selftest//$'\r'/}"
    fail "$account_security_selftest"
  fi

  logo_selftest=''
  if logo_selftest="$(compose exec -T -u 33:33 php php scripts/logo-alpha-selftest.php 2>&1)"; then
    logo_selftest="${logo_selftest//$'\r'/}"
    ok "$logo_selftest"
  else
    logo_selftest="${logo_selftest//$'\r'/}"
    fail "$logo_selftest"
  fi

  if is_enabled "${COREUI_INSTALL_BROWSE:-0}"; then
    frame_table_ready=0
    for _frame_try in {1..15}; do
      if compose exec -T php php -r \
          'require "/var/www/coreui/api/db.php"; stu_pdo()->query("SELECT id FROM stu_ember_browse_frames LIMIT 1");' \
          >/dev/null 2>&1; then
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

  LOCK_NAMESPACE="$(compose exec -T php php -r \
    'require "/var/www/coreui/api/config.php"; echo STU_EMBER_LOCK_NAMESPACE;' 2>/dev/null || true)"
  LOCK_NAMESPACE="${LOCK_NAMESPACE//$'\r'/}"
  if [[ -n "$LOCK_NAMESPACE" && "$LOCK_NAMESPACE" != 'ember' && "$LOCK_NAMESPACE" != 'stu' ]]; then
    ok "Eigener MariaDB-Lock-Namespace aktiv: $LOCK_NAMESPACE"
  else
    fail 'Kein sicherer Ember CoreUI-Lock-Namespace aktiv.'
  fi

  for writable_dir in \
    /var/www/coreui/logs \
    /var/www/coreui/var/cache \
    /var/www/coreui/var/console_media \
    /var/www/coreui/var/profile_media \
    /var/www/coreui/var/knowledge_uploads \
    /var/www/coreui/var/ember_frames \
    /var/www/coreui/var/pdf_pages \
    /var/www/coreui/uploads/ember_browse \
    /var/www/coreui/assets/chat_media \
    /var/www/coreui/assets/profile_photos/pending \
    /var/www/coreui/assets/profile_photos/approved; do
    if compose exec -T -u 33:33 php test -w "$writable_dir" >/dev/null 2>&1; then
      ok "Container-Schreibpfad bereit: ${writable_dir#/var/www/coreui/}"
    else
      fail "Container-Schreibpfad nicht bereit: ${writable_dir#/var/www/coreui/}"
    fi
  done

  video_selftest=''
  if video_selftest="$(compose exec -T -u 33:33 php php scripts/video-selftest.php 2>&1)"; then
    video_selftest="${video_selftest//$'\r'/}"
    ok "$video_selftest"
  else
    video_selftest="${video_selftest//$'\r'/}"
    fail "$video_selftest"
  fi

  pdf_selftest=''
  if pdf_selftest="$(compose exec -T -u 33:33 php php scripts/pdf-selftest.php 2>&1)"; then
    pdf_selftest="${pdf_selftest//$'\r'/}"
    ok "$pdf_selftest"
  else
    pdf_selftest="${pdf_selftest//$'\r'/}"
    fail "$pdf_selftest"
  fi
fi

if curl -fsS --max-time 10 "http://127.0.0.1:${COREUI_HTTP_PORT}/api/health.php" >/dev/null 2>&1; then
  ok "Ember CoreUI-Healthcheck antwortet auf Loopback-Port ${COREUI_HTTP_PORT}."
else
  fail "Ember CoreUI-Healthcheck antwortet nicht auf Port ${COREUI_HTTP_PORT}."
fi

if curl -fsS --max-time 10 "http://127.0.0.1:${COREUI_HTTP_PORT}/settings.html" \
    | grep -Fq 'EINSTELLUNGEN'; then
  ok 'Ember CoreUI-Einstellungen werden vom isolierten Webserver ausgeliefert.'
else
  fail 'Ember CoreUI-Einstellungen sind nicht erreichbar. Fuehre scripts/stack.sh refresh-runtime und danach scripts/stack.sh restart web aus.'
fi

if curl -fsS --max-time 10 "http://127.0.0.1:${COREUI_HTTP_PORT}/admin/index.html" \
    | grep -Fq 'ADMIN CORE'; then
  ok 'Das isolierte Admin Core wird vom Ember CoreUI-Webserver ausgeliefert.'
else
  fail 'Admin Core ist nicht erreichbar.'
fi

SESSION_ROUTE_STATUS="$(curl -sS --max-time 10 -o /dev/null -w '%{http_code}' \
  "http://127.0.0.1:${COREUI_HTTP_PORT}/api/console_messages.php" 2>/dev/null || true)"
if [[ "$SESSION_ROUTE_STATUS" == '401' || "$SESSION_ROUTE_STATUS" == '400' ]]; then
  ok 'Der authentifizierte Sitzungs-History-Endpunkt wird vom Ember CoreUI-Webserver erreicht.'
else
  fail "Sitzungs-History-Endpunkt nicht korrekt geroutet (HTTP ${SESSION_ROUTE_STATUS:-000})."
fi

for private_route in profile knowledge profile_media account_security account_export models; do
  route_status="$(curl -sS --max-time 10 -o /dev/null -w '%{http_code}' \
    "http://127.0.0.1:${COREUI_HTTP_PORT}/api/${private_route}.php" 2>/dev/null || true)"
  if [[ "$route_status" == '401' ]]; then
    ok "Privater Ember CoreUI-Endpunkt korrekt geroutet: ${private_route}.php"
  else
    fail "Ember CoreUI-Endpunkt ${private_route}.php nicht korrekt geroutet (HTTP ${route_status:-000})."
  fi
done

if command -v ollama >/dev/null 2>&1 \
    && ollama show "${COREUI_MODEL_NAME}" >/dev/null 2>&1; then
  ok "Getrenntes Ollama-Modell vorhanden: ${COREUI_MODEL_NAME}"
else
  fail "Ember CoreUI-Ollama-Modell fehlt: ${COREUI_MODEL_NAME}"
fi

if is_enabled "${COREUI_INSTALL_SEARXNG:-0}"; then
  if curl -fsS --max-time 10 \
      "http://127.0.0.1:${COREUI_SEARXNG_PORT}/search?q=ember&format=json" >/dev/null 2>&1; then
    ok "Eigene SearXNG-JSON-Suche antwortet auf Port ${COREUI_SEARXNG_PORT}."
  else
    fail "Eigene SearXNG-JSON-Suche antwortet nicht auf Port ${COREUI_SEARXNG_PORT}."
  fi
fi

if [[ "${COREUI_BIND_ADDRESS:-127.0.0.1}" == '127.0.0.1' ]]; then
  ok 'Webzugriff ist standardmaessig auf Loopback begrenzt.'
else
  warn 'Ember CoreUI lauscht bewusst auf 0.0.0.0. Firewall und TLS muessen separat geprueft werden.'
fi

if [[ -L /etc/nginx/sites-enabled/default || -e /etc/nginx/sites-enabled/default ]]; then
  ok 'Eine bestehende Nginx-Default-Site wurde nicht entfernt.'
else
  warn 'Keine Nginx-Default-Site gefunden. Ember CoreUI veraendert diesen Zustand nicht.'
fi

if (( FAILURES > 0 )); then
  printf '\nPreflight fehlgeschlagen: %d Problem(e).\n' "$FAILURES" >&2
  exit 1
fi

printf '\nPreflight erfolgreich. Ember CoreUI laeuft im isolierten Parallelmodus.\n'
