#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
STU - Ember Browse-Worker (Phase 1)
===================================
Dediziertes Subsystem ausserhalb des PHP-Request-Pfads.

Was es tut:
  1. Pollt die Tabelle stu_ember_browse_jobs nach 'queued' Jobs (atomar geclaimt).
  2. Haelt einen Ember CoreUI-namespaceten Ollama-Lock auf der EIGENEN
     DB-Session -> stirbt der Worker, gibt MariaDB den Lock automatisch frei.
  3. Flippt Embers Presence auf AFK ("im Netz unterwegs") via stu_kv-Flag + Chat-Systemmeldung.
  4. Browst headless mit Playwright ueber den Accessibility-Tree (Rolle+Name, kein Vision-Loop).
  5. Fragt pro Schritt dasselbe lokale Ember CoreUI-Modell im nuechternen Aktions-Modus nach der naechsten Aktion.
  6. Postet das Ergebnis als Ember in den Global-Chat (ein lockerer Report-Call, Embers Stil).
  7. Rueckkehr ist STILL (kein "wieder da") - nur das AFK-Flag wird geloescht.

Single-LLM: dasselbe Ember CoreUI-Modell, nur zwei Prompt-Modi (Aktion = nuechtern/JSON, Report = locker).

Konfiguration wird zur LAUFZEIT aus api/config.local.php gelesen (eine Quelle der Wahrheit,
keine duplizierten Creds). config.local.php ist NICHT Teil des ZIP.

Abhaengigkeiten (auf dem Server installieren):
    pip install playwright pymysql requests
    playwright install chromium

Start (Beispiele):
    python3 tools/ember_browse_worker.py --once        # genau einen Job abarbeiten, dann Ende
    python3 tools/ember_browse_worker.py               # Dauerloop (z.B. als systemd-Service)
    python3 tools/ember_browse_worker.py --config ./api/config.local.php
