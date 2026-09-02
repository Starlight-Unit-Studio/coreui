#!/usr/bin/env bash
set -Eeuo pipefail
IFS=$'\n\t'
umask 027

REPOSITORY='Starlight-Unit-Studio/coreui'
VERSION='0.5.2-alpha'
TAG="v${VERSION}"
ARCHIVE='EMBER_COREUI_0_5_2_ALPHA.zip'
CHECKSUM="${ARCHIVE}.sha256"
PACKAGE_ROOT='EMBER_COREUI_0_5_2_ALPHA'
TARGET_DIR='/opt/ember-coreui'
# Release-Paare liegen absichtlich gemeinsam im Repository. Dadurch bleibt die
# Blitzinstallation nutzbar, ohne dass ZIP und SHA-Datei aus zwei verschiedenen
# oder veralteten GitHub-Releases gemischt werden koennen.
DOWNLOAD_BASE="https://raw.githubusercontent.com/${REPOSITORY}/main/releases/${TAG}"
TEMP_DIR=''

log() {
  printf '[Ember CoreUI Setup] %s\n' "$*"
}

die() {
  printf '[Ember CoreUI Setup] FEHLER: %s\n' "$*" >&2
  exit 1
}

cleanup() {
  if [[ -n "$TEMP_DIR" && -d "$TEMP_DIR" && "$TEMP_DIR" == /tmp/ember-coreui-setup.* ]]; then
    rm -rf -- "$TEMP_DIR"
  fi
}
trap cleanup EXIT

cleanup_legacy_release_dirs() {
  local legacy_dir
  local legacy_name
  local removed=0

  while IFS= read -r -d '' legacy_dir; do
    legacy_name="${legacy_dir##*/}"
    [[ "$legacy_name" =~ ^EMBER_COREUI_[0-9]+_[0-9]+_[0-9]+_ALPHA$ ]] || continue
    [[ ! -L "$legacy_dir" ]] || die "Unsicherer symbolischer Altversionspfad erkannt: $legacy_dir"
    [[ "$legacy_dir" == "$TARGET_DIR"/EMBER_COREUI_*_ALPHA ]] \
      || die "Unsicherer Altversionspfad erkannt: $legacy_dir"
    if [[ ! -f "$legacy_dir/VERSION" || ! -f "$legacy_dir/scripts/install.sh" ]]; then
      log "WARN: $legacy_name sieht nur dem Namen nach wie eine Altversion aus und bleibt deshalb erhalten."
      continue
    fi
    if command -v mountpoint >/dev/null 2>&1 && mountpoint -q "$legacy_dir"; then
      die "Altversionsordner ist ein Einhaengepunkt und wird nicht automatisch entfernt: $legacy_dir"
    fi

    log "Entferne vollstaendig entpackte Altversion: $legacy_name"
    rm -rf -- "$legacy_dir"
    [[ ! -e "$legacy_dir" ]] || die "Altversionsordner konnte nicht entfernt werden: $legacy_dir"
    removed=$((removed + 1))
  done < <(find -P "$TARGET_DIR" -mindepth 1 -maxdepth 1 -type d -name 'EMBER_COREUI_*_ALPHA' -print0)

  if (( removed > 0 )); then
    log "$removed entpackte Altversionsordner wurden entfernt. Persistente Daten blieben unangetastet."
  fi
}

[[ "${EUID:-$(id -u)}" -eq 0 ]] || die 'Bitte das heruntergeladene Setup mit sudo bash ausfuehren.'

