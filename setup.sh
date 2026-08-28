#!/usr/bin/env bash
set -Eeuo pipefail

VERSION="0.3.2-alpha"
ARCHIVE="EMBER_COREUI_0_3_2_ALPHA.zip"
CHECKSUM="${ARCHIVE}.sha256"

REPO="pschildgen87-code/coreui"
BASE_URL="https://github.com/${REPO}/releases/download/v${VERSION}"

TARGET_DIR="/opt/ember-coreui"

# Vor dem Update feststellen, ob CoreUI bereits installiert ist.
EXISTING_INSTALL=0
if [[ -f "$TARGET_DIR/var/compose.env" ]]; then
    EXISTING_INSTALL=1
fi

echo "[Ember CoreUI] Bereite Installation vor..."

sudo apt-get update
sudo apt-get install -y wget unzip

sudo mkdir -p "$TARGET_DIR"
cd "$TARGET_DIR"

echo "[Ember CoreUI] Lade Release v${VERSION} herunter..."

sudo wget -q --show-progress \
  "${BASE_URL}/${ARCHIVE}" \
  -O "${ARCHIVE}"

sudo wget -q \
  "${BASE_URL}/${CHECKSUM}" \
  -O "${CHECKSUM}"

echo "[Ember CoreUI] Verifiziere Dateisicherheit (SHA256)..."

if ! sha256sum -c "$CHECKSUM"; then
    echo "ERROR: SHA-Pruefung fehlgeschlagen!"
    exit 1
fi

echo "[Ember CoreUI] Entpacke Systemdateien..."

sudo unzip -q -o "$ARCHIVE"

sudo rm -f \
  "$ARCHIVE" \
  "$CHECKSUM"

echo "[Ember CoreUI] Uebergebe an den Haupt-Installer..."

sudo chmod +x scripts/install.sh

if (( EXISTING_INSTALL == 1 )); then
    echo "[Ember CoreUI] Bestehende Installation erkannt - Update-Modus."

    # Kein erneutes Admin-Passwort abfragen.
    sudo env \
      COREUI_SKIP_BOOTSTRAP=1 \
      ./scripts/install.sh
else
    echo "[Ember CoreUI] Neue Installation erkannt."

    # curl | bash verwendet STDIN fuer den Script-Download.
    # Deshalb die Installer-Eingaben direkt vom Terminal lesen.
    if [[ -r /dev/tty ]]; then
        sudo ./scripts/install.sh </dev/tty
    else
        echo "ERROR: Fuer die Erstinstallation wird ein interaktives Terminal benoetigt."
        exit 1
    fi
fi

echo "[Ember CoreUI] Setup abgeschlossen."
