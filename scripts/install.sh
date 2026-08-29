#!/usr/bin/env bash
set -Eeuo pipefail
IFS=$'\n\t'
umask 027

PROJECT_ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
COREUI_VERSION="$(tr -d '[:space:]' < "$PROJECT_ROOT/VERSION")"
COMPOSE_FILE="$PROJECT_ROOT/compose.yaml"
ENV_FILE="$PROJECT_ROOT/var/compose.env"
CONFIG_FILE="$PROJECT_ROOT/api/config.local.php"
MODEL_OWNER_FILE="$PROJECT_ROOT/var/model.owner"
COMPOSE_PROJECT='ember-coreui'
COMPOSE_CMD=()
TEMP_FILES=()

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

cleanup() {
  local file
  for file in "${TEMP_FILES[@]:-}"; do
    [[ -z "$file" || ! -f "$file" ]] || rm -f -- "$file"
  done
}
trap cleanup EXIT

is_enabled() {
  case "${1:-}" in
    1|true|TRUE|yes|YES|on|ON) return 0 ;;
    *) return 1 ;;
  esac
}

php_escape() {
  printf '%s' "$1" | sed -e 's/\\/\\\\/g' -e "s/'/\\\\'/g"
}

valid_port() {
  [[ "$1" =~ ^[0-9]{4,5}$ ]] && (( 10#$1 >= 1024 && 10#$1 <= 65535 ))
}

read_saved_env() {
  local key="$1"
  [[ -f "$ENV_FILE" ]] || return 1
  awk -F= -v wanted="$key" '
    $1 == wanted {
      sub(/^[^=]*=/, "")
      print
      exit
    }
  ' "$ENV_FILE"
}

ensure_apt_updated() {
  if [[ "${APT_UPDATED:-0}" != '1' ]]; then
    export DEBIAN_FRONTEND=noninteractive
    apt-get update
    APT_UPDATED=1
  fi
}

ensure_host_tools() {
  local packages=()
  command -v curl >/dev/null 2>&1 || packages+=(ca-certificates curl)
  command -v openssl >/dev/null 2>&1 || packages+=(openssl)
  command -v python3 >/dev/null 2>&1 || packages+=(python3)
  command -v ss >/dev/null 2>&1 || packages+=(iproute2)
  if (( ${#packages[@]} > 0 )); then
    ensure_apt_updated
    apt-get install -y "${packages[@]}"
  fi
}

ensure_docker() {
  if ! command -v docker >/dev/null 2>&1; then
    is_enabled "$INSTALL_DOCKER" || die 'Docker fehlt. Installiere Docker oder setze COREUI_INSTALL_DOCKER=1.'
    log 'Installiere Docker fuer den isolierten CoreUI-Stack.'
    ensure_apt_updated
    apt-get install -y docker.io
  fi

  if command -v systemctl >/dev/null 2>&1 && ! systemctl is-active --quiet docker; then
    systemctl enable --now docker
  fi
  docker info >/dev/null 2>&1 || die 'Docker ist installiert, aber der Daemon ist nicht erreichbar.'

  if docker compose version >/dev/null 2>&1; then
    COMPOSE_CMD=(docker compose)
    return
  fi
  if command -v docker-compose >/dev/null 2>&1; then
    COMPOSE_CMD=(docker-compose)
    return
  fi

  is_enabled "$INSTALL_DOCKER" || die 'Docker Compose fehlt. Installiere Compose oder setze COREUI_INSTALL_DOCKER=1.'
  ensure_apt_updated
  if apt-cache show docker-compose-plugin >/dev/null 2>&1; then
    apt-get install -y docker-compose-plugin
  elif apt-cache show docker-compose-v2 >/dev/null 2>&1; then
    apt-get install -y docker-compose-v2
  else
    apt-get install -y docker-compose
  fi

  if docker compose version >/dev/null 2>&1; then
    COMPOSE_CMD=(docker compose)
  elif command -v docker-compose >/dev/null 2>&1; then
    COMPOSE_CMD=(docker-compose)
  else
    die 'Docker Compose konnte nicht eingerichtet werden.'
  fi
}

compose() {
  "${COMPOSE_CMD[@]}" \
    --project-name "$COMPOSE_PROJECT" \
    --env-file "$ENV_FILE" \
    --file "$COMPOSE_FILE" \
    "$@"
}

wait_http() {
  local url="$1"
  local attempts="${2:-60}"
  local count=0
  while (( count < attempts )); do
    if curl -fsS --max-time 5 "$url" >/dev/null 2>&1; then
      return 0
    fi
    count=$((count + 1))
    sleep 2
  done
  return 1
}

wait_healthy() {
  local service="$1"
  local attempts="${2:-60}"
  local count=0
  local container_id=''
  local status=''
  while (( count < attempts )); do
    container_id="$(compose ps -q "$service" 2>/dev/null || true)"
    if [[ -n "$container_id" ]]; then
      status="$(docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' "$container_id" 2>/dev/null || true)"
      [[ "$status" == 'healthy' || "$status" == 'running' ]] && return 0
      [[ "$status" == 'unhealthy' || "$status" == 'exited' || "$status" == 'dead' ]] && return 1
    fi
    count=$((count + 1))
    sleep 2
  done
  return 1
}

port_is_listening() {
  local port="$1"
  ss -H -ltn 2>/dev/null | awk -v suffix=":$port" '
    $4 ~ (suffix "$") { found = 1 }
    END { exit(found ? 0 : 1) }
  '
}

stack_service_running() {
  local service="$1"
  local container_id=''
  container_id="$(compose ps -q "$service" 2>/dev/null || true)"
  [[ -n "$container_id" ]] || return 1
  [[ "$(docker inspect --format '{{.State.Running}}' "$container_id" 2>/dev/null || true)" == 'true' ]]
}

assert_stack_identity() {
  local ids=()
  local id
  local working_dir
  mapfile -t ids < <(docker ps -aq --filter "label=com.docker.compose.project=$COMPOSE_PROJECT")
  if (( ${#ids[@]} == 0 )); then
    EXISTING_STACK=0
    return
  fi

  for id in "${ids[@]}"; do
    working_dir="$(docker inspect --format '{{index .Config.Labels "com.docker.compose.project.working_dir"}}' "$id" 2>/dev/null || true)"
    [[ "$working_dir" == "$PROJECT_ROOT" ]] \
      || die "Ein Compose-Projekt namens $COMPOSE_PROJECT gehoert zu einem anderen Pfad: ${working_dir:-unbekannt}"
  done
  EXISTING_STACK=1
}

assert_ports_available() {
  local ports=("$HTTP_PORT" "$PHP_PORT" "$DB_PORT")
  local labels=('HTTP' 'PHP-FPM' 'MariaDB')
  local services=('web' 'php' 'database')
  local saved_ports=("${SAVED_HTTP_PORT:-}" "${SAVED_PHP_PORT:-}" "${SAVED_DB_PORT:-}")
  local seen=' '
  local index
  if is_enabled "$INSTALL_SEARXNG"; then
    ports+=("$SEARXNG_PORT")
    labels+=('SearXNG')
    services+=('searxng')
    saved_ports+=("${SAVED_SEARXNG_PORT:-}")
  fi

  for index in "${!ports[@]}"; do
    [[ "$seen" != *" ${ports[$index]} "* ]] || die "Port ${ports[$index]} wurde im CoreUI-Stack doppelt vergeben."
    seen+="${ports[$index]} "
    if port_is_listening "${ports[$index]}"; then
      if (( EXISTING_STACK == 0 )) \
          || [[ "${ports[$index]}" != "${saved_ports[$index]}" ]] \
          || ! stack_service_running "${services[$index]}"; then
        die "${labels[$index]}-Port ${ports[$index]} ist bereits belegt. Waehle einen anderen COREUI_*-PORT."
      fi
    fi
  done
}

prepare_permissions() {
  chgrp 33 "$PROJECT_ROOT"
  chmod 0750 "$PROJECT_ROOT"

  install -d -m 0750 -o root -g 33 \
    "$PROJECT_ROOT/var" \
    "$PROJECT_ROOT/var/nginx" \
    "$PROJECT_ROOT/var/php-fpm"

  install -d -m 0770 -o 33 -g 33 \
    "$PROJECT_ROOT/logs" \
    "$PROJECT_ROOT/var/cache" \
    "$PROJECT_ROOT/var/console_media" \
    "$PROJECT_ROOT/var/profile_media" \
    "$PROJECT_ROOT/var/knowledge_uploads" \
    "$PROJECT_ROOT/var/ember_py" \
    "$PROJECT_ROOT/var/ember_frames" \
    "$PROJECT_ROOT/var/pdf_pages" \
    "$PROJECT_ROOT/var/browser-home" \
    "$PROJECT_ROOT/uploads/ember_browse" \
    "$PROJECT_ROOT/assets/chat_media" \
    "$PROJECT_ROOT/assets/profile_photos" \
    "$PROJECT_ROOT/assets/profile_photos/pending" \
    "$PROJECT_ROOT/assets/profile_photos/approved"

  if [[ ! -d "$PROJECT_ROOT/var/mariadb" ]]; then
    install -d -m 0700 -o root -g root "$PROJECT_ROOT/var/mariadb"
  fi
  if [[ ! -d "$PROJECT_ROOT/var/searxng/config" ]]; then
    install -d -m 0770 -o root -g root \
      "$PROJECT_ROOT/var/searxng" \
      "$PROJECT_ROOT/var/searxng/config"
  fi
  if [[ ! -d "$PROJECT_ROOT/var/searxng/cache" ]]; then
    install -d -m 0770 -o root -g root "$PROJECT_ROOT/var/searxng/cache"
  fi
}

write_runtime_files() {
  local env_tmp config_tmp nginx_tmp fpm_tmp searxng_tmp
  local db_pass_php public_url_php model_php searxng_url_php lock_namespace_php
  local web_bool browse_bool

  env_tmp="$(mktemp)"
  config_tmp="$(mktemp)"
  nginx_tmp="$(mktemp)"
  fpm_tmp="$(mktemp)"
  searxng_tmp="$(mktemp)"
  TEMP_FILES+=("$env_tmp" "$config_tmp" "$nginx_tmp" "$fpm_tmp" "$searxng_tmp")

  cat > "$env_tmp" <<ENV
COMPOSE_PROJECT_NAME=$COMPOSE_PROJECT
COREUI_HTTP_PORT=$HTTP_PORT
COREUI_PHP_PORT=$PHP_PORT
COREUI_DB_PORT=$DB_PORT
COREUI_SEARXNG_PORT=$SEARXNG_PORT
COREUI_DB_NAME=$DB_NAME
COREUI_DB_USER=$DB_USER
COREUI_DB_PASS=$DB_PASS
COREUI_DB_ROOT_PASS=$DB_ROOT_PASS
COREUI_MODEL_NAME=$MODEL_NAME
COREUI_LOCK_NAMESPACE=$LOCK_NAMESPACE
COREUI_BIND_ADDRESS=$BIND_ADDRESS
COREUI_INSTALL_SEARXNG=$INSTALL_SEARXNG
COREUI_INSTALL_BROWSE=$INSTALL_BROWSE
COREUI_SEARXNG_URL=$SEARXNG_URL
COREUI_SEARXNG_SECRET=$SEARXNG_SECRET
ENV
  install -m 0600 -o root -g root "$env_tmp" "$ENV_FILE"

  if [[ ! -f "$CONFIG_FILE" || "$FORCE_CONFIG" == '1' ]]; then
    db_pass_php="$(php_escape "$DB_PASS")"
    public_url_php="$(php_escape "$PUBLIC_URL")"
    model_php="$(php_escape "$MODEL_NAME")"
    searxng_url_php="$(php_escape "$SEARXNG_URL")"
    lock_namespace_php="$(php_escape "$LOCK_NAMESPACE")"
    if [[ -n "$SEARXNG_URL" ]]; then web_bool=true; else web_bool=false; fi
    if is_enabled "$INSTALL_BROWSE"; then browse_bool=true; else browse_bool=false; fi

    cat > "$config_tmp" <<PHP
<?php
declare(strict_types=1);

/* Generated by Ember CoreUI scripts/install.sh for the isolated Compose stack. */
define('STU_DB_HOST', '127.0.0.1');
define('STU_DB_PORT', $DB_PORT);
define('STU_DB_NAME', '$DB_NAME');
define('STU_DB_USER', '$DB_USER');
define('STU_DB_PASS', '$db_pass_php');

define('STU_PUBLIC_BASE_URL', '$public_url_php');
define('STU_SESSION_NAME', 'EMBERCOREUISESSID');
define('STU_ALLOW_REGISTRATION', false);
define('STU_DEBUG', false);

define('STU_EMBER_ENABLED', true);
define('STU_EMBER_USER_ID', 1);
define('STU_EMBER_CHARACTER_ID', 'ember');
define('STU_EMBER_CHARACTER_NAME', 'Ember');
define('STU_EMBER_MODEL', '$model_php');
define('STU_EMBER_OLLAMA_URL', 'http://127.0.0.1:11434/api/chat');
define('STU_EMBER_LOCK_NAMESPACE', '$lock_namespace_php');
define('STU_EMBER_NUM_THREAD', $THREADS);
define('STU_EMBER_NUM_CTX', 32768);
define('STU_EMBER_NUM_PREDICT', 6500);
define('STU_EMBER_TIMEOUT', 600);
define('STU_EMBER_TIMEOUT_RETRY', 600);
define('STU_EMBER_MAX_REPLY_CHARS', 60000);
define('STU_EMBER_TEMPERATURE', 1.0);
define('STU_EMBER_TOP_P', 0.95);
define('STU_EMBER_TOP_K', 64);

define('STU_EMBER_MEMORY_ENABLED', true);
define('STU_EMBER_REFLECT_ENABLED', true);
define('STU_EMBER_LORE_ENABLED', true);
define('STU_EMBER_WEB_ENABLED', $web_bool);
define('STU_EMBER_WEB_URL', '$searxng_url_php');
define('STU_EMBER_BROWSE_ENABLED', $browse_bool);
define('STU_EMBER_BROWSE_MAX_STEPS', 12);
define('STU_EMBER_BROWSE_AGENT_TIMEOUT', 420);
define('STU_EMBER_BROWSE_REPORT_TIMEOUT', 420);
define('STU_EMBER_BROWSE_AGENT_RETRIES', 0);
define('STU_EMBER_BROWSE_FRAME_QUALITY', 45);
define('STU_EMBER_BROWSE_FRAME_BURST', 3);
define('STU_EMBER_BROWSE_FRAME_INTERVAL_MS', 240);
define('STU_EMBER_BROWSE_FRAME_RETENTION_HOURS', 24);
define('STU_EMBER_BROWSE_SCREENSHOT_DIR', '/var/www/coreui/uploads/ember_browse');
define('STU_EMBER_PY_ENABLED', false);
PHP
    install -m 0640 -o root -g 33 "$config_tmp" "$CONFIG_FILE"
  else
    log 'Vorhandene api/config.local.php bleibt unveraendert.'
  fi

  sed \
    -e "s|@@BIND_ADDRESS@@|$BIND_ADDRESS|g" \
    -e "s|@@HTTP_PORT@@|$HTTP_PORT|g" \
    -e "s|@@PHP_PORT@@|$PHP_PORT|g" \
    "$PROJECT_ROOT/config/runtime/nginx.conf.template" > "$nginx_tmp"
  install -m 0640 -o root -g 33 "$nginx_tmp" "$PROJECT_ROOT/var/nginx/coreui.conf"

  sed -e "s|@@PHP_PORT@@|$PHP_PORT|g" \
    "$PROJECT_ROOT/config/runtime/php-fpm.conf.template" > "$fpm_tmp"
  install -m 0640 -o root -g 33 "$fpm_tmp" "$PROJECT_ROOT/var/php-fpm/zz-coreui.conf"

  sed \
    -e "s|{{SEARXNG_SECRET}}|$SEARXNG_SECRET|g" \
    -e "s|{{SEARXNG_PORT}}|$SEARXNG_PORT|g" \
    "$PROJECT_ROOT/config/runtime/searxng-settings.yml.template" > "$searxng_tmp"
  install -m 0640 -o root -g root "$searxng_tmp" "$PROJECT_ROOT/var/searxng/config/settings.yml"
}

ensure_ollama_model() {
  local install_tmp
  local owned_model=''

  if ! command -v ollama >/dev/null 2>&1; then
    is_enabled "$INSTALL_OLLAMA" || die 'Ollama fehlt. Installiere es oder setze COREUI_INSTALL_OLLAMA=1.'
    log 'Installiere Ollama mit dem offiziellen Linux-Installer.'
    install_tmp="$(mktemp)"
    TEMP_FILES+=("$install_tmp")
    curl -fsSL https://ollama.com/install.sh -o "$install_tmp"
    sh "$install_tmp"
    if command -v systemctl >/dev/null 2>&1 && systemctl list-unit-files ollama.service >/dev/null 2>&1; then
      systemctl enable --now ollama
    fi
  fi

  wait_http 'http://127.0.0.1:11434/api/tags' 30 \
    || die 'Ollama antwortet nicht auf 127.0.0.1:11434. Ein vorhandener Ollama-Dienst wird vom Parallelinstaller nicht umkonfiguriert.'

  if [[ -z "$BASE_MODEL" ]]; then
    BASE_MODEL="$(ollama list 2>/dev/null | awk 'NR > 1 && $1 ~ /^gemma4:/ {print $1; exit}')"
    BASE_MODEL="${BASE_MODEL:-gemma4:e4b}"
  fi
  [[ "$MODEL_NAME" != "$BASE_MODEL" ]] \
    || die 'COREUI_MODEL_NAME darf im Isolationsmodus nicht dem Basismodell entsprechen.'

  if ! ollama show "$BASE_MODEL" >/dev/null 2>&1; then
    log "Lade Ollama-Basismodell $BASE_MODEL."
    ollama pull "$BASE_MODEL"
  else
    log "Ollama-Basismodell $BASE_MODEL bleibt unveraendert und wird wiederverwendet."
  fi

  [[ -f "$MODEL_OWNER_FILE" ]] && owned_model="$(tr -d '\r\n' < "$MODEL_OWNER_FILE")"
  if ollama show "$MODEL_NAME" >/dev/null 2>&1 \
      && [[ "$owned_model" != "$MODEL_NAME" ]] \
      && ! is_enabled "$ADOPT_MODEL"; then
    die "Das Modell $MODEL_NAME existiert bereits, ist aber nicht als CoreUI-eigen markiert. Waehle einen anderen Namen oder setze COREUI_ADOPT_MODEL=1 bewusst."
  fi

  sed "s|{{BASE_MODEL}}|$BASE_MODEL|g" \
    "$PROJECT_ROOT/config/Modelfile.template" > "$PROJECT_ROOT/var/Modelfile.generated"
  chown root:33 "$PROJECT_ROOT/var/Modelfile.generated"
  chmod 0640 "$PROJECT_ROOT/var/Modelfile.generated"

  log "Erzeuge das getrennte Ollama-Modell $MODEL_NAME aus $BASE_MODEL."
  ollama create "$MODEL_NAME" -f "$PROJECT_ROOT/var/Modelfile.generated"
  printf '%s\n' "$MODEL_NAME" > "$MODEL_OWNER_FILE"
  chown root:33 "$MODEL_OWNER_FILE"
  chmod 0640 "$MODEL_OWNER_FILE"
}

[[ "${EUID:-$(id -u)}" -eq 0 ]] || die 'Bitte mit sudo oder als root ausfuehren.'
[[ -f "$PROJECT_ROOT/app.html" ]] || die 'app.html fehlt. Das Installationspaket ist unvollstaendig.'
[[ -f "$PROJECT_ROOT/settings.html" ]] || die 'settings.html fehlt. Das Installationspaket ist unvollstaendig.'
[[ -f "$PROJECT_ROOT/config/runtime/nginx.conf.template" ]] || die 'Runtime-Nginx-Template fehlt.'
[[ -f "$COMPOSE_FILE" ]] || die 'compose.yaml fehlt. Das Installationspaket ist unvollstaendig.'
[[ -f "$PROJECT_ROOT/scripts/migrate.sh" ]] || die 'scripts/migrate.sh fehlt. Das Installationspaket ist unvollstaendig.'
[[ -f "$PROJECT_ROOT/database/migrations/001_core.sql" ]] || die 'SQL-Basisschema fehlt.'
[[ -f "$PROJECT_ROOT/database/migrations/002_coreui_management.sql" ]] || die 'SQL-Verwaltungsschema fehlt.'
[[ -f "$PROJECT_ROOT/database/migrations/003_console_sessions.sql" ]] || die 'SQL-Sitzungsschema fehlt.'
[[ -f "$PROJECT_ROOT/database/migrations/004_profiles_knowledge.sql" ]] || die 'SQL-Profil- und Knowledge-Schema fehlt.'
[[ -f "$PROJECT_ROOT/database/migrations/005_thinking_attachments.sql" ]] || die 'SQL-Thinking- und Anhangschema fehlt.'
[[ -f "$PROJECT_ROOT/database/migrations/006_account_security.sql" ]] || die 'SQL-Kontosicherheitsschema fehlt.'
[[ "$PROJECT_ROOT" =~ ^/[A-Za-z0-9._/-]+$ ]] || die 'Der Projektpfad enthaelt nicht unterstuetzte Zeichen.'
[[ "$PROJECT_ROOT" != /root/* ]] || die 'Bitte das Paket dauerhaft nach /opt/ember-coreui verschieben.'
[[ "$PROJECT_ROOT" != /home/* ]] || die 'Bitte das Paket dauerhaft nach /opt/ember-coreui verschieben.'

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

# Muss vor der Erzeugung neuer Zugangsdaten laufen, da minimale Server-Images
# openssl und selbst curl nicht zwingend vorinstalliert haben.
ensure_host_tools

REQUEST_HTTP_PORT="${COREUI_HTTP_PORT:-}"
REQUEST_PHP_PORT="${COREUI_PHP_PORT:-}"
REQUEST_DB_PORT="${COREUI_DB_PORT:-}"
REQUEST_SEARXNG_PORT="${COREUI_SEARXNG_PORT:-}"
REQUEST_DB_NAME="${COREUI_DB_NAME:-}"
REQUEST_DB_USER="${COREUI_DB_USER:-}"
REQUEST_DB_PASS="${COREUI_DB_PASS:-}"
REQUEST_DB_ROOT_PASS="${COREUI_DB_ROOT_PASS:-}"
REQUEST_MODEL_NAME="${COREUI_MODEL_NAME:-}"
REQUEST_LOCK_NAMESPACE="${COREUI_LOCK_NAMESPACE:-}"
REQUEST_BIND_ADDRESS="${COREUI_BIND_ADDRESS:-}"
REQUEST_INSTALL_SEARXNG="${COREUI_INSTALL_SEARXNG:-}"
REQUEST_INSTALL_BROWSE="${COREUI_INSTALL_BROWSE:-}"
REQUEST_SEARXNG_URL="${COREUI_SEARXNG_URL:-}"

SAVED_HTTP_PORT="$(read_saved_env COREUI_HTTP_PORT || true)"
SAVED_PHP_PORT="$(read_saved_env COREUI_PHP_PORT || true)"
SAVED_DB_PORT="$(read_saved_env COREUI_DB_PORT || true)"
SAVED_SEARXNG_PORT="$(read_saved_env COREUI_SEARXNG_PORT || true)"
SAVED_DB_NAME="$(read_saved_env COREUI_DB_NAME || true)"
SAVED_DB_USER="$(read_saved_env COREUI_DB_USER || true)"
SAVED_DB_PASS="$(read_saved_env COREUI_DB_PASS || true)"
SAVED_DB_ROOT_PASS="$(read_saved_env COREUI_DB_ROOT_PASS || true)"
SAVED_MODEL_NAME="$(read_saved_env COREUI_MODEL_NAME || true)"
SAVED_LOCK_NAMESPACE="$(read_saved_env COREUI_LOCK_NAMESPACE || true)"
SAVED_BIND_ADDRESS="$(read_saved_env COREUI_BIND_ADDRESS || true)"
SAVED_INSTALL_SEARXNG="$(read_saved_env COREUI_INSTALL_SEARXNG || true)"
SAVED_INSTALL_BROWSE="$(read_saved_env COREUI_INSTALL_BROWSE || true)"
SAVED_SEARXNG_URL="$(read_saved_env COREUI_SEARXNG_URL || true)"
SAVED_SEARXNG_SECRET="$(read_saved_env COREUI_SEARXNG_SECRET || true)"

if [[ -e "$PROJECT_ROOT/var/mariadb/ibdata1" || -d "$PROJECT_ROOT/var/mariadb/mysql" ]]; then
  if [[ -n "$REQUEST_DB_NAME" && "$REQUEST_DB_NAME" != "$SAVED_DB_NAME" \
      || -n "$REQUEST_DB_USER" && "$REQUEST_DB_USER" != "$SAVED_DB_USER" \
      || -n "$REQUEST_DB_PASS" && "$REQUEST_DB_PASS" != "$SAVED_DB_PASS" \
      || -n "$REQUEST_DB_ROOT_PASS" && "$REQUEST_DB_ROOT_PASS" != "$SAVED_DB_ROOT_PASS" ]]; then
    die 'Datenbankname oder Zugangsdaten einer initialisierten Container-Datenbank koennen nicht ueber den Installer rotiert werden. Nutze dafuer eine kontrollierte MariaDB-Migration.'
  fi
fi

HTTP_PORT="${REQUEST_HTTP_PORT:-${SAVED_HTTP_PORT:-9080}}"
PHP_PORT="${REQUEST_PHP_PORT:-${SAVED_PHP_PORT:-19073}}"
DB_PORT="${REQUEST_DB_PORT:-${SAVED_DB_PORT:-13306}}"
SEARXNG_PORT="${REQUEST_SEARXNG_PORT:-${SAVED_SEARXNG_PORT:-18889}}"
DB_NAME="${REQUEST_DB_NAME:-${SAVED_DB_NAME:-ember_coreui}}"
DB_USER="${REQUEST_DB_USER:-${SAVED_DB_USER:-ember_coreui}}"
DB_PASS="${REQUEST_DB_PASS:-${SAVED_DB_PASS:-$(openssl rand -hex 24)}}"
DB_ROOT_PASS="${REQUEST_DB_ROOT_PASS:-${SAVED_DB_ROOT_PASS:-$(openssl rand -hex 24)}}"
MODEL_NAME="${REQUEST_MODEL_NAME:-${SAVED_MODEL_NAME:-ember-coreui:latest}}"
LOCK_NAMESPACE="${REQUEST_LOCK_NAMESPACE:-${SAVED_LOCK_NAMESPACE:-ember_coreui}}"
BIND_ADDRESS="${REQUEST_BIND_ADDRESS:-${SAVED_BIND_ADDRESS:-127.0.0.1}}"
INSTALL_SEARXNG="${REQUEST_INSTALL_SEARXNG:-${SAVED_INSTALL_SEARXNG:-1}}"
INSTALL_BROWSE="${REQUEST_INSTALL_BROWSE:-${SAVED_INSTALL_BROWSE:-1}}"
INSTALL_DOCKER="${COREUI_INSTALL_DOCKER:-1}"
INSTALL_OLLAMA="${COREUI_INSTALL_OLLAMA:-1}"
FORCE_CONFIG="${COREUI_FORCE_CONFIG:-0}"
SKIP_LORE="${COREUI_SKIP_LORE:-0}"
SKIP_BOOTSTRAP="${COREUI_SKIP_BOOTSTRAP:-0}"
ADOPT_MODEL="${COREUI_ADOPT_MODEL:-0}"
BASE_MODEL="${COREUI_BASE_MODEL:-}"
PUBLIC_URL="${COREUI_PUBLIC_URL:-http://127.0.0.1:$HTTP_PORT}"
THREADS="$(getconf _NPROCESSORS_ONLN 2>/dev/null || printf '4')"
[[ "$THREADS" =~ ^[0-9]+$ ]] || THREADS=4

if [[ -f "$CONFIG_FILE" && -f "$ENV_FILE" && "$FORCE_CONFIG" != '1' ]]; then
  if [[ -n "$REQUEST_HTTP_PORT" \
      || -n "$REQUEST_PHP_PORT" \
      || -n "$REQUEST_DB_PORT" \
      || -n "$REQUEST_SEARXNG_PORT" \
      || -n "$REQUEST_DB_NAME" \
      || -n "$REQUEST_DB_USER" \
      || -n "$REQUEST_DB_PASS" \
      || -n "$REQUEST_DB_ROOT_PASS" \
      || -n "$REQUEST_MODEL_NAME" \
      || -n "$REQUEST_LOCK_NAMESPACE" \
      || -n "$REQUEST_BIND_ADDRESS" \
      || -n "$REQUEST_INSTALL_SEARXNG" \
      || -n "$REQUEST_INSTALL_BROWSE" \
      || -n "$REQUEST_SEARXNG_URL" \
      || -n "${COREUI_PUBLIC_URL:-}" ]]; then
    die 'Konfigurationswerte wurden fuer eine bestehende Installation geaendert. Sichere den Stand und wiederhole bewusst mit COREUI_FORCE_CONFIG=1.'
  fi
fi

if is_enabled "$INSTALL_SEARXNG"; then
  SEARXNG_URL="${REQUEST_SEARXNG_URL:-${SAVED_SEARXNG_URL:-http://127.0.0.1:$SEARXNG_PORT/search}}"
else
  SEARXNG_URL="${REQUEST_SEARXNG_URL:-${SAVED_SEARXNG_URL:-}}"
fi
SEARXNG_SECRET="${SAVED_SEARXNG_SECRET:-$(openssl rand -hex 32)}"

for port in "$HTTP_PORT" "$PHP_PORT" "$DB_PORT" "$SEARXNG_PORT"; do
  valid_port "$port" || die "Ungueltiger oder privilegierter Port: $port"
done
[[ "$BIND_ADDRESS" == '127.0.0.1' || "$BIND_ADDRESS" == '0.0.0.0' ]] \
  || die 'COREUI_BIND_ADDRESS muss 127.0.0.1 oder 0.0.0.0 sein.'
[[ "$DB_NAME" =~ ^[A-Za-z0-9_]+$ ]] || die 'Ungueltiger Datenbankname.'
[[ "$DB_USER" =~ ^[A-Za-z0-9_]+$ ]] || die 'Ungueltiger Datenbanknutzer.'
[[ "$DB_PASS" =~ ^[A-Za-z0-9._~!@%+=:-]+$ ]] || die 'Ungueltiges Datenbankpasswort fuer den automatischen Installer.'
[[ "$DB_ROOT_PASS" =~ ^[A-Za-z0-9._~!@%+=:-]+$ ]] || die 'Ungueltiges Datenbank-Root-Passwort fuer den automatischen Installer.'
[[ "$MODEL_NAME" =~ ^[A-Za-z0-9._:/-]+$ ]] || die 'COREUI_MODEL_NAME enthaelt ungueltige Zeichen.'
[[ -z "$BASE_MODEL" || "$BASE_MODEL" =~ ^[A-Za-z0-9._:/-]+$ ]] || die 'COREUI_BASE_MODEL enthaelt ungueltige Zeichen.'
[[ "$LOCK_NAMESPACE" =~ ^[A-Za-z0-9_.:-]{1,40}$ ]] || die 'COREUI_LOCK_NAMESPACE enthaelt ungueltige Zeichen.'
[[ "$SEARXNG_URL" != *$'\n'* && "$SEARXNG_URL" != *$'\r'* ]] || die 'Ungueltige SearXNG-URL.'

if is_enabled "$INSTALL_BROWSE" && [[ -z "$SEARXNG_URL" ]]; then
  die 'Der Browse-Worker benoetigt eine lokale oder externe SearXNG-URL.'
fi
if [[ -f "$CONFIG_FILE" && ! -f "$ENV_FILE" && "$FORCE_CONFIG" != '1' ]]; then
  die 'Eine nicht zugeordnete api/config.local.php existiert. Nutze COREUI_FORCE_CONFIG=1 nur nach Sicherung und bewusster Pruefung.'
fi

ADMIN_EMAIL="${COREUI_ADMIN_EMAIL:-}"
ADMIN_PASSWORD="${COREUI_ADMIN_PASSWORD:-}"
ADMIN_NAME="${COREUI_ADMIN_NAME:-Operator}"
ADMIN_GENDER="${COREUI_ADMIN_GENDER:-}"

if ! is_enabled "$SKIP_BOOTSTRAP"; then
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
  [[ "$ADMIN_GENDER" == '' || "$ADMIN_GENDER" == 'm' || "$ADMIN_GENDER" == 'f' ]] \
    || die 'COREUI_ADMIN_GENDER muss leer, m oder f sein.'
fi

log "Bereite den isolierten Parallelbetrieb fuer CoreUI $COREUI_VERSION vor."
ensure_docker
prepare_permissions
assert_stack_identity
assert_ports_available
write_runtime_files

compose config >/dev/null || die 'Die Compose-Konfiguration ist ungueltig.'

log 'Pruefe die paketierten Python-Quellen.'
while IFS= read -r -d '' py_file; do
  python3 -c 'import pathlib,sys; p=pathlib.Path(sys.argv[1]); compile(p.read_text(encoding="utf-8"), str(p), "exec")' "$py_file" \
    || die "Python-Syntaxfehler in $py_file"
done < <(find "$PROJECT_ROOT/tools" -type f -name '*.py' -print0)

log 'Baue die isolierten PHP- und Browse-Images.'
compose build php
if is_enabled "$INSTALL_BROWSE"; then
  compose build browse
fi

compose run --rm --no-deps php sh -ec \
  'find api scripts tools -type f -name "*.php" -print0 | xargs -0 -r -n1 php -l >/dev/null'

ensure_ollama_model

log 'Starte die eigene CoreUI-Datenbank auf dem geschuetzten Loopback-Port.'
compose up -d database
if ! wait_healthy database 75; then
  compose logs --tail=100 database >&2 || true
  die 'Die isolierte CoreUI-Datenbank wurde nicht gesund.'
fi

log 'Importiere alle CoreUI-Migrationen in die eigene Container-Datenbank.'
"$PROJECT_ROOT/scripts/migrate.sh"

if ! is_enabled "$SKIP_BOOTSTRAP"; then
  log 'Initialisiere Ember und das CoreUI-Administratorkonto.'
  compose run --rm --no-deps \
    -e "COREUI_ADMIN_EMAIL=$ADMIN_EMAIL" \
    -e "COREUI_ADMIN_PASSWORD=$ADMIN_PASSWORD" \
    -e "COREUI_ADMIN_NAME=$ADMIN_NAME" \
    -e "COREUI_ADMIN_GENDER=$ADMIN_GENDER" \
    php php scripts/bootstrap.php
else
  warn 'Account-Bootstrap wurde durch COREUI_SKIP_BOOTSTRAP=1 uebersprungen.'
fi

if ! is_enabled "$SKIP_LORE"; then
  log 'Importiere die paketierten Lore-Dokumente in die eigene RAG-Lite-Datenbank.'
  compose run --rm --no-deps php php tools/ingest_docx_knowledge.php \
    --file='/var/www/coreui/docs/S.U. MASTER BIBEL v10.4.docx' \
    --source=bibel_v10_4 \
    --truncate
  compose run --rm --no-deps php php tools/ingest_docx_knowledge.php \
    --file='/var/www/coreui/docs/STU_KOMPENDIUM_V6.docx' \
    --source=kompendium_v6 \
    --truncate
else
  warn 'Lore-Import wurde durch COREUI_SKIP_LORE=1 uebersprungen.'
fi

if is_enabled "$INSTALL_SEARXNG"; then
  log "Starte die eigene SearXNG-Instanz auf 127.0.0.1:$SEARXNG_PORT."
  compose up -d searxng
  wait_http "http://127.0.0.1:$SEARXNG_PORT/search?q=ember&format=json" 60 \
    || die 'Die isolierte SearXNG-Instanz antwortet nicht mit JSON.'
else
  compose stop searxng >/dev/null 2>&1 || true
fi

log "Starte CoreUI-Web und PHP auf $BIND_ADDRESS:$HTTP_PORT."
compose up -d php web
if ! wait_healthy php 45; then
  compose logs --tail=100 php >&2 || true
  die 'Der isolierte CoreUI-PHP-Dienst wurde nicht gesund.'
fi
if ! wait_http "http://127.0.0.1:$HTTP_PORT/api/health.php" 45; then
  compose logs --tail=100 web php >&2 || true
  die 'Der CoreUI-Healthcheck ist fehlgeschlagen.'
fi

if is_enabled "$INSTALL_BROWSE"; then
  compose up -d browse
else
  compose stop browse >/dev/null 2>&1 || true
fi

chmod 0750 "$PROJECT_ROOT/scripts/install.sh" "$PROJECT_ROOT/scripts/preflight.sh" \
  "$PROJECT_ROOT/scripts/install-native.sh" "$PROJECT_ROOT/scripts/preflight-native.sh" \
  "$PROJECT_ROOT/scripts/stack.sh" "$PROJECT_ROOT/scripts/migrate.sh" 2>/dev/null || true

log "Installation erfolgreich. Lokaler Endpunkt: http://127.0.0.1:$HTTP_PORT/"
if [[ "$BIND_ADDRESS" == '127.0.0.1' ]]; then
  log "Zum sicheren Testen vom eigenen Rechner: ssh -L $HTTP_PORT:127.0.0.1:$HTTP_PORT USER@SERVER"
fi
log 'Bestehende Nginx-, Apache-, PHP-FPM-, MariaDB-, Game-, Ember- und Homepage-Konfigurationen wurden nicht veraendert.'
log 'Nur der vorhandene Ollama-Dienst wird als lokale Modell-Schnittstelle gemeinsam genutzt.'
