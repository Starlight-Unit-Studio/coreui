#!/usr/bin/env bash
set -Eeuo pipefail
IFS=$'\n\t'
umask 027

PROJECT_ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
CONFIG_FILE="$PROJECT_ROOT/api/config.local.php"
COREUI_VERSION="$(tr -d '[:space:]' < "$PROJECT_ROOT/VERSION")"
CONFIG_TMP=''
DB_CLIENT_TMP=''
OLLAMA_INSTALL_TMP=''

cleanup() {
  [[ -z "$CONFIG_TMP" || ! -f "$CONFIG_TMP" ]] || rm -f -- "$CONFIG_TMP"
  [[ -z "$DB_CLIENT_TMP" || ! -f "$DB_CLIENT_TMP" ]] || rm -f -- "$DB_CLIENT_TMP"
  [[ -z "$OLLAMA_INSTALL_TMP" || ! -f "$OLLAMA_INSTALL_TMP" ]] || rm -f -- "$OLLAMA_INSTALL_TMP"
}
trap cleanup EXIT

log() {
  printf '[Ember CoreUI] %s\n' "$*"
}

warn() {
  printf '[Ember CoreUI] WARN: %s\n' "$*" >&2
}

die() {
  printf '[Ember CoreUI] FEHLER: %s\n' "$*" >&2
  exit 1
}

is_enabled() {
  case "${1:-}" in
    1|true|TRUE|yes|YES|on|ON) return 0 ;;
    *) return 1 ;;
  esac
}

php_escape() {
  printf '%s' "$1" | sed -e 's/\\/\\\\/g' -e "s/'/\\\\'/g"
}

read_constant() {
  local key="$1"
  COREUI_READ_CONFIG="$CONFIG_FILE" COREUI_READ_KEY="$key" php -r '
    $file = getenv("COREUI_READ_CONFIG");
    $key = getenv("COREUI_READ_KEY");
    require $file;
    if (!defined($key)) exit(3);
    $value = constant($key);
    if (is_bool($value)) echo $value ? "1" : "0";
    elseif (is_scalar($value)) echo (string)$value;
  '
}

wait_http() {
  local url="$1"
  local attempts="${2:-30}"
  local count=0
  while (( count < attempts )); do
    if curl -fsS --max-time 4 "$url" >/dev/null 2>&1; then
      return 0
    fi
    count=$((count + 1))
    sleep 2
  done
  return 1
}

[[ "${EUID:-$(id -u)}" -eq 0 ]] || die 'Bitte mit sudo oder als root ausfuehren.'
[[ "${COREUI_NATIVE_CONFIRM:-}" == 'DEDICATED_SERVER' ]] \
  || die 'Der native Installer ist nur fuer einen dedizierten, leeren Server gedacht. Nutze standardmaessig scripts/install.sh oder bestaetige bewusst mit COREUI_NATIVE_CONFIRM=DEDICATED_SERVER.'
