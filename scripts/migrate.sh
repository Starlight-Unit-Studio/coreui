#!/usr/bin/env bash
set -Eeuo pipefail
IFS=$'\n\t'

PROJECT_ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
ENV_FILE="$PROJECT_ROOT/var/compose.env"
COMPOSE_FILE="$PROJECT_ROOT/compose.yaml"
COMPOSE_PROJECT='ember-coreui'

[[ -f "$ENV_FILE" ]] || {
  printf '[Ember CoreUI] FEHLER: var/compose.env fehlt. Fuehre zuerst scripts/install.sh aus.\n' >&2
  exit 1
}

if docker compose version >/dev/null 2>&1; then
  COMPOSE_CMD=(docker compose)
elif command -v docker-compose >/dev/null 2>&1; then
  COMPOSE_CMD=(docker-compose)
else
  printf '[Ember CoreUI] FEHLER: Docker Compose fehlt.\n' >&2
  exit 1
fi

compose() {
  "${COMPOSE_CMD[@]}" --project-name "$COMPOSE_PROJECT" --env-file "$ENV_FILE" --file "$COMPOSE_FILE" "$@"
}

compose up -d database >/dev/null

for _try in {1..75}; do
  if compose exec -T database sh -ec \
      'mariadb-admin ping --protocol=socket -uroot -p"$MARIADB_ROOT_PASSWORD" --silent' \
      >/dev/null 2>&1; then
    break
  fi
  if [[ "$_try" == '75' ]]; then
    printf '[Ember CoreUI] FEHLER: MariaDB wurde nicht rechtzeitig bereit.\n' >&2
    exit 1
  fi
  sleep 2
done

shopt -s nullglob
migrations=("$PROJECT_ROOT"/database/migrations/*.sql)
(( ${#migrations[@]} > 0 )) || {
  printf '[Ember CoreUI] FEHLER: Keine SQL-Migrationen gefunden.\n' >&2
  exit 1
}

for migration in "${migrations[@]}"; do
  version="$(basename "$migration" .sql)"
  applied='0'
  query="SELECT COUNT(*) FROM stu_schema_migrations WHERE version='$version'"
  applied="$(compose exec -T database sh -ec \
    'exec mariadb --protocol=socket -N -B -uroot -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE" -e "$1"' \
    -- "$query" 2>/dev/null || true)"
  applied="$(printf '%s' "$applied" | tr -d '[:space:]')"
  if [[ "$applied" == '1' ]]; then
    printf '[Ember CoreUI] Migration bereits aktiv: %s\n' "$version"
    continue
  fi
  printf '[Ember CoreUI] Wende Migration an: %s\n' "$version"
  compose exec -T database sh -ec \
    'exec mariadb --protocol=socket -uroot -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE"' \
    < "$migration"
done

printf '[Ember CoreUI] Alle Datenbankmigrationen sind aktuell.\n'
