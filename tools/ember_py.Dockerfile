# -----------------------------------------------------------------------------
# STU - Sandbox-Image fuer Embers Python-Werkzeug (v1.1.1.87)
#
# Bauen (auf dem Server, als root):
#   cd /opt/ember-coreui
#   docker build -t ember-py:1 -f tools/ember_py.Dockerfile tools/
#
# Das Image enthaelt bewusst KEINE Zugangsdaten, keine Mounts und keinen Bezug
# zum Spiel. Es ist ein reiner Rechen-/Netz-Werkzeugkasten. Alle Grenzen (RAM,
# CPU, Prozesse, Laufzeit, Rootfs read-only) setzt der Worker beim docker run.
# -----------------------------------------------------------------------------
FROM python:3.12-slim

# Nur was Ember realistisch braucht. Wer mehr will: hier ergaenzen und Tag hochzaehlen
# (ember-py:2) statt das laufende Image zu ueberschreiben.
RUN pip install --no-cache-dir \
      numpy \
      pandas \
      requests \
      beautifulsoup4 \
      lxml \
      python-dateutil \
      pytz \
      pillow \
      openpyxl \
      matplotlib \
 && rm -rf /root/.cache

# Kein Bytecode-Muell im read-only Rootfs, Diagramme ohne Display-Server.
ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    MPLBACKEND=Agg \
    HOME=/tmp

# matplotlib legt beim ersten Start einen Font-Cache an. Ohne Vorwaermen passiert
# das im read-only Container bei jedem Lauf neu in /tmp und kostet Sekunden.
RUN MPLCONFIGDIR=/opt/mplcache python -c "import matplotlib.pyplot" \
 && chmod -R a+rX /opt/mplcache
ENV MPLCONFIGDIR=/opt/mplcache

WORKDIR /work
USER 65534:65534
CMD ["python3"]