packages=()
command -v curl >/dev/null 2>&1 || packages+=(ca-certificates curl)
command -v sha256sum >/dev/null 2>&1 || packages+=(coreutils)
command -v unzip >/dev/null 2>&1 || packages+=(unzip)
command -v rsync >/dev/null 2>&1 || packages+=(rsync)
if (( ${#packages[@]} > 0 )); then
  command -v apt-get >/dev/null 2>&1 || die 'curl, sha256sum, unzip oder rsync fehlt und apt-get ist nicht verfuegbar.'
  export DEBIAN_FRONTEND=noninteractive
  apt-get update
  apt-get install -y "${packages[@]}"
fi

TEMP_DIR="$(mktemp -d /tmp/ember-coreui-setup.XXXXXX)"
log "Lade Ember CoreUI ${VERSION} in ein temporaeres Verzeichnis."
curl --fail --location --show-error --silent --retry 3 --retry-all-errors \
  --output "$TEMP_DIR/$ARCHIVE" "$DOWNLOAD_BASE/$ARCHIVE"
curl --fail --location --show-error --silent --retry 3 --retry-all-errors \
  --output "$TEMP_DIR/$CHECKSUM" "$DOWNLOAD_BASE/$CHECKSUM"

(
  cd "$TEMP_DIR"
  sha256sum -c "$CHECKSUM"
)

unzip -q "$TEMP_DIR/$ARCHIVE" -d "$TEMP_DIR/unpacked"
SOURCE_DIR="$TEMP_DIR/unpacked/$PACKAGE_ROOT"
for required in VERSION README.md compose.yaml scripts/install.sh scripts/stack.sh scripts/preflight.sh; do
  [[ -s "$SOURCE_DIR/$required" ]] || die "Das gepruefte Paket ist unvollstaendig: $required fehlt."
done
[[ "$(tr -d '[:space:]' < "$SOURCE_DIR/VERSION")" == "$VERSION" ]] \
  || die 'Paketversion und Setup-Version stimmen nicht ueberein.'

if find "$SOURCE_DIR" -type f \
    \( -iname '*master*bibel*' -o -iname '*kompendium*v6*' \) -print -quit | grep -q .; then
  die 'Das Release enthaelt gesperrtes privates Studio-Material.'
fi

existing=0
if [[ -f "$TARGET_DIR/api/config.local.php" && -f "$TARGET_DIR/var/compose.env" ]]; then
  existing=1
  log 'Bestehende Installation erkannt. Konten, Datenbank, Uploads und lokale Konfiguration bleiben erhalten.'
fi

install -d -m 0750 "$TARGET_DIR"
cleanup_legacy_release_dirs
rsync -a \
  --exclude='api/config.local.php' \
  --exclude='var/' \
  --exclude='logs/' \
  --exclude='uploads/' \
  --exclude='assets/chat_media/' \
  --exclude='assets/profile_photos/' \
  "$SOURCE_DIR/" "$TARGET_DIR/"

# Exakte Altlasten aus versehentlichen Alpha-Paketen entfernen. Andere Dateien
# im docs-Verzeichnis bleiben unangetastet.
rm -f -- \
  "$TARGET_DIR/docs/S.U. MASTER BIBEL v10.4.docx" \
  "$TARGET_DIR/docs/STU_KOMPENDIUM_V6.docx"

chmod 0750 \
  "$TARGET_DIR/scripts/install.sh" \
  "$TARGET_DIR/scripts/stack.sh" \
  "$TARGET_DIR/scripts/preflight.sh"

cd "$TARGET_DIR"
if (( existing == 1 )); then
  COREUI_SKIP_BOOTSTRAP=1 ./scripts/install.sh
elif [[ -r /dev/tty && -w /dev/tty ]]; then
  ./scripts/install.sh </dev/tty
else
  [[ -n "${COREUI_ADMIN_EMAIL:-}" && -n "${COREUI_ADMIN_PASSWORD:-}" ]] \
    || die 'Fuer eine nicht-interaktive Erstinstallation muessen COREUI_ADMIN_EMAIL und COREUI_ADMIN_PASSWORD gesetzt sein.'
  ./scripts/install.sh
fi

./scripts/preflight.sh
log "Ember CoreUI ${VERSION} wurde geprueft installiert."
