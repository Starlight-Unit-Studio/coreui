#!/usr/bin/env bash
set -Eeuo pipefail

VERSION="0.4.2-alpha"
ARCHIVE="EMBER_COREUI_0_4_2_ALPHA.zip"
CHECKSUM="${ARCHIVE}.sha256"

REPO="pschildgen87-code/coreui"
BASE_URL="https://github.com/${REPO}/releases/download/v${VERSION}"

TARGET_DIR="/opt/ember-coreui"

# Root oder sudo automatisch verwenden.
if [[ "${EUID:-$(id -u)}" -eq 0 ]]; then
    SUDO=()
elif command -v sudo >/dev/null 2>&1; then
    SUDO=(sudo)
else
    echo "ERROR: Root-Rechte oder sudo werden benoetigt."
    exit 1
fi

# Vor dem Update feststellen, ob CoreUI bereits installiert ist.
EXISTING_INSTALL=0
if [[ -f "$TARGET_DIR/var/compose.env" ]]; then
    EXISTING_INSTALL=1
fi

echo "[Ember CoreUI] Bereite Installation vor..."

"${SUDO[@]}" apt-get update
"${SUDO[@]}" apt-get install -y wget unzip

"${SUDO[@]}" mkdir -p "$TARGET_DIR"
cd "$TARGET_DIR"

echo "[Ember CoreUI] Lade Release v${VERSION} herunter..."

"${SUDO[@]}" wget -q --show-progress \
  "${BASE_URL}/${ARCHIVE}" \
  -O "${ARCHIVE}"

"${SUDO[@]}" wget -q \
  "${BASE_URL}/${CHECKSUM}" \
  -O "${CHECKSUM}"

echo "[Ember CoreUI] Verifiziere Dateisicherheit (SHA256)..."

if ! sha256sum -c "$CHECKSUM"; then
    echo "ERROR: SHA-Pruefung fehlgeschlagen!"
    exit 1
fi

echo "[Ember CoreUI] Entpacke Systemdateien..."

"${SUDO[@]}" unzip -q -o "$ARCHIVE"

"${SUDO[@]}" rm -f \
  "$ARCHIVE" \
  "$CHECKSUM"

echo "[Ember CoreUI] Uebergebe an den Haupt-Installer..."

"${SUDO[@]}" chmod +x scripts/install.sh

if (( EXISTING_INSTALL == 1 )); then
    echo "[Ember CoreUI] Bestehende Installation erkannt - Update-Modus."

    # Bei Updates keinen erneuten Admin-Bootstrap ausfuehren.
    "${SUDO[@]}" env \
      COREUI_SKIP_BOOTSTRAP=1 \
      ./scripts/install.sh
else
    echo "[Ember CoreUI] Neue Installation erkannt."

    # curl | bash verwendet STDIN fuer den Script-Download.
    # Installer-Eingaben deshalb direkt vom Terminal lesen.
    if [[ -r /dev/tty ]]; then
        "${SUDO[@]}" ./scripts/install.sh </dev/tty
    else
        echo "ERROR: Fuer die Erstinstallation wird ein interaktives Terminal benoetigt."
        exit 1
    fi
fi

echo "[Ember CoreUI] Setup abgeschlossen."
