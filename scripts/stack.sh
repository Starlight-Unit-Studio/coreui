#!/usr/bin/env bash
set -Eeuo pipefail
IFS=$'\n\t'

PROJECT_ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
ENV_FILE="$PROJECT_ROOT/var/compose.env"
COMPOSE_FILE="$PROJECT_ROOT/compose.yaml"
COMPOSE_PROJECT='ember-coreui'
RUNTIME_NGINX_TEMPLATE="$PROJECT_ROOT/config/runtime/nginx.conf.template"
RUNTIME_FPM_TEMPLATE="$PROJECT_ROOT/config/runtime/php-fpm.conf.template"
RUNTIME_NGINX_FILE="$PROJECT_ROOT/var/nginx/coreui.conf"
RUNTIME_FPM_FILE="$PROJECT_ROOT/var/php-fpm/zz-coreui.conf"

valid_runtime_port() {
  local port="${1:-}"
  [[ "$port" =~ ^[0-9]{2,5}$ ]] || return 1
  (( 10#$port >= 1024 && 10#$port <= 65535 ))
}

install_runtime_file() {
  local source_file="$1"
  local target_file="$2"
  if (( EUID == 0 )); then
    if install -m 0640 -o root -g 33 "$source_file" "$target_file" 2>/dev/null; then
      return 0
    fi
    printf '[Ember CoreUI] WARNUNG: Dateisystem akzeptiert Gruppe 33 nicht; Runtime-Datei wird ohne Geheimnisse lesbar abgelegt.\n' >&2
  fi
  install -m 0644 "$source_file" "$target_file"
}

[[ -f "$ENV_FILE" ]] || {
  printf '[Ember CoreUI] FEHLER: var/compose.env fehlt. Fuehre scripts/install.sh aus.\n' >&2
  exit 1
}

render_runtime_files() (
  local nginx_tmp fpm_tmp

  [[ -f "$RUNTIME_NGINX_TEMPLATE" ]] || {
    printf '[Ember CoreUI] FEHLER: Runtime-Nginx-Template fehlt.\n' >&2
    exit 1
  }
  [[ -f "$RUNTIME_FPM_TEMPLATE" ]] || {
    printf '[Ember CoreUI] FEHLER: Runtime-PHP-FPM-Template fehlt.\n' >&2
    exit 1
  }

  # compose.env wird ausschliesslich vom Installer mit validierten
  # einzeiligen Werten erzeugt und ist nicht Bestandteil des Release-ZIPs.
  set -a
  # shellcheck disable=SC1090
  source "$ENV_FILE"
  set +a

  [[ "${COREUI_BIND_ADDRESS:-}" == '127.0.0.1' || "${COREUI_BIND_ADDRESS:-}" == '0.0.0.0' ]] || {
    printf '[Ember CoreUI] FEHLER: COREUI_BIND_ADDRESS in compose.env ist ungueltig.\n' >&2
    exit 1
  }
  valid_runtime_port "${COREUI_HTTP_PORT:-}" && valid_runtime_port "${COREUI_PHP_PORT:-}" || {
    printf '[Ember CoreUI] FEHLER: Runtime-Ports in compose.env sind ungueltig.\n' >&2
    exit 1
  }

  install -d -m 0750 "$PROJECT_ROOT/var/nginx" "$PROJECT_ROOT/var/php-fpm"
  nginx_tmp="$(mktemp)"
  fpm_tmp="$(mktemp)"
  trap 'rm -f -- "$nginx_tmp" "$fpm_tmp"' EXIT

  sed \
    -e "s|@@BIND_ADDRESS@@|$COREUI_BIND_ADDRESS|g" \
    -e "s|@@HTTP_PORT@@|$COREUI_HTTP_PORT|g" \
    -e "s|@@PHP_PORT@@|$COREUI_PHP_PORT|g" \
    "$RUNTIME_NGINX_TEMPLATE" > "$nginx_tmp"

  sed \
    -e "s|@@PHP_PORT@@|$COREUI_PHP_PORT|g" \
    "$RUNTIME_FPM_TEMPLATE" > "$fpm_tmp"

  install_runtime_file "$nginx_tmp" "$RUNTIME_NGINX_FILE"
  install_runtime_file "$fpm_tmp" "$RUNTIME_FPM_FILE"
)

STACK_ACTION="${1:-ps}"
case "$STACK_ACTION" in
  refresh-runtime)
    render_runtime_files
    printf '[Ember CoreUI] Runtime-Konfiguration aus den aktuellen Templates erneuert.\n'
    exit 0
    ;;
  up|start|restart)
    render_runtime_files
    ;;
esac

if docker compose version >/dev/null 2>&1; then
  COMPOSE_CMD=(docker compose)
elif command -v docker-compose >/dev/null 2>&1; then
  COMPOSE_CMD=(docker-compose)
else
  printf '[Ember CoreUI] FEHLER: Docker Compose fehlt.\n' >&2
  exit 1
fi

if [[ "$STACK_ACTION" == 'scrub-thinking' ]]; then
  "${COMPOSE_CMD[@]}" \
    --project-name "$COMPOSE_PROJECT" \
    --env-file "$ENV_FILE" \
    --file "$COMPOSE_FILE" \
    exec -T -u 33:33 php php scripts/scrub-thinking.php
  exit 0
fi

if [[ "$STACK_ACTION" == 'migrate' ]]; then
  exec "$PROJECT_ROOT/scripts/migrate.sh"
fi

# Jede reguläre Aktualisierung wendet neue additive Migrationen an, bevor PHP,
# Web oder Worker wieder Anfragen annehmen. Bereits registrierte Migrationen
# werden von migrate.sh uebersprungen.
case "$STACK_ACTION" in
  up|start|restart)
    "$PROJECT_ROOT/scripts/migrate.sh"
    ;;
esac

if (( $# == 0 )); then
  set -- ps
fi

exec "${COMPOSE_CMD[@]}" \
  --project-name "$COMPOSE_PROJECT" \
  --env-file "$ENV_FILE" \
  --file "$COMPOSE_FILE" \
  "$@"
