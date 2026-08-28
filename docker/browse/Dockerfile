FROM python:3.12-slim-bookworm

ENV PIP_DISABLE_PIP_VERSION_CHECK=1 \
    PLAYWRIGHT_BROWSERS_PATH=/ms-playwright \
    PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1

COPY tools/requirements-browse.txt /tmp/requirements-browse.txt

RUN python -m pip install --no-cache-dir -r /tmp/requirements-browse.txt \
    && python -m playwright install --with-deps chromium \
    && chmod -R a+rX /ms-playwright \
    && rm -f /tmp/requirements-browse.txt

WORKDIR /var/www/coreui
USER 33:33

CMD ["python3", "tools/ember_browse_worker.py", "--config", "/var/www/coreui/api/config.local.php", "--poll", "2"]