"""

import os
import re
import sys
import json
import html
import base64
import time
import signal
import argparse
import traceback
from datetime import datetime
from urllib.parse import urlsplit

try:
    import pymysql
except ImportError:
    sys.exit("Fehlt: pip install pymysql")
try:
    import requests
except ImportError:
    sys.exit("Fehlt: pip install requests")
try:
    from playwright.sync_api import sync_playwright, TimeoutError as PWTimeout
except ImportError:
    sys.exit("Fehlt: pip install playwright  &&  playwright install chromium")

# ----------------------------------------------------------------------------- config

KV_AFK_KEY = "ember_browse_afk"

DEFAULTS = {
    "STU_DB_HOST": "127.0.0.1",
    "STU_DB_PORT": "13306",
    "STU_DB_NAME": "ember_coreui",
    "STU_DB_USER": "ember_coreui",
    "STU_DB_PASS": "",
    "STU_EMBER_OLLAMA_URL": "http://127.0.0.1:11434/api/chat",
    "STU_EMBER_MODEL": "ember-coreui:latest",
    "STU_EMBER_LOCK_NAMESPACE": "ember_coreui",
    "STU_EMBER_USER_ID": "1",
    "STU_EMBER_CHARACTER_ID": "ember",
    "STU_EMBER_CHARACTER_NAME": "Ember",
    "STU_EMBER_BROWSE_AFK_REASON": "im netz unterwegs",
    "STU_EMBER_BROWSE_MAX_STEPS": "12",
    # Ein Aktionsentscheid kann auf CPU-Systemen oder bei parallel geladenen
    # Ollama-Modellen deutlich laenger als 90 Sekunden dauern. Der Browser selbst
    # bleibt waehrenddessen intakt. Verbindungs-Retries sind standardmaessig aus,
    # damit ein bereits rechnender Ollama-Request nicht unbemerkt dupliziert wird.
    "STU_EMBER_BROWSE_AGENT_TIMEOUT": "420",
    "STU_EMBER_BROWSE_REPORT_TIMEOUT": "420",
    "STU_EMBER_BROWSE_AGENT_RETRIES": "0",
    "STU_EMBER_BROWSE_SCREENSHOT_DIR": "",  # leer = uploads/ember_browse unter www-root
    "STU_EMBER_BROWSE_FRAME_QUALITY": "45",
    "STU_EMBER_BROWSE_FRAME_BURST": "3",
    "STU_EMBER_BROWSE_FRAME_INTERVAL_MS": "240",
    "STU_EMBER_BROWSE_FRAME_RETENTION_HOURS": "24",
    # SearXNG - dieselbe Instanz/Keys wie chat.php (eine Quelle der Wahrheit, lokal, kein CAPTCHA)
    "STU_EMBER_WEB_URL": "http://127.0.0.1:18889/search",
    "STU_EMBER_WEB_LIMIT": "5",
    "STU_EMBER_WEB_TIMEOUT": "12",
}


def parse_php_config(path):
    """Liest define('KEY', <expr>) aus config.local.php.
    Beruecksichtigt das Muster getenv('KEY') ?: 'fallback' (Env hat Vorrang)
    und einfache define('KEY', 'wert') / define('KEY', 123)."""
    cfg = dict(DEFAULTS)
    try:
        with open(path, "r", encoding="utf-8", errors="replace") as fh:
            src = fh.read()
    except OSError as exc:
        print(f"[config] WARN konnte {path} nicht lesen: {exc}", flush=True)
        return cfg

    for key in list(cfg.keys()) + [
        "STU_DB_PORT",
    ]:
        m = re.search(r"define\(\s*['\"]" + re.escape(key) + r"['\"]\s*,\s*(.+?)\)\s*;",
                      src, re.DOTALL)
        if not m:
            continue
        expr = m.group(1).strip()
        # Env-Override, wenn die Konfig getenv('KEY') benutzt und die Variable gesetzt ist
        if "getenv(" in expr:
            env_m = re.search(r"getenv\(\s*['\"]([^'\"]+)['\"]\s*\)", expr)
            if env_m and os.environ.get(env_m.group(1)):
                cfg[key] = os.environ[env_m.group(1)]
                continue
        # sonst: letztes string-literal als Wert; sonst nackte Zahl
        lit = re.findall(r"'((?:[^'\\]|\\.)*)'|\"((?:[^\"\\]|\\.)*)\"", expr)
        if lit:
            val = lit[-1][0] or lit[-1][1]
            cfg[key] = val.replace("\\'", "'").replace('\\"', '"')
        else:
            num = re.search(r"(-?\d+)", expr)
            if num:
                cfg[key] = num.group(1)
    return cfg


def cfg_int(cfg, key, default, minimum, maximum):
    """Liest eine begrenzte Ganzzahl aus der Laufzeitkonfiguration."""
    try:
        value = int(cfg.get(key, default))
    except (TypeError, ValueError):
        value = int(default)
    return max(int(minimum), min(int(maximum), value))


# ----------------------------------------------------------------------------- db


def db_connect(cfg, autocommit=True):
    return pymysql.connect(
        host=cfg["STU_DB_HOST"],
        user=cfg["STU_DB_USER"],
        password=cfg["STU_DB_PASS"],
        database=cfg["STU_DB_NAME"],
        port=int(cfg.get("STU_DB_PORT", "13306") or "13306"),
        charset="utf8mb4",
        autocommit=autocommit,
        cursorclass=pymysql.cursors.DictCursor,
    )


def lock_name(cfg, purpose):
    namespace = re.sub(r"[^a-zA-Z0-9_.:-]+", "_", str(
        cfg.get("STU_EMBER_LOCK_NAMESPACE") or "ember_coreui"
    ).strip()) or "ember_coreui"
    purpose = re.sub(r"[^a-zA-Z0-9_.:-]+", "_", str(purpose).strip()) or "lock"
    value = f"{namespace}:{purpose}"
    if len(value) <= 64:
        return value
    import hashlib
    return f"{namespace[:30]}:{hashlib.sha256(value.encode('utf-8')).hexdigest()[:32]}"


def ensure_jobs_table(conn):
    with conn.cursor() as cur:
        cur.execute("""
            CREATE TABLE IF NOT EXISTS stu_ember_browse_jobs (
              id INT UNSIGNED NOT NULL AUTO_INCREMENT,
              status ENUM('queued','running','done','error') NOT NULL DEFAULT 'queued',
              goal TEXT NOT NULL,
              start_url VARCHAR(2048) NULL,
              max_steps INT NOT NULL DEFAULT 12,
              channel VARCHAR(32) NOT NULL DEFAULT 'global',
              recipient_uid BIGINT UNSIGNED NULL,
              session_id VARCHAR(40) NULL,
              trigger_message_id BIGINT UNSIGNED NULL,
              trigger_user_id BIGINT UNSIGNED NULL,
              result MEDIUMTEXT NULL,
              steps_json MEDIUMTEXT NULL,
              screenshot_path VARCHAR(512) NULL,
              error TEXT NULL,
              worker_pid INT NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              started_at DATETIME NULL,
              finished_at DATETIME NULL,
              PRIMARY KEY (id),
              KEY idx_status (status, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        """)
        # Bestandstabellen migrieren (Phase 2). MariaDB unterstuetzt IF NOT EXISTS bei ADD COLUMN.
        for ddl in (
            "ALTER TABLE stu_ember_browse_jobs ADD COLUMN IF NOT EXISTS channel VARCHAR(32) NOT NULL DEFAULT 'global'",
            "ALTER TABLE stu_ember_browse_jobs ADD COLUMN IF NOT EXISTS recipient_uid BIGINT UNSIGNED NULL",
            "ALTER TABLE stu_ember_browse_jobs ADD COLUMN IF NOT EXISTS session_id VARCHAR(40) NULL",
            "ALTER TABLE stu_ember_browse_jobs ADD COLUMN IF NOT EXISTS trigger_message_id BIGINT UNSIGNED NULL",
            "ALTER TABLE stu_ember_browse_jobs ADD COLUMN IF NOT EXISTS trigger_user_id BIGINT UNSIGNED NULL",
        ):
            try:
                cur.execute(ddl)
            except Exception:
                pass


def ensure_steps_table(conn):
    """Live-Schritt-Tabelle fuer das Console-Browser-Fenster (Phase 3b).
    Der SSE-Endpunkt console_browse_stream.php tailt sie pro Job."""
    with conn.cursor() as cur:
        cur.execute("""
            CREATE TABLE IF NOT EXISTS stu_ember_browse_steps (
              id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              job_id INT UNSIGNED NOT NULL,
              channel VARCHAR(32) NOT NULL DEFAULT 'global',
              recipient_uid BIGINT UNSIGNED NULL,
              text VARCHAR(800) NOT NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (id),
              KEY idx_job (job_id, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        """)


def ensure_frames_table(conn):
    """Private JPEG-Frames fuer die authentifizierte Ember CoreUI-Agentenansicht.
    Die Bilder liegen absichtlich nicht in einem oeffentlichen Webverzeichnis."""
    with conn.cursor() as cur:
        cur.execute("""
            CREATE TABLE IF NOT EXISTS stu_ember_browse_frames (
              id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              job_id INT UNSIGNED NOT NULL,
              channel VARCHAR(32) NOT NULL DEFAULT 'global',
              recipient_uid BIGINT UNSIGNED NULL,
              step INT NOT NULL DEFAULT 0,
              b64 MEDIUMTEXT NOT NULL,
              cx FLOAT NULL,
              cy FLOAT NULL,
              vw INT NULL,
              vh INT NULL,
              cursor_click TINYINT(1) NOT NULL DEFAULT 0,
              page_url VARCHAR(2048) NULL,
              frame_label VARCHAR(255) NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (id),
              KEY idx_job (job_id, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        """)
        # Sichere Aktualisierung einer Tabelle aus einer fruehen Testversion.
        for ddl in (
            "ALTER TABLE stu_ember_browse_frames ADD COLUMN IF NOT EXISTS channel VARCHAR(32) NOT NULL DEFAULT 'global'",
            "ALTER TABLE stu_ember_browse_frames ADD COLUMN IF NOT EXISTS recipient_uid BIGINT UNSIGNED NULL",
            "ALTER TABLE stu_ember_browse_frames ADD COLUMN IF NOT EXISTS step INT NOT NULL DEFAULT 0",
            "ALTER TABLE stu_ember_browse_frames ADD COLUMN IF NOT EXISTS cx FLOAT NULL",
            "ALTER TABLE stu_ember_browse_frames ADD COLUMN IF NOT EXISTS cy FLOAT NULL",
            "ALTER TABLE stu_ember_browse_frames ADD COLUMN IF NOT EXISTS vw INT NULL",
            "ALTER TABLE stu_ember_browse_frames ADD COLUMN IF NOT EXISTS vh INT NULL",
            "ALTER TABLE stu_ember_browse_frames ADD COLUMN IF NOT EXISTS cursor_click TINYINT(1) NOT NULL DEFAULT 0",
            "ALTER TABLE stu_ember_browse_frames ADD COLUMN IF NOT EXISTS page_url VARCHAR(2048) NULL",
            "ALTER TABLE stu_ember_browse_frames ADD COLUMN IF NOT EXISTS frame_label VARCHAR(255) NULL",
        ):
            try:
                cur.execute(ddl)
            except Exception:
                pass


def push_browse_step(conn, job_id, channel, recipient_uid, text):
    """Schreibt einen Live-Schritt. Ein Fehler hier darf den Browse-Job NIE stoppen."""
    try:
        ch = "console" if channel == "console" else "global"
        rcp = int(recipient_uid) if recipient_uid else None
        with conn.cursor() as cur:
            cur.execute(
                "INSERT INTO stu_ember_browse_steps (job_id, channel, recipient_uid, text) "
                "VALUES (%s, %s, %s, %s)",
                (int(job_id), ch, rcp, str(text)[:800]),
            )
    except Exception:
        pass


def push_browse_frame(conn, job_id, channel, recipient_uid, step, b64_data,
                      cx=None, cy=None, vw=None, vh=None, cursor_click=False,
                      page_url=None, frame_label=None):
    """Speichert einen privaten Live-Frame. Ein Fehler darf den Browse-Job nie stoppen."""
    try:
        if not b64_data or len(b64_data) > 4_100_000:
            return
        ch = "console" if channel == "console" else "global"
        rcp = int(recipient_uid) if recipient_uid else None
        with conn.cursor() as cur:
            cur.execute(
                "INSERT INTO stu_ember_browse_frames "
                "(job_id, channel, recipient_uid, step, b64, cx, cy, vw, vh, cursor_click, page_url, frame_label) "
                "VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)",
                (
                    int(job_id), ch, rcp, int(step), str(b64_data),
                    float(cx) if cx is not None else None,
                    float(cy) if cy is not None else None,
                    int(vw) if vw is not None else None,
                    int(vh) if vh is not None else None,
                    1 if cursor_click else 0,
                    str(page_url or "")[:2048] or None,
                    str(frame_label or "")[:255] or None,
                ),
            )
    except Exception:
        pass


def cleanup_browse_frames(conn, cfg):
    """Begrenzt die Datenbankgroesse ohne laufende oder neue Jobs anzutasten."""
    hours = cfg_int(cfg, "STU_EMBER_BROWSE_FRAME_RETENTION_HOURS", 24, 1, 168)
    try:
        with conn.cursor() as cur:
            cur.execute(
                "DELETE FROM stu_ember_browse_frames "
                "WHERE created_at < (NOW() - INTERVAL %s HOUR)",
                (hours,),
            )
    except Exception:
        pass


def claim_next_job(conn):
    """Atomar genau einen queued-Job auf running setzen und zurueckgeben."""
    with conn.cursor() as cur:
        cur.execute("SELECT id FROM stu_ember_browse_jobs WHERE status='queued' ORDER BY id ASC LIMIT 1")
        row = cur.fetchone()
        if not row:
            return None
        job_id = row["id"]
        cur.execute(
            "UPDATE stu_ember_browse_jobs SET status='running', started_at=NOW(), worker_pid=%s "
            "WHERE id=%s AND status='queued'",
            (os.getpid(), job_id),
        )
        if cur.rowcount != 1:
            return None  # ein anderer Worker war schneller
        cur.execute("SELECT * FROM stu_ember_browse_jobs WHERE id=%s", (job_id,))
        return cur.fetchone()


def finish_job(conn, job_id, status, result=None, steps=None, error=None, shot=None):
    with conn.cursor() as cur:
        cur.execute(
            "UPDATE stu_ember_browse_jobs SET status=%s, result=%s, steps_json=%s, error=%s, "
            "screenshot_path=%s, finished_at=NOW() WHERE id=%s",
            (status, result, json.dumps(steps or [], ensure_ascii=False), error, shot, job_id),
        )


# --- Lock auf der EIGENEN Session ------------------------------------------------


def lock_acquire(conn, cfg):
    with conn.cursor() as cur:
        cur.execute("SELECT GET_LOCK(%s, 0) AS got", (lock_name(cfg, "global_ollama"),))
        return int((cur.fetchone() or {}).get("got") or 0) == 1


def lock_release(conn, cfg):
    try:
        with conn.cursor() as cur:
            cur.execute("SELECT RELEASE_LOCK(%s)", (lock_name(cfg, "global_ollama"),))
    except Exception:
        pass


# --- Ember Presence / Chat -------------------------------------------------------


def set_browse_afk(conn, cfg, on):
    uid = int(cfg["STU_EMBER_USER_ID"])
    val = "1" if on else "0"
    with conn.cursor() as cur:
        cur.execute("UPDATE stu_kv SET value=%s WHERE k=%s AND user_id=%s", (val, KV_AFK_KEY, uid))
        if cur.rowcount == 0:
            try:
                cur.execute("INSERT INTO stu_kv (k, user_id, value) VALUES (%s,%s,%s)",
                            (KV_AFK_KEY, uid, val))
            except Exception:
                # falls (k,user_id) nicht unique ist o.ae. - best effort
                cur.execute("UPDATE stu_kv SET value=%s WHERE k=%s AND user_id=%s",
                            (val, KV_AFK_KEY, uid))
    # Presence-Zeile (falls vorhanden) ebenfalls flippen - schadet nie.
    try:
        with conn.cursor() as cur:
            cur.execute(
                "UPDATE stu_chat_presence SET afk=%s WHERE channel='global' AND user_id=%s AND character_id=%s",
                (1 if on else 0, uid, cfg["STU_EMBER_CHARACTER_ID"]),
            )
    except Exception:
        pass


def touch_console_session(conn, uid, session_id, message_id):
    if not session_id or not message_id:
        return
    try:
        with conn.cursor() as cur:
            cur.execute(
                "UPDATE stu_console_sessions "
                "SET last_message_id=GREATEST(COALESCE(last_message_id,0),%s), "
                "since_id=GREATEST(COALESCE(since_id,0),%s), updated_at=NOW() "
                "WHERE id=%s AND user_id=%s",
                (int(message_id), int(message_id), str(session_id), int(uid)),
            )
    except Exception:
        pass


def insert_system_message(conn, cfg, text, channel="global", recipient_uid=None, session_id=None):
    ch = "console" if channel == "console" else "global"
    uid = int(recipient_uid) if (ch == "console" and recipient_uid) else int(cfg["STU_EMBER_USER_ID"])
    scoped_session = str(session_id or "") if ch == "console" else None
    with conn.cursor() as cur:
        cur.execute(
            "INSERT INTO stu_chat_messages "
            "(channel, alliance_id, user_id, session_id, character_id, character_name, message, created_at) "
            "VALUES (%s, NULL, %s, %s, 'system', 'System', %s, NOW())",
            (ch, uid, scoped_session, text),
        )
        message_id = int(cur.lastrowid or 0)
    if ch == "console":
        touch_console_session(conn, uid, scoped_session, message_id)
    return message_id


SAFE_THINKING_COMPLETE = (
    "ANFRAGE ANALYSIERT\n"
    "KONTEXT ABGEGLICHEN\n"
    "AUSGABE GEPRUEFT\n"
    "ANTWORT BEREIT"
)


def insert_ember_message(conn, cfg, text, thinking="browse", channel="global",
                         recipient_uid=None, session_id=None, reply_to_id=None):
    """Spiegelt ember_insert(). Im Console-Kanal wird nur der feste Denkstatus gespeichert."""
    ch = "console" if channel == "console" else "global"
    uid = int(recipient_uid) if (ch == "console" and recipient_uid) else int(cfg["STU_EMBER_USER_ID"])
    cid = cfg["STU_EMBER_CHARACTER_ID"]
    name = cfg["STU_EMBER_CHARACTER_NAME"]
    scoped_session = str(session_id or "") if ch == "console" else None
    scoped_reply = int(reply_to_id) if (ch == "console" and reply_to_id) else None
    stored_thinking = SAFE_THINKING_COMPLETE if ch == "console" else thinking
    with conn.cursor() as cur:
        # thinking_content-Spalte ist optional - wie in ember_insert() defensiv behandeln
        try:
            cur.execute("SELECT thinking_content FROM stu_chat_messages LIMIT 1")
            has_think = True
        except Exception:
            has_think = False
        if has_think:
            cur.execute(
                "INSERT INTO stu_chat_messages "
                "(channel, alliance_id, user_id, session_id, reply_to_id, character_id, character_name, message, thinking_content, created_at) "
                "VALUES (%s, NULL, %s, %s, %s, %s, %s, %s, %s, NOW())",
                (ch, uid, scoped_session, scoped_reply, cid, name, text, stored_thinking),
            )
        else:
            cur.execute(
                "INSERT INTO stu_chat_messages "
                "(channel, alliance_id, user_id, session_id, reply_to_id, character_id, character_name, message, created_at) "
                "VALUES (%s, NULL, %s, %s, %s, %s, %s, %s, NOW())",
                (ch, uid, scoped_session, scoped_reply, cid, name, text),
            )
        message_id = int(cur.lastrowid or 0)
    if ch == "console":
        touch_console_session(conn, uid, scoped_session, message_id)
    return message_id


# ----------------------------------------------------------------------------- ollama


ACTION_SYS = (
    "Du bist ein nuechterner Web-Navigations-Agent (KEIN Charakter, KEINE Persoenlichkeit).\n"
    "Du bekommst ein ZIEL, eine Liste von SUCHTREFFERN (Titel, URL, Snippet) aus einer Websuche, "
    "sowie die aktuell geoeffnete Seite (URL, Titel, interaktive Elemente, sichtbarer Text).\n"
    "WICHTIG - so gehst du vor:\n"
    "1. Wenn ein SUCHTREFFER-Snippet die Antwort auf das ZIEL schon enthaelt (z.B. Temperatur, Zahl, "
    "Fakt), nutze SOFORT \"done\" und schreibe den konkreten Befund in die summary.\n"
    "2. Reicht das Snippet nicht, oeffne die passendste Treffer-URL mit "
    '{"action":"goto","url":"<treffer-url>"} und lies dann den sichtbaren Text.\n'
    "3. Steht die Antwort im sichtbaren Text der geoeffneten Seite, nutze \"done\" mit konkretem Befund.\n"
    "Antworte AUSSCHLIESSLICH mit EINEM JSON-Objekt. Keine Erklaerung, kein Markdown, kein Text drumherum.\n"
    "Erlaubte Aktionen:\n"
    '{"action":"goto","url":"https://..."}\n'
    '{"action":"click","role":"link","name":"exakter Name aus der Liste"}\n'
    '{"action":"scroll"}\n'
    '{"action":"done","summary":"konkreter Befund zum Ziel"}\n'
    'Pro Schritt GENAU EINE Aktion. Tippe NICHT in Suchfelder - die Suche ist bereits erfolgt '
    '(siehe SUCHTREFFER). Hast du genug Infos, nutze "done".'
)

REPORT_SYS = (
    "Du bist Ember. Locker, kleinschreibung, du nutzt ... als Stilmittel. "
    "Du hast gerade selbst im Netz nachgeschaut. Fasse den Befund in 1-3 lockeren Saetzen "
    "fuer den Chat zusammen, als haettest du es eben selbst recherchiert. "
    "Kein Markdown, keine Aufzaehlung, keine Ueberschriften."
)


def ollama_chat(cfg, system, user, want_json, num_predict=256, temperature=0.2, think=None, timeout=120):
    payload = {
        "model": cfg["STU_EMBER_MODEL"],
        "messages": [
            {"role": "system", "content": system},
            {"role": "user", "content": user},
        ],
        "stream": False,
        "keep_alive": -1,
        "options": {"temperature": temperature, "num_predict": num_predict},
    }
    if want_json:
        payload["format"] = "json"  # erzwingt valides JSON
    # think=False schaltet das Thinking NUR fuer diesen Call ab (z.B. Aktions-Modus).
    # think:true wird NIE gesetzt - das Modelfile-Thinking der Chat-Ember bleibt unberuehrt.
    if think is False:
        payload["think"] = False
    # Kurze, getrennte Verbindungsfrist plus konfigurierbare Lesefrist. So ist ein
    # nicht erreichbarer Dienst schnell erkennbar, waehrend laufende Inferenz nicht
    # faelschlich als Browserfehler abgebrochen wird.
    r = requests.post(cfg["STU_EMBER_OLLAMA_URL"], json=payload, timeout=(10, timeout))
    r.raise_for_status()
    msg = r.json().get("message") or {}
    content = (msg.get("content") or "").strip()
    # Notnagel: falls doch mal alles im thinking-Block landet, dort nach JSON greifen
    if not content and msg.get("thinking"):
        content = (msg.get("thinking") or "").strip()
    return content


def browse_action_call(cfg, user_msg, log=None):
    """Fordert die naechste Browser-Aktion an.

    Ein Read-Timeout wird absichtlich nicht wiederholt: Ollama kann den ersten
    Request serverseitig noch berechnen. Nur ein unmittelbarer Verbindungsfehler
    darf optional erneut versucht werden.
    """
    timeout = cfg_int(cfg, "STU_EMBER_BROWSE_AGENT_TIMEOUT", 420, 30, 1800)
    retries = cfg_int(cfg, "STU_EMBER_BROWSE_AGENT_RETRIES", 0, 0, 2)
    for attempt in range(retries + 1):
        try:
            return ollama_chat(
                cfg,
                ACTION_SYS,
                user_msg,
                want_json=False,
                num_predict=256,
                temperature=0.1,
                think=False,
                timeout=timeout,
            )
        except requests.exceptions.ReadTimeout:
            raise
        except (requests.exceptions.ConnectTimeout, requests.exceptions.ConnectionError) as exc:
            if attempt >= retries:
                raise
            if log:
                log(
                    "  ollama-verbindung unterbrochen "
                    f"({exc}); neuer versuch {attempt + 2}/{retries + 1}"
                )
            time.sleep(1.0)
    raise RuntimeError("Ollama-Aktionsaufruf ohne Ergebnis")


def parse_action(raw):
    raw = (raw or "").strip()
    try:
        return json.loads(raw)
    except Exception:
        pass
    # Fallback: erstes {...} herausschneiden
    a, b = raw.find("{"), raw.rfind("}")
    if a >= 0 and b > a:
        try:
            return json.loads(raw[a:b + 1])
        except Exception:
            return None
    return None


# ----------------------------------------------------------------------------- a11y


ACTIONABLE_ROLES = {
    "link", "button", "textbox", "searchbox", "combobox",
    "checkbox", "radio", "menuitem", "tab", "option",
}


def collect_elements(page, limit=40):
    """Accessibility-Tree flach in (role, name)-Liste umwandeln."""
    out = []
    try:
        snap = page.accessibility.snapshot()
    except Exception:
        snap = None

    def walk(node):
        if not node or len(out) >= limit:
            return
        role = (node.get("role") or "").lower()
        name = (node.get("name") or "").strip()
        if role in ACTIONABLE_ROLES and name:
            out.append({"role": role, "name": name[:120]})
        for child in node.get("children", []) or []:
            walk(child)

    if snap:
        walk(snap)
    # dedupe, Reihenfolge erhalten
    seen, uniq = set(), []
    for e in out:
        key = (e["role"], e["name"])
        if key not in seen:
            seen.add(key)
            uniq.append(e)
    return uniq[:limit]


def render_elements(elements):
    return "\n".join(f'{i+1}. [{e["role"]}] "{e["name"]}"' for i, e in enumerate(elements)) or "(keine)"


def page_text(page, limit=1800):
    """Sichtbarer Seitentext, bereinigt und gekuerzt. Damit der Agent Inhalte LESEN kann
    (nicht nur interaktive Elemente) und z.B. ein Wetter direkt aus dem Text melden kann."""
    try:
        raw = page.inner_text("body")
    except Exception:
        return "(kein Text lesbar)"
    lines = []
    for ln in raw.splitlines():
        s = ln.strip()
        if s:
            lines.append(s)
    txt = "\n".join(lines)
    if len(txt) > limit:
        txt = txt[:limit] + " …"
    return txt or "(kein Text)"


def searxng_search(cfg, query, log=None):
    """Lokale SearXNG-JSON-Suche (dieselbe Instanz wie chat.php, Key STU_EMBER_WEB_URL).
    Laeuft serverseitig gegen 127.0.0.1 -> kein Bot-CAPTCHA. Gibt Liste [{title,url,content}] zurueck."""
    base = (cfg.get("STU_EMBER_WEB_URL") or "http://127.0.0.1:18889/search").strip()
    try:
        limit = max(1, min(10, int(cfg.get("STU_EMBER_WEB_LIMIT") or 5)))
    except (TypeError, ValueError):
        limit = 5
    try:
        timeout = max(3, min(20, int(cfg.get("STU_EMBER_WEB_TIMEOUT") or 12)))
    except (TypeError, ValueError):
        timeout = 12
    params = {"q": query, "format": "json", "language": "de", "safesearch": "1"}
    try:
        resp = requests.get(base, params=params, timeout=timeout,
                            headers={"User-Agent": "STU-Ember/1.0 (+browse-worker)"})
        resp.raise_for_status()
        data = resp.json()
    except Exception as exc:
        if log:
            log(f"  searxng-fehler: {exc}")
        return []
    out = []
    for item in (data.get("results") or []):
        if not isinstance(item, dict):
            continue
        title = (item.get("title") or "").strip()
        url = (item.get("url") or "").strip()
        content = (item.get("content") or "").strip()
        if not (title or content):
            continue
        out.append({"title": title, "url": url, "content": content})
        if len(out) >= limit:
            break
    return out


def render_results(results):
    if not results:
        return "(keine Suchtreffer)"
    rows = []
    for i, r in enumerate(results):
        rows.append(f'{i+1}. {r["title"]} - {r["url"]}\n   {r["content"][:300]}')
    return "\n".join(rows)


def safe_browser_url(value):
    """Erlaubt fuer Playwright und gerenderte Treffer nur normale Web-URLs."""
    candidate = str(value or "").strip()
    try:
        parsed = urlsplit(candidate)
    except Exception:
        return ""
    if parsed.scheme.lower() not in ("http", "https") or not parsed.hostname:
        return ""
    return candidate


def render_search_browser_html(query, results):
    """Erzeugt die erste sichtbare Browserseite aus den lokalen SearXNG-Treffern.
    Alle fremden Inhalte werden escaped; es wird niemals Suchtreffer-HTML ausgefuehrt."""
    safe_query = html.escape(str(query or ""), quote=True)
    cards = []
    for index, result in enumerate(results, start=1):
        title = html.escape(str(result.get("title") or "Unbenannter Treffer"), quote=True)
        target_raw = safe_browser_url(result.get("url"))
        target = html.escape(target_raw, quote=True)
        snippet = html.escape(str(result.get("content") or "Keine Vorschau verfuegbar."), quote=True)
        link = (
            f'<a class="result-title" href="{target}">{title}</a>'
            if target else f'<div class="result-title">{title}</div>'
        )
        cards.append(
            '<article class="result">'
            f'<span class="result-index">{index:02d}</span>'
            f'<div>{link}<div class="result-url">{target}</div>'
            f'<p>{snippet}</p></div></article>'
        )
    if not cards:
        cards.append(
            '<div class="empty"><strong>Keine Treffer empfangen.</strong>'
            '<span>Die lokale Suchschnittstelle lieferte keine auswertbare Vorschau.</span></div>'
        )
    return f"""<!doctype html>
<html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width">
<style>
  *{{box-sizing:border-box}} html,body{{margin:0;min-height:100%;background:#f1f5fb;color:#101626}}
  body{{font-family:Arial,sans-serif;background-image:radial-gradient(#5b92ff22 1px,transparent 1px);background-size:20px 20px}}
  header{{position:sticky;top:0;padding:28px 42px 24px;background:#ffffffee;border-bottom:1px solid #5b92ff55;box-shadow:0 10px 30px #46699815}}
  .sig{{font:12px monospace;letter-spacing:.2em;color:#5b92ff;text-transform:uppercase}}
  h1{{margin:8px 0 18px;font-size:28px;letter-spacing:.03em}}
  .search{{display:flex;align-items:center;gap:12px;padding:14px 18px;border:1px solid #5b92ff88;background:#fff;clip-path:polygon(0 0,calc(100% - 14px) 0,100% 14px,100% 100%,14px 100%,0 calc(100% - 14px))}}
  .search b{{color:#d58b28}} .query{{font-size:18px}}
  main{{width:min(100% - 84px,980px);margin:24px auto 60px}}
  .count{{margin:0 0 14px;font:12px monospace;letter-spacing:.14em;color:#65738b;text-transform:uppercase}}
  .result{{display:grid;grid-template-columns:44px 1fr;gap:14px;margin:0 0 12px;padding:18px 20px;border:1px solid #5b92ff3d;background:#ffffffec;box-shadow:0 8px 24px #334f7c10}}
  .result-index{{position:relative;display:grid;width:34px;height:34px;place-items:center;color:#a56a1d;font:12px monospace}}
  .result-index::before{{position:absolute;inset:4px;border:1px solid #e29b3966;content:"";transform:rotate(45deg)}}
  .result-title{{color:#275fc9;font-size:18px;font-weight:700;text-decoration:none}}
  .result-url{{margin-top:5px;color:#2a9a70;font:11px monospace;overflow-wrap:anywhere}}
  p{{margin:9px 0 0;color:#445168;font-size:14px;line-height:1.5}}
  .empty{{display:grid;gap:10px;padding:30px;border:1px solid #e29b3966;background:#fffaf2}}
</style></head><body>
<header><div class="sig">Project Starlight // lokaler Suchkern</div><h1>SearXNG Recherche</h1>
<div class="search"><b>◇</b><span class="query">{safe_query}</span></div></header>
<main><div class="count">{len(results)} Treffer // ungepruefte Webdaten</div>{''.join(cards)}</main>
</body></html>"""


def _wait_navigated(page, url_before, timeout=8000):
    """Wartet auf echte Navigation (URL-Wechsel). True wenn navigiert."""
    try:
        page.wait_for_url(lambda u: u != url_before, timeout=timeout)
        try:
            page.wait_for_load_state("domcontentloaded", timeout=5000)
        except Exception:
            pass
        return True
    except Exception:
        return False


def do_action(page, act, cfg, before_click=None):
    """Fuehrt eine Modell-Aktion aus. Gibt (kurz-log, done?, summary) zurueck."""
    a = (act.get("action") or "").lower()
    if a == "goto":
        url = safe_browser_url(act.get("url"))
        if not url:
            raise ValueError("nur http/https-navigation erlaubt")
        page.goto(url, wait_until="domcontentloaded", timeout=30000)
        return (f"goto {url}", False, None)
    if a == "click":
        url_before = page.url
        loc = page.get_by_role(act.get("role", "link"), name=act.get("name", ""), exact=False).first
        try:
            loc.scroll_into_view_if_needed(timeout=5000)
            box = loc.bounding_box(timeout=5000)
            viewport = page.viewport_size or {"width": 1280, "height": 900}
            if box and callable(before_click):
                before_click(box, viewport)
        except Exception:
            # Ein fehlender Cursor-Frame darf den echten Klick nicht verhindern.
            pass
        loc.click(timeout=15000)
        _wait_navigated(page, url_before, timeout=8000)
        return (f'click [{act.get("role")}] "{act.get("name")}"', False, None)
    if a == "type":
        loc = page.get_by_role(act.get("role", "textbox"), name=act.get("name", ""), exact=False).first
        loc.fill(act.get("text", ""), timeout=15000)
        if act.get("submit"):
            url_before = page.url
            loc.press("Enter")
            if not _wait_navigated(page, url_before, timeout=8000):
                # Enter loeste keine Navigation aus -> Such-/Submit-Button suchen und klicken
                try:
                    page.get_by_role(
                        "button", name=re.compile(r"such|search|los|go|finden|abschick", re.I)
                    ).first.click(timeout=4000)
                    _wait_navigated(page, url_before, timeout=8000)
                except Exception:
                    pass
        return (f'type "{act.get("text","")[:40]}" submit={bool(act.get("submit"))}', False, None)
    if a == "scroll":
        page.mouse.wheel(0, 1400)
        page.wait_for_timeout(400)
        return ("scroll", False, None)
    if a == "done":
        return ("done", True, act.get("summary", ""))
    return (f"unbekannte aktion: {a}", False, None)


# ----------------------------------------------------------------------------- browse


def run_browse(cfg, job, log, push_frame=None):
    goal = job["goal"]
    start_url = safe_browser_url(job.get("start_url"))
    max_steps = int(job.get("max_steps") or cfg_int(
        cfg, "STU_EMBER_BROWSE_MAX_STEPS", 12, 1, 40
    ))
    agent_timeout = cfg_int(cfg, "STU_EMBER_BROWSE_AGENT_TIMEOUT", 420, 30, 1800)
    frame_quality = cfg_int(cfg, "STU_EMBER_BROWSE_FRAME_QUALITY", 45, 25, 75)
    frame_burst = cfg_int(cfg, "STU_EMBER_BROWSE_FRAME_BURST", 3, 1, 5)
    frame_interval_ms = cfg_int(
        cfg, "STU_EMBER_BROWSE_FRAME_INTERVAL_MS", 240, 120, 800
    )
    steps, summary = [], ""

    shot_dir = cfg.get("STU_EMBER_BROWSE_SCREENSHOT_DIR") or os.path.join(
        os.path.dirname(os.path.abspath(__file__)), "..", "uploads", "ember_browse")
    os.makedirs(shot_dir, exist_ok=True)
    shot_path = os.path.join(shot_dir, f"job_{job['id']}.png")

    with sync_playwright() as pw:
        browser = pw.chromium.launch(headless=True, args=["--no-sandbox", "--disable-dev-shm-usage"])
        ctx = browser.new_context(
            viewport={"width": 1280, "height": 900},
            user_agent="Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124 Safari/537.36",
            locale="de-DE",
        )
        page = ctx.new_page()
        # Der Cursor bleibt zwischen Frames an seiner letzten echten Position sichtbar.
        # Erst ein tatsaechlicher Playwright-Klick setzt cursor_click fuer genau einen Frame.
        cursor_state = {
            "cx": 832.0,
            "cy": 288.0,
            "vw": 1280,
            "vh": 900,
        }

        def grab_frame(step, label, url_override=None, cursor=None, cursor_click=False):
            """Nimmt den sichtbaren 1280x900-Viewport auf und reicht ihn privat weiter."""
            if not callable(push_frame):
                return False
            try:
                raw = page.screenshot(
                    type="jpeg", quality=frame_quality, full_page=False, animations="disabled"
                )
                # Schutz gegen unerwartet grosse Seiten/Browserfehler.
                if not raw or len(raw) > 3_000_000:
                    return False
                encoded = base64.b64encode(raw).decode("ascii")
                if cursor:
                    cursor_state.update(cursor)
                pointer = dict(cursor_state)
                push_frame(
                    int(step), encoded,
                    pointer.get("cx"), pointer.get("cy"),
                    pointer.get("vw"), pointer.get("vh"),
                    str(url_override if url_override is not None else page.url),
                    str(label or "Browser aktiv"),
                    bool(cursor_click),
                )
                return True
            except Exception as exc:
                log(f"  live-frame uebersprungen: {exc}")
                return False

        def grab_burst(step, label, url_override=None, count=None):
            """Erfasst wenige echte Folgeframes, damit spaetes Rendering sichtbar wird."""
            burst_count = frame_burst if count is None else max(1, min(5, int(count)))
            for frame_index in range(burst_count):
                if not grab_frame(step, label, url_override=url_override):
                    break
                if frame_index + 1 < burst_count:
                    page.wait_for_timeout(frame_interval_ms)

        # Suchschritt = lokale SearXNG-JSON (stabil, kein CAPTCHA). Die Treffer bekommt der Agent
        # mitgegeben; er kann sofort done (Snippet reicht) oder eine Treffer-URL oeffnen und lesen.
        results = searxng_search(cfg, goal, log=log)
        log(f"  searxng: {len(results)} treffer fuer {goal!r}")
        treffer_block = render_results(results)
        # Die Suchtreffer sind selbst die erste Browserseite. Damit sieht der Nutzer
        # auch bei einem sofortigen Snippet-Ergebnis eine echte Agentenansicht statt
        # eines technischen Textprotokolls.
        try:
            page.set_content(render_search_browser_html(goal, results), wait_until="domcontentloaded")
            grab_burst(
                0,
                f"SearXNG - {len(results)} Treffer",
                "searxng://local/search",
                count=min(2, frame_burst),
            )
        except Exception as exc:
            log(f"  suchansicht konnte nicht gerendert werden: {exc}")
        if start_url:
            try:
                page.goto(start_url, wait_until="domcontentloaded", timeout=30000)
                grab_burst(0, "Startseite geoeffnet")
            except Exception as exc:
                steps.append(f"start-goto fehler: {exc}")

        last_sig = None
        stall = 0
        for step in range(1, max_steps + 1):
            try:
                title = page.title()
            except Exception:
                title = ""
            url = page.url
            elements = collect_elements(page)
            visible = page_text(page)
            user_msg = (
                f"ZIEL: {goal}\n"
                f"SUCHTREFFER (SearXNG):\n{treffer_block}\n\n"
                f"AKTUELLE URL: {url}\n"
                f"TITEL: {title}\n"
                f"INTERAKTIVE ELEMENTE:\n{render_elements(elements)}\n"
                f"SICHTBARER TEXT (Auszug):\n{visible}\n"
                f"Letzte Schritte: {', '.join(steps[-4:]) if steps else '(keine)'}\n"
                f"Naechste Aktion als JSON?"
            )
            try:
                # want_json=False: KEIN format:json-Grammar-Zwang -> umgeht den intermittierenden
                # Stall des Thinking-Modells. ACTION_SYS verlangt ohnehin "AUSSCHLIESSLICH ein
                # JSON-Objekt", think=False ist gesetzt, und parse_action() schneidet das {...}
                # notfalls aus Freitext. num_predict leicht hoeher als Headroom fuer evtl. Vorrede.
                raw = browse_action_call(cfg, user_msg, log=log)
            except requests.exceptions.ReadTimeout:
                # Das ist kein Playwright-Fehler: Navigation und Seitenerfassung liefen,
                # aber Ollama lieferte innerhalb seiner eigenen Frist keine neue Aktion.
                log(
                    f"  ollama-aktionsaufruf nach {agent_timeout}s ohne antwort; "
                    "playwright-browser ist intakt -> nutze suchtreffer"
                )
                steps.append(f"step{step}: ollama-read-timeout -> suchtreffer")
                break
            except Exception as exc:
                # Ollama nicht erreichbar oder Protokollfehler: Browser-Job nicht verlieren.
                # Die bereits vorhandenen SearXNG-Treffer bleiben als sauberer Fallback.
                log(f"  ollama-aktionsaufruf fehlgeschlagen ({exc}) -> nutze suchtreffer")
                steps.append(f"step{step}: ollama-fehler -> suchtreffer")
                break
            act = parse_action(raw)
            log(f"  step {step}: roh={raw[:200]!r}")
            if not act:
                steps.append(f"step{step}: ungueltiges JSON")
                continue

            sig = json.dumps({k: act.get(k) for k in ("action", "role", "name", "text", "url")},
                             sort_keys=True, ensure_ascii=False)
            stall = stall + 1 if sig == last_sig else 0
            last_sig = sig

            try:
                def before_click(box, viewport):
                    click_cursor = {
                        "cx": float(box.get("x", 0)) + float(box.get("width", 0)) / 2,
                        "cy": float(box.get("y", 0)) + float(box.get("height", 0)) / 2,
                        "vw": int(viewport.get("width") or 1280),
                        "vh": int(viewport.get("height") or 900),
                    }
                    grab_frame(
                        step,
                        f'Klickziel: {act.get("name") or act.get("role") or "Element"}',
                        cursor=click_cursor,
                        cursor_click=True,
                    )

                desc, done, summ = do_action(page, act, cfg, before_click=before_click)
            except PWTimeout:
                steps.append(f"step{step}: timeout bei {act.get('action')}")
                continue
            except Exception as exc:
                steps.append(f"step{step}: fehler {act.get('action')}: {exc}")
                continue
            steps.append(f"step{step}: {desc}")
            if done:
                summary = summ or ""
                grab_burst(step, "Recherche ausgewertet", count=min(2, frame_burst))
                break
            grab_burst(step, desc)
            if stall >= 3:
                steps.append(f"step{step}: abbruch (loop ohne fortschritt)")
                break

        if not summary:
            # kein sauberes done -> erst offene Seite, sonst die SearXNG-Snippets als Notbefund
            try:
                body = (page.inner_text("body")[:1500]).strip() if page.url not in ("", "about:blank") else ""
            except Exception:
                body = ""
            if body:
                summary = body
            elif results:
                summary = " | ".join(
                    f'{r["title"]}: {r["content"]}' for r in results[:3] if (r["title"] or r["content"])
                ).strip() or "(kein klarer Befund)"
            else:
                summary = "(kein klarer Befund)"
        try:
            page.screenshot(path=shot_path, full_page=False)
        except Exception:
            shot_path = None
        ctx.close()
        browser.close()

    return summary, steps, shot_path


# ----------------------------------------------------------------------------- job


def clear_browse_afk_safe(conn, cfg):
    """AFK-Flag IMMER zuruecksetzen - notfalls ueber eine frische Verbindung,
    damit Ember nie AFK haengen bleibt, falls die Hauptverbindung kaputt ist."""
    try:
        set_browse_afk(conn, cfg, False)
        return
    except Exception:
        pass
    try:
        fresh = db_connect(cfg, autocommit=True)
        try:
            set_browse_afk(fresh, cfg, False)
        finally:
            fresh.close()
    except Exception as exc:
        print(f"WARN konnte AFK-Flag nicht zuruecksetzen: {exc}", flush=True)


def clean_report(text):
    """Entfernt versehentliche Denk-/Protokoll-Reste, die nie in den Chat sollen.
    Gibt '' zurueck, wenn der Output klar ein Denkprotokoll ist -> Aufrufer nimmt den Notnagel."""
    t = (text or "").strip()
    if not t:
        return ""
    markers = (
        "persona:", "* persona", "task:", "constraint:", "strategy:",
        "input text", "alternative interpretation", "i am ember", "i have to report",
        "response draft:", "selected response:", "final decision:",
        "checking constraints:", "constraint check:", "maybe i should",
    )
    low = t.lower()
    if any(m in low for m in markers):
        return ""  # eindeutig Denkprotokoll -> unbrauchbar
    # fuehrende Markdown-/Bullet-Zeilen rauswerfen
    lines = [ln for ln in t.splitlines() if not ln.strip().startswith(("*", "#", "-", ">"))]
    cleaned = "\n".join(lines).strip()
    return cleaned or t


def process_job(conn, cfg, job, log):
    reason = cfg["STU_EMBER_BROWSE_AFK_REASON"]
    name = cfg["STU_EMBER_CHARACTER_NAME"]
    channel = (job.get("channel") or "global")
    recipient = job.get("recipient_uid")
    session_id = job.get("session_id")
    trigger_message_id = job.get("trigger_message_id")
    if channel == "console" and (
        not re.fullmatch(r"[A-Za-z0-9_-]{8,40}", str(session_id or ""))
        or not trigger_message_id
    ):
        error = "Console-Browse-Job ohne gueltige Sitzung oder Turn-Referenz"
        log(f"[job {job['id']}] FEHLER: {error}")
        finish_job(conn, job["id"], "error", error=error)
        return
    log(f"[job {job['id']}] Ziel: {job['goal']!r}  Channel: {channel}  Start: {job.get('start_url') or '(auto)'}")

    # Live-Schritt-Logger (Phase 3b): jede SAUBERE Surf-Zeile sofort in die Steps-Tabelle,
    # damit das Console-Browser-Fenster live mitlaufen kann. Interne [job ...]-Framing-/Fehler-
    # zeilen werden NICHT gespiegelt (nur stdout). Stdout-Log bleibt unveraendert.
    def jlog(msg):
        log(msg)
        line = str(msg).strip()
        # Rohe Modell-JSON bleibt im Serverlog. Die Agentenansicht zeigt nur einen
        # kurzen menschlichen Status und niemals interne Aktionsentwuerfe.
        if line and not line.startswith("[job") and " roh=" not in line:
            push_browse_step(conn, job["id"], channel, recipient, line)

    def jframe(step, b64_data, cx, cy, vw, vh, page_url, frame_label, cursor_click):
        push_browse_frame(
            conn, job["id"], channel, recipient, step, b64_data,
            cx=cx, cy=cy, vw=vw, vh=vh,
            cursor_click=cursor_click,
            page_url=page_url, frame_label=frame_label,
        )

    set_browse_afk(conn, cfg, True)
    insert_system_message(conn, cfg, f"{name} ist gerade nicht an der Tastatur. 💤 ({reason})",
                          channel=channel, recipient_uid=recipient, session_id=session_id)
    try:
        summary, steps, shot = run_browse(cfg, job, jlog, push_frame=jframe)
        jlog("fasse das ergebnis zusammen...")
        # Ergebnis in Embers Stimme zusammenfassen. think=False -> kein Denkprotokoll im Output.
        try:
            report_timeout = cfg_int(
                cfg, "STU_EMBER_BROWSE_REPORT_TIMEOUT", 420, 30, 1800
            )
            report = clean_report(ollama_chat(
                cfg, REPORT_SYS,
                f"ZIEL: {job['goal']}\nBEFUND: {summary[:1800]}\nWas meldest du im Chat?",
                want_json=False, num_predict=320, temperature=0.7, think=False,
                timeout=report_timeout,
            ))
        except requests.exceptions.ReadTimeout:
            log(
                f"[job {job['id']}] Ollama-Report nach {report_timeout}s ohne Antwort; "
                "nutze den vorhandenen Rohbefund."
            )
            report = ""
        except Exception as exc:
            log(f"[job {job['id']}] Report-Call fehlgeschlagen: {exc}")
            report = ""
        if not report:
            report = f"hab nachgeschaut... {summary[:300]}"
        insert_ember_message(
            conn,
            cfg,
            report,
            thinking="browse",
            channel=channel,
            recipient_uid=recipient,
            session_id=session_id,
            reply_to_id=trigger_message_id,
        )
        jlog("fertig - ergebnis ist da ✓")
        finish_job(conn, job["id"], "done", result=summary, steps=steps, shot=shot)
        log(f"[job {job['id']}] fertig.")
    except Exception:
        err = traceback.format_exc()
        log(f"[job {job['id']}] FEHLER:\n{err}")
        finish_job(conn, job["id"], "error", error=err[:4000])
        # Im Fehlerfall trotzdem freundlich aus dem AFK kommen (still, keine Meldung)
    finally:
        clear_browse_afk_safe(conn, cfg)  # Rueckkehr STILL - kein "wieder da"


# ----------------------------------------------------------------------------- main


_RUNNING = True


def _stop(*_):
    global _RUNNING
    _RUNNING = False


def main():
    ap = argparse.ArgumentParser(description="Ember CoreUI Browse-Worker")
    here = os.path.dirname(os.path.abspath(__file__))
    ap.add_argument("--config", default=os.environ.get(
        "STU_CONFIG", os.path.join(here, "..", "api", "config.local.php")))
    ap.add_argument("--once", action="store_true", help="genau einen Job abarbeiten, dann beenden")
    ap.add_argument("--poll", type=float, default=4.0, help="Poll-Intervall in Sekunden")
    args = ap.parse_args()

    signal.signal(signal.SIGTERM, _stop)
    signal.signal(signal.SIGINT, _stop)

    cfg = parse_php_config(os.path.abspath(args.config))
    if not cfg.get("STU_EMBER_BROWSE_SCREENSHOT_DIR"):
        cfg["STU_EMBER_BROWSE_SCREENSHOT_DIR"] = os.path.abspath(
            os.path.join(here, "..", "uploads", "ember_browse")
        )

    def log(msg):
        print(f"{datetime.now():%H:%M:%S} {msg}", flush=True)

    log(f"Worker startet. Modell={cfg['STU_EMBER_MODEL']} DB={cfg['STU_DB_NAME']} "
        f"Ember-Uid={cfg['STU_EMBER_USER_ID']} "
        f"Agent-Timeout={cfg_int(cfg, 'STU_EMBER_BROWSE_AGENT_TIMEOUT', 420, 30, 1800)}s "
        f"Report-Timeout={cfg_int(cfg, 'STU_EMBER_BROWSE_REPORT_TIMEOUT', 420, 30, 1800)}s "
        f"Frame-Q={cfg_int(cfg, 'STU_EMBER_BROWSE_FRAME_QUALITY', 45, 25, 75)} "
        f"Frame-TTL={cfg_int(cfg, 'STU_EMBER_BROWSE_FRAME_RETENTION_HOURS', 24, 1, 168)}h")

    # EINE Verbindung fuer alles -> der Ollama-Lock haengt an dieser Session und wird bei
    # Worker-Tod automatisch freigegeben.
    conn = db_connect(cfg, autocommit=True)
    ensure_jobs_table(conn)
    ensure_steps_table(conn)
    ensure_frames_table(conn)
    cleanup_browse_frames(conn, cfg)

    try:
        while _RUNNING:
            job = None
            try:
                job = claim_next_job(conn)
            except Exception as exc:
                log(f"DB-Fehler beim Claim: {exc}")
                try:
                    conn.ping(reconnect=True)
                except Exception:
                    conn = db_connect(cfg, autocommit=True)

            if not job:
                if args.once:
                    log("Keine Jobs in der Queue.")
                    break
                time.sleep(args.poll)
                continue

            # Lock holen - nur browsen wenn Ollama frei ist
            if not lock_acquire(conn, cfg):
                log(f"[job {job['id']}] Ollama belegt - Job zurueck in die Queue.")
                with conn.cursor() as cur:
                    cur.execute("UPDATE stu_ember_browse_jobs SET status='queued', started_at=NULL, "
                                "worker_pid=NULL WHERE id=%s", (job["id"],))
                time.sleep(args.poll)
                continue
            try:
                process_job(conn, cfg, job, log)
            finally:
                lock_release(conn, cfg)
            cleanup_browse_frames(conn, cfg)

            if args.once:
                break
    finally:
        lock_release(conn, cfg)
        try:
            conn.close()
        except Exception:
            pass
        log("Worker beendet.")


if __name__ == "__main__":
    main()
