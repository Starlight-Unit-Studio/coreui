#!/usr/bin/env bash
set -Eeuo pipefail

VERSION="0.3.2-alpha"
ARCHIVE="EMBER_COREUI_0_3_2_ALPHA.zip"
CHECKSUM="${ARCHIVE}.sha256"

REPO="pschildgen87-code/coreui"
BASE_URL="https://github.com/${REPO}/releases/download/v${VERSION}"

TARGET_DIR="/opt/ember-coreui"

# 1. Vorbereitung
echo "[Ember CoreUI] Bereite Installation vor..."

sudo apt-get update
sudo apt-get install -y wget unzip

sudo mkdir -p "$TARGET_DIR"
cd "$TARGET_DIR"

# 2. Release herunterladen
echo "[Ember CoreUI] Lade Release v${VERSION} herunter..."

sudo wget -q --show-progress \
  "${BASE_URL}/${ARCHIVE}" \
  -O "${ARCHIVE}"

sudo wget -q \
  "${BASE_URL}/${CHECKSUM}" \
  -O "${CHECKSUM}"

# 3. Integritätsprüfung
echo "[Ember CoreUI] Verifiziere Dateisicherheit (SHA256)..."

if ! sha256sum -c "$CHECKSUM"; then
    echo "ERROR: SHA-Pruefung fehlgeschlagen!"
    exit 1
fi

# 4. Entpacken
echo "[Ember CoreUI] Entpacke Systemdateien..."

sudo unzip -q -o "$ARCHIVE"

sudo rm -f \
  "$ARCHIVE" \
  "$CHECKSUM"

# 5. Übergabe an Haupt-Installer
echo "[Ember CoreUI] Uebergebe an den Haupt-Installer..."

sudo chmod +x scripts/install.sh
sudo ./scripts/install.sh
