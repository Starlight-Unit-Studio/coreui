#!/bin/sh
set -eu

project_root="${COREUI_PROJECT_ROOT:-/opt/ember-coreui}"
config_file="${STU_CONFIG:-$project_root/api/config.local.php}"
sandbox_image="${STU_EMBER_PY_IMAGE:-ember-py:1}"

test -S /var/run/docker.sock || {
  echo '[Ember CoreUI Python] Docker-Socket fehlt.' >&2
  exit 1
}
test -r "$config_file" || {
  echo '[Ember CoreUI Python] Geschuetzte Laufzeitkonfiguration fehlt.' >&2
  exit 1
}
test -r "$project_root/tools/ember_py_worker.py" || {
  echo '[Ember CoreUI Python] Worker-Quelle fehlt.' >&2
  exit 1
}

if ! docker image inspect "$sandbox_image" >/dev/null 2>&1; then
  echo "[Ember CoreUI Python] Baue einmalig das Sandbox-Image $sandbox_image."
  docker build \
    --tag "$sandbox_image" \
    --file "$project_root/tools/ember_py.Dockerfile" \
    "$project_root/tools"
fi

exec python3 "$project_root/tools/ember_py_worker.py" \
  --config "$config_file" \
  --poll 2