[[ -f "$PROJECT_ROOT/app.html" ]] || die 'app.html fehlt. Das Installationspaket ist unvollstaendig.'
[[ -f "$PROJECT_ROOT/settings.html" ]] || die 'settings.html fehlt. Das Installationspaket ist unvollstaendig.'
[[ -f "$PROJECT_ROOT/admin/index.html" ]] || die 'admin/index.html fehlt. Das Installationspaket ist unvollstaendig.'
[[ -f "$PROJECT_ROOT/config/nginx.conf.template" ]] || die 'Nginx-Template fehlt.'
[[ -f "$PROJECT_ROOT/database/migrations/001_core.sql" ]] || die 'SQL-Basisschema fehlt.'
[[ -f "$PROJECT_ROOT/database/migrations/002_coreui_management.sql" ]] || die 'SQL-Verwaltungsschema fehlt.'
[[ -f "$PROJECT_ROOT/database/migrations/003_console_sessions.sql" ]] || die 'SQL-Sitzungsschema fehlt.'
[[ -f "$PROJECT_ROOT/database/migrations/004_profiles_knowledge.sql" ]] || die 'SQL-Profil- und Knowledge-Schema fehlt.'
[[ -f "$PROJECT_ROOT/database/migrations/005_thinking_attachments.sql" ]] || die 'SQL-Thinking- und Anhangschema fehlt.'
[[ -f "$PROJECT_ROOT/database/migrations/006_account_security.sql" ]] || die 'SQL-Kontosicherheitsschema fehlt.'
[[ "$PROJECT_ROOT" =~ ^/[A-Za-z0-9._/-]+$ ]] || die 'Der Projektpfad darf nur Buchstaben, Zahlen, Punkt, Unterstrich, Bindestrich und Slash enthalten.'
[[ "$PROJECT_ROOT" != /root/* ]] || die 'Bitte das Paket zuerst nach /opt/ember-coreui verschieben. Nginx darf Verzeichnisse unter /root nicht ausliefern.'
[[ "$PROJECT_ROOT" != /home/* ]] || die 'Bitte das Paket zuerst nach /opt/ember-coreui verschieben. Der gehaertete Worker kapselt Benutzerverzeichnisse.'

if [[ -r /etc/os-release ]]; then
  # shellcheck disable=SC1091
  source /etc/os-release
else
  die '/etc/os-release fehlt. Unterstuetzt werden Debian und Ubuntu.'
fi
case "${ID:-}:${ID_LIKE:-}" in
  debian:*|ubuntu:*|*:debian*) ;;
  *) die "Nicht unterstuetztes System: ${PRETTY_NAME:-unbekannt}. Nutze Debian 12+ oder Ubuntu 24.04+." ;;
esac

SERVER_NAME="${COREUI_SERVER_NAME:-_}"
PUBLIC_URL="${COREUI_PUBLIC_URL:-}"
INSTALL_OLLAMA="${COREUI_INSTALL_OLLAMA:-1}"
INSTALL_SEARXNG="${COREUI_INSTALL_SEARXNG:-1}"
INSTALL_BROWSE="${COREUI_INSTALL_BROWSE:-1}"
FORCE_CONFIG="${COREUI_FORCE_CONFIG:-0}"
SKIP_LORE="${COREUI_SKIP_LORE:-0}"
BASE_MODEL="${COREUI_BASE_MODEL:-}"
MODEL_NAME="${COREUI_MODEL_NAME:-ember-coreui:latest}"
LOCK_NAMESPACE="${COREUI_LOCK_NAMESPACE:-ember_coreui_native}"
SEARXNG_URL="${COREUI_SEARXNG_URL:-http://127.0.0.1:8888/search}"

[[ "$SERVER_NAME" =~ ^[A-Za-z0-9._-]+$ ]] || die 'COREUI_SERVER_NAME enthaelt ungueltige Zeichen.'
[[ "$MODEL_NAME" =~ ^[A-Za-z0-9._:/-]+$ ]] || die 'COREUI_MODEL_NAME enthaelt ungueltige Zeichen.'
[[ -z "$BASE_MODEL" || "$BASE_MODEL" =~ ^[A-Za-z0-9._:/-]+$ ]] || die 'COREUI_BASE_MODEL enthaelt ungueltige Zeichen.'
[[ "$LOCK_NAMESPACE" =~ ^[A-Za-z0-9_.:-]{1,40}$ ]] || die 'COREUI_LOCK_NAMESPACE enthaelt ungueltige Zeichen.'

if [[ -z "$PUBLIC_URL" ]]; then
  if [[ "$SERVER_NAME" == '_' ]]; then
    PUBLIC_URL='http://localhost'
  else
    PUBLIC_URL="http://$SERVER_NAME"
  fi
fi

ADMIN_EMAIL="${COREUI_ADMIN_EMAIL:-}"
ADMIN_PASSWORD="${COREUI_ADMIN_PASSWORD:-}"
ADMIN_NAME="${COREUI_ADMIN_NAME:-Operator}"
ADMIN_GENDER="${COREUI_ADMIN_GENDER:-}"

if [[ -z "$ADMIN_EMAIL" ]]; then
  [[ -t 0 ]] || die 'COREUI_ADMIN_EMAIL fehlt fuer eine nicht-interaktive Installation.'
  read -r -p 'Admin-E-Mail: ' ADMIN_EMAIL
fi
if [[ -z "$ADMIN_PASSWORD" ]]; then
  [[ -t 0 ]] || die 'COREUI_ADMIN_PASSWORD fehlt fuer eine nicht-interaktive Installation.'
  read -r -s -p 'Admin-Passwort, mindestens 12 Zeichen: ' ADMIN_PASSWORD
  printf '\n'
  read -r -s -p 'Admin-Passwort wiederholen: ' ADMIN_PASSWORD_CONFIRM
  printf '\n'
  [[ "$ADMIN_PASSWORD" == "$ADMIN_PASSWORD_CONFIRM" ]] || die 'Die Passwoerter stimmen nicht ueberein.'
fi
[[ ${#ADMIN_PASSWORD} -ge 12 ]] || die 'Das Admin-Passwort muss mindestens 12 Zeichen lang sein.'
[[ "$ADMIN_GENDER" == '' || "$ADMIN_GENDER" == 'm' || "$ADMIN_GENDER" == 'f' ]] || die 'COREUI_ADMIN_GENDER muss leer, m oder f sein.'

log "Installiere Systempakete fuer Ember CoreUI $COREUI_VERSION."
export DEBIAN_FRONTEND=noninteractive
apt-get update

PACKAGES=(
  ca-certificates
  curl
  ffmpeg
  mariadb-client
  mariadb-server
  nginx
  openssl
  poppler-utils
  php-cli
  php-curl
  php-fpm
  php-gd
  php-mbstring
  php-mysql
  php-xml
  php-zip
  python3-pip
  python3-venv
  unzip
)
if is_enabled "$INSTALL_SEARXNG"; then
  PACKAGES+=(docker.io)
fi
apt-get install -y "${PACKAGES[@]}"

command -v php >/dev/null || die 'PHP wurde nicht gefunden.'
command -v mariadb >/dev/null || die 'MariaDB-Client wurde nicht gefunden.'
command -v nginx >/dev/null || die 'Nginx wurde nicht gefunden.'

log 'Pruefe die paketierten PHP- und Python-Quellen.'
while IFS= read -r -d '' php_file; do
  php -l "$php_file" >/dev/null || die "PHP-Syntaxfehler in $php_file"
done < <(find "$PROJECT_ROOT/api" "$PROJECT_ROOT/scripts" "$PROJECT_ROOT/tools" -type f -name '*.php' -print0)
while IFS= read -r -d '' py_file; do
  python3 -c 'import pathlib,sys; p=pathlib.Path(sys.argv[1]); compile(p.read_text(encoding="utf-8"), str(p), "exec")' "$py_file" \
    || die "Python-Syntaxfehler in $py_file"
done < <(find "$PROJECT_ROOT/tools" -type f -name '*.py' -print0)

systemctl enable --now mariadb

FPM_UNIT="$(systemctl list-unit-files --type=service --no-legend 'php*-fpm.service' 2>/dev/null | awk '{print $1}' | sort -V | tail -n 1)"
[[ -n "$FPM_UNIT" ]] || die 'Kein PHP-FPM-Dienst gefunden.'
systemctl enable --now "$FPM_UNIT"

PHP_SOCKET="$(find /run/php -maxdepth 1 -type s -name 'php*-fpm.sock' -print 2>/dev/null | sort -V | tail -n 1)"
[[ -n "$PHP_SOCKET" ]] || die 'Kein PHP-FPM-Socket unter /run/php gefunden.'

PHP_VERSION_MM="$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')"
PHP_FPM_INI_DIR="/etc/php/$PHP_VERSION_MM/fpm/conf.d"
[[ -d "$PHP_FPM_INI_DIR" ]] || die "PHP-FPM-Konfigurationsordner fehlt: $PHP_FPM_INI_DIR"
cat > "$PHP_FPM_INI_DIR/99-ember-coreui.ini" <<'INI'
upload_max_filesize = 256M
post_max_size = 260M
max_execution_time = 900
max_input_time = 900
memory_limit = 768M
max_file_uploads = 10
session.cookie_httponly = 1
session.use_strict_mode = 1
expose_php = Off
INI
systemctl restart "$FPM_UNIT"

install -d -m 0770 -o www-data -g www-data \
  "$PROJECT_ROOT/logs" \
  "$PROJECT_ROOT/var/cache" \
  "$PROJECT_ROOT/var/console_media" \
  "$PROJECT_ROOT/var/profile_media" \
  "$PROJECT_ROOT/var/knowledge_uploads" \
  "$PROJECT_ROOT/var/ember_py" \
  "$PROJECT_ROOT/var/ember_frames" \
  "$PROJECT_ROOT/var/pdf_pages" \
  "$PROJECT_ROOT/var/worker-home" \
  "$PROJECT_ROOT/uploads/ember_browse" \
  "$PROJECT_ROOT/assets/chat_media" \
  "$PROJECT_ROOT/assets/profile_photos/pending" \
  "$PROJECT_ROOT/assets/profile_photos/approved"

REUSE_CONFIG=0
if [[ -f "$CONFIG_FILE" ]] && ! is_enabled "$FORCE_CONFIG"; then
  REUSE_CONFIG=1
  log 'Vorhandene api/config.local.php bleibt unveraendert.'
  DB_HOST="$(read_constant STU_DB_HOST || true)"
  DB_PORT="$(read_constant STU_DB_PORT || true)"
  DB_NAME="$(read_constant STU_DB_NAME || true)"
  DB_USER="$(read_constant STU_DB_USER || true)"
  DB_PASS="$(read_constant STU_DB_PASS || true)"
  MODEL_NAME="$(read_constant STU_EMBER_MODEL || printf '%s' "$MODEL_NAME")"
  SEARXNG_URL="$(read_constant STU_EMBER_WEB_URL || printf '%s' "$SEARXNG_URL")"
  INSTALL_BROWSE="$(read_constant STU_EMBER_BROWSE_ENABLED || printf '%s' "$INSTALL_BROWSE")"
else
  DB_HOST="${COREUI_DB_HOST:-127.0.0.1}"
  DB_PORT="${COREUI_DB_PORT:-3306}"
  DB_NAME="${COREUI_DB_NAME:-ember_coreui}"
  DB_USER="${COREUI_DB_USER:-ember_coreui}"
  DB_PASS="${COREUI_DB_PASS:-$(openssl rand -hex 24)}"
fi

[[ "$DB_HOST" == '127.0.0.1' || "$DB_HOST" == 'localhost' ]] || die 'Der automatische Installer unterstuetzt nur eine lokale MariaDB auf 127.0.0.1 oder localhost.'
[[ "$DB_PORT" =~ ^[0-9]{1,5}$ ]] || die 'Ungueltiger Datenbank-Port.'
[[ "$DB_NAME" =~ ^[A-Za-z0-9_]+$ ]] || die 'Ungueltiger Datenbankname.'
[[ "$DB_USER" =~ ^[A-Za-z0-9_]+$ ]] || die 'Ungueltiger Datenbanknutzer.'
[[ -n "$DB_PASS" ]] || die 'Das Datenbankpasswort ist leer.'
[[ "$DB_PASS" =~ ^[A-Za-z0-9._~!@%+=:-]+$ ]] || die 'Das Datenbankpasswort darf im automatischen Installer nur sichere ASCII-Zeichen ohne Leerzeichen oder Anfuehrungszeichen enthalten.'

if (( REUSE_CONFIG == 0 )); then
  DB_HOST_PHP="$(php_escape "$DB_HOST")"
  DB_NAME_PHP="$(php_escape "$DB_NAME")"
  DB_USER_PHP="$(php_escape "$DB_USER")"
  DB_PASS_PHP="$(php_escape "$DB_PASS")"
  PUBLIC_URL_PHP="$(php_escape "$PUBLIC_URL")"
  MODEL_NAME_PHP="$(php_escape "$MODEL_NAME")"
  SEARXNG_URL_PHP="$(php_escape "$SEARXNG_URL")"
  SCREENSHOT_DIR_PHP="$(php_escape "$PROJECT_ROOT/uploads/ember_browse")"
  if is_enabled "$INSTALL_BROWSE"; then BROWSE_BOOL=true; else BROWSE_BOOL=false; fi
  if [[ -n "$SEARXNG_URL" ]]; then WEB_BOOL=true; else WEB_BOOL=false; fi
  THREADS="$(getconf _NPROCESSORS_ONLN 2>/dev/null || printf '4')"
  [[ "$THREADS" =~ ^[0-9]+$ ]] || THREADS=4

  CONFIG_TMP="$(mktemp)"
  cat > "$CONFIG_TMP" <<PHP
<?php
declare(strict_types=1);

/* Generated by Ember CoreUI scripts/install-native.sh. */
define('STU_DB_HOST', '$DB_HOST_PHP');
define('STU_DB_PORT', $DB_PORT);
define('STU_DB_NAME', '$DB_NAME_PHP');
define('STU_DB_USER', '$DB_USER_PHP');
define('STU_DB_PASS', '$DB_PASS_PHP');

define('STU_PUBLIC_BASE_URL', '$PUBLIC_URL_PHP');
define('STU_SESSION_NAME', 'EMBERCOREUISESSID');
define('STU_ALLOW_REGISTRATION', false);
define('STU_DEBUG', false);

define('STU_EMBER_ENABLED', true);
define('STU_EMBER_USER_ID', 1);
define('STU_EMBER_CHARACTER_ID', 'ember');
define('STU_EMBER_CHARACTER_NAME', 'Ember');
define('STU_EMBER_MODEL', '$MODEL_NAME_PHP');
define('STU_EMBER_OLLAMA_URL', 'http://127.0.0.1:11434/api/chat');
define('STU_EMBER_LOCK_NAMESPACE', '$LOCK_NAMESPACE');
define('STU_EMBER_NUM_THREAD', $THREADS);
define('STU_EMBER_NUM_PREDICT', 6500);
define('STU_EMBER_NUM_CTX', 32768);
define('STU_EMBER_TEMPERATURE', 1.0);
define('STU_EMBER_TOP_P', 0.95);
define('STU_EMBER_TOP_K', 64);
define('STU_EMBER_TIMEOUT', 600);
define('STU_EMBER_TIMEOUT_RETRY', 600);
define('STU_EMBER_MAX_REPLY_CHARS', 60000);

define('STU_EMBER_MEMORY_ENABLED', true);
define('STU_EMBER_REFLECT_ENABLED', true);
define('STU_EMBER_LORE_ENABLED', true);
define('STU_EMBER_WEB_ENABLED', $WEB_BOOL);
define('STU_EMBER_WEB_URL', '$SEARXNG_URL_PHP');
define('STU_EMBER_BROWSE_ENABLED', $BROWSE_BOOL);
define('STU_EMBER_BROWSE_MAX_STEPS', 12);
define('STU_EMBER_BROWSE_AGENT_TIMEOUT', 420);
define('STU_EMBER_BROWSE_REPORT_TIMEOUT', 420);
define('STU_EMBER_BROWSE_AGENT_RETRIES', 0);
define('STU_EMBER_BROWSE_FRAME_QUALITY', 45);
define('STU_EMBER_BROWSE_FRAME_BURST', 3);
define('STU_EMBER_BROWSE_FRAME_INTERVAL_MS', 240);
define('STU_EMBER_BROWSE_FRAME_RETENTION_HOURS', 24);
define('STU_EMBER_BROWSE_SCREENSHOT_DIR', '$SCREENSHOT_DIR_PHP');
define('STU_EMBER_PY_ENABLED', false);
PHP
  install -m 0640 -o root -g www-data "$CONFIG_TMP" "$CONFIG_FILE"
fi

log "Lege MariaDB-Datenbank $DB_NAME an und importiere das Schema."
DB_PASS_SQL="$(printf '%s' "$DB_PASS" | sed "s/'/''/g")"
mariadb --protocol=socket --user=root <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'127.0.0.1' IDENTIFIED BY '$DB_PASS_SQL';
ALTER USER '$DB_USER'@'127.0.0.1' IDENTIFIED BY '$DB_PASS_SQL';
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS_SQL';
ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS_SQL';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'127.0.0.1';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
SQL

DB_CLIENT_TMP="$(mktemp)"
chmod 0600 "$DB_CLIENT_TMP"
cat > "$DB_CLIENT_TMP" <<CNF
[client]
host=$DB_HOST
port=$DB_PORT
user=$DB_USER
password=$DB_PASS
default-character-set=utf8mb4
CNF
mariadb --defaults-extra-file="$DB_CLIENT_TMP" "$DB_NAME" < "$PROJECT_ROOT/database/migrations/001_core.sql"
mariadb --defaults-extra-file="$DB_CLIENT_TMP" "$DB_NAME" < "$PROJECT_ROOT/database/migrations/002_coreui_management.sql"
mariadb --defaults-extra-file="$DB_CLIENT_TMP" "$DB_NAME" < "$PROJECT_ROOT/database/migrations/003_console_sessions.sql"
mariadb --defaults-extra-file="$DB_CLIENT_TMP" "$DB_NAME" < "$PROJECT_ROOT/database/migrations/004_profiles_knowledge.sql"
mariadb --defaults-extra-file="$DB_CLIENT_TMP" "$DB_NAME" < "$PROJECT_ROOT/database/migrations/005_thinking_attachments.sql"
mariadb --defaults-extra-file="$DB_CLIENT_TMP" "$DB_NAME" < "$PROJECT_ROOT/database/migrations/006_account_security.sql"

log 'Initialisiere Ember und das Administratorkonto.'
COREUI_ADMIN_EMAIL="$ADMIN_EMAIL" \
COREUI_ADMIN_PASSWORD="$ADMIN_PASSWORD" \
COREUI_ADMIN_NAME="$ADMIN_NAME" \
COREUI_ADMIN_GENDER="$ADMIN_GENDER" \
php "$PROJECT_ROOT/scripts/bootstrap.php"

if ! command -v ollama >/dev/null 2>&1; then
  if ! is_enabled "$INSTALL_OLLAMA"; then
    die 'Ollama fehlt. Installiere es oder setze COREUI_INSTALL_OLLAMA=1.'
  fi
  log 'Installiere Ollama mit dem offiziellen Linux-Installer.'
  OLLAMA_INSTALL_TMP="$(mktemp)"
  curl -fsSL https://ollama.com/install.sh -o "$OLLAMA_INSTALL_TMP"
  sh "$OLLAMA_INSTALL_TMP"
fi

if systemctl list-unit-files ollama.service >/dev/null 2>&1; then
  systemctl enable --now ollama
fi
wait_http 'http://127.0.0.1:11434/api/tags' 30 || die 'Ollama antwortet nicht auf 127.0.0.1:11434.'

if [[ -z "$BASE_MODEL" ]]; then
  BASE_MODEL="$(ollama list 2>/dev/null | awk 'NR > 1 && $1 ~ /^gemma4:/ {print $1; exit}')"
  BASE_MODEL="${BASE_MODEL:-gemma4:e4b}"
fi
[[ "$BASE_MODEL" =~ ^gemma4: ]] || warn "Basismodell $BASE_MODEL ist kein gemma4-Tag. Die Installation wird trotzdem fortgesetzt."

if ! ollama show "$BASE_MODEL" >/dev/null 2>&1; then
  log "Lade Ollama-Basismodell $BASE_MODEL. Das kann je nach Modell laenger dauern."
  ollama pull "$BASE_MODEL"
else
  log "Ollama-Basismodell $BASE_MODEL ist bereits vorhanden."
fi

if [[ "$MODEL_NAME" != "$BASE_MODEL" ]]; then
  MODELFILE_GENERATED="$PROJECT_ROOT/var/Modelfile.generated"
  sed "s|{{BASE_MODEL}}|$BASE_MODEL|g" "$PROJECT_ROOT/config/Modelfile.template" > "$MODELFILE_GENERATED"
  log "Erzeuge lokales Ember CoreUI-Modell $MODEL_NAME aus $BASE_MODEL."
  ollama create "$MODEL_NAME" -f "$MODELFILE_GENERATED"
else
  warn 'COREUI_MODEL_NAME entspricht dem Basismodell. Das eigene Modelfile wird deshalb nicht erzeugt.'
fi

if ! is_enabled "$SKIP_LORE"; then
  log 'Importiere die lokalen Lore-Dokumente in RAG-Lite.'
  php "$PROJECT_ROOT/tools/ingest_docx_knowledge.php" \
    --file="$PROJECT_ROOT/docs/S.U. MASTER BIBEL v10.4.docx" \
    --source=bibel_v10_4 \
    --truncate
  php "$PROJECT_ROOT/tools/ingest_docx_knowledge.php" \
    --file="$PROJECT_ROOT/docs/STU_KOMPENDIUM_V6.docx" \
    --source=kompendium_v6 \
    --truncate
else
  warn 'Lore-Import wurde durch COREUI_SKIP_LORE=1 uebersprungen.'
fi

if is_enabled "$INSTALL_SEARXNG"; then
  command -v docker >/dev/null || die 'Docker fehlt fuer SearXNG.'
  systemctl enable --now docker
  install -d -m 0770 -o www-data -g www-data \
    "$PROJECT_ROOT/var/searxng/config" \
    "$PROJECT_ROOT/var/searxng/cache"
  SEARXNG_SECRET="$(openssl rand -hex 32)"
  sed "s|{{SEARXNG_SECRET}}|$SEARXNG_SECRET|g" \
    "$PROJECT_ROOT/config/searxng-settings.yml.template" \
    > "$PROJECT_ROOT/var/searxng/config/settings.yml"
  chmod 0640 "$PROJECT_ROOT/var/searxng/config/settings.yml"

  if docker container inspect ember-coreui-searxng >/dev/null 2>&1; then
    EXISTING_MOUNT="$(docker inspect -f '{{range .Mounts}}{{if eq .Destination "/etc/searxng"}}{{.Source}}{{end}}{{end}}' ember-coreui-searxng)"
    [[ "$EXISTING_MOUNT" == "$PROJECT_ROOT/var/searxng/config" ]] || die 'Ein fremder Container namens ember-coreui-searxng existiert. Entferne oder benenne ihn manuell.'
    docker restart ember-coreui-searxng >/dev/null
  else
    log 'Starte die private SearXNG-Instanz auf 127.0.0.1:8888.'
    docker pull docker.io/searxng/searxng:latest
    docker run -d \
      --name ember-coreui-searxng \
      --restart unless-stopped \
      -p 127.0.0.1:8888:8080 \
      -e SEARXNG_BASE_URL=http://127.0.0.1:8888/ \
      -e FORCE_OWNERSHIP=true \
      -v "$PROJECT_ROOT/var/searxng/config:/etc/searxng:rw" \
      -v "$PROJECT_ROOT/var/searxng/cache:/var/cache/searxng:rw" \
      docker.io/searxng/searxng:latest >/dev/null
  fi
  wait_http 'http://127.0.0.1:8888/search?q=ember&format=json' 45 || die 'SearXNG antwortet nicht mit aktivierter JSON-Suche.'
fi

if is_enabled "$INSTALL_BROWSE"; then
  if ! is_enabled "$INSTALL_SEARXNG" && [[ "$SEARXNG_URL" == 'http://127.0.0.1:8888/search' ]]; then
    die 'Browse ist aktiviert, aber die lokale SearXNG-Installation wurde deaktiviert und keine externe COREUI_SEARXNG_URL gesetzt.'
  fi
  log 'Installiere den asynchronen Playwright-Browse-Worker.'
  python3 -m venv "$PROJECT_ROOT/var/venv"
  "$PROJECT_ROOT/var/venv/bin/python" -m pip install --upgrade pip wheel
  "$PROJECT_ROOT/var/venv/bin/python" -m pip install -r "$PROJECT_ROOT/tools/requirements-browse.txt"
  PLAYWRIGHT_BROWSERS_PATH="$PROJECT_ROOT/var/playwright" \
    "$PROJECT_ROOT/var/venv/bin/python" -m playwright install-deps chromium
  PLAYWRIGHT_BROWSERS_PATH="$PROJECT_ROOT/var/playwright" \
    "$PROJECT_ROOT/var/venv/bin/python" -m playwright install chromium

  sed \
    -e "s|@@COREUI_ROOT@@|$PROJECT_ROOT|g" \
    "$PROJECT_ROOT/config/ember-browse-worker.service.template" \
    > /etc/systemd/system/ember-coreui-browse.service
fi

chgrp -R www-data "$PROJECT_ROOT"
chmod -R g+rX,o-rwx "$PROJECT_ROOT"
chown -R www-data:www-data \
  "$PROJECT_ROOT/logs" \
  "$PROJECT_ROOT/var/cache" \
  "$PROJECT_ROOT/var/console_media" \
  "$PROJECT_ROOT/var/profile_media" \
  "$PROJECT_ROOT/var/knowledge_uploads" \
  "$PROJECT_ROOT/var/ember_py" \
  "$PROJECT_ROOT/var/ember_frames" \
  "$PROJECT_ROOT/var/pdf_pages" \
  "$PROJECT_ROOT/var/worker-home" \
  "$PROJECT_ROOT/uploads/ember_browse" \
  "$PROJECT_ROOT/assets/chat_media" \
  "$PROJECT_ROOT/assets/profile_photos"
chmod 0770 \
  "$PROJECT_ROOT/logs" \
  "$PROJECT_ROOT/var/cache" \
  "$PROJECT_ROOT/var/console_media" \
  "$PROJECT_ROOT/var/profile_media" \
  "$PROJECT_ROOT/var/knowledge_uploads" \
  "$PROJECT_ROOT/var/ember_py" \
  "$PROJECT_ROOT/var/ember_frames" \
  "$PROJECT_ROOT/var/pdf_pages" \
  "$PROJECT_ROOT/var/worker-home" \
  "$PROJECT_ROOT/uploads/ember_browse" \
  "$PROJECT_ROOT/assets/chat_media" \
  "$PROJECT_ROOT/assets/profile_photos" \
  "$PROJECT_ROOT/assets/profile_photos/pending" \
  "$PROJECT_ROOT/assets/profile_photos/approved"
chmod 0750 "$PROJECT_ROOT/var"
chmod 0640 "$CONFIG_FILE"
chmod 0750 "$PROJECT_ROOT/scripts/install-native.sh" "$PROJECT_ROOT/scripts/preflight-native.sh" 2>/dev/null || true

if is_enabled "$INSTALL_BROWSE"; then
  systemctl daemon-reload
  systemctl enable --now ember-coreui-browse.service
fi

log 'Konfiguriere Nginx.'
sed \
  -e "s|@@COREUI_ROOT@@|$PROJECT_ROOT|g" \
  -e "s|@@SERVER_NAME@@|$SERVER_NAME|g" \
  -e "s|@@PHP_SOCKET@@|$PHP_SOCKET|g" \
  "$PROJECT_ROOT/config/nginx.conf.template" \
  > /etc/nginx/sites-available/ember-coreui.conf
ln -sfn /etc/nginx/sites-available/ember-coreui.conf /etc/nginx/sites-enabled/ember-coreui.conf
if is_enabled "${COREUI_DISABLE_NGINX_DEFAULT:-0}" && [[ -L /etc/nginx/sites-enabled/default ]]; then
  unlink /etc/nginx/sites-enabled/default
fi
nginx -t
systemctl enable --now nginx
systemctl reload nginx

HEALTH_HOST="$SERVER_NAME"
[[ "$HEALTH_HOST" == '_' ]] && HEALTH_HOST='localhost'
if curl -fsS --max-time 10 -H "Host: $HEALTH_HOST" 'http://127.0.0.1/api/health.php' >/dev/null; then
  log "Installation erfolgreich. Oeffne $PUBLIC_URL/"
else
  warn 'Der Nginx-Healthcheck ist noch nicht gruen. Fuehre scripts/preflight.sh aus und pruefe journalctl sowie logs/stu_error.log.'
fi

log 'Sicherheitsnotiz: MariaDB, Ollama und SearXNG bleiben auf Loopback. Nach aussen werden nur HTTP und spaeter optional HTTPS benoetigt.'
