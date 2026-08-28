#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
STU - Ember Python-Worker (v1.1.1.87)
=====================================
Fuehrt den Code aus, den Ember per [PY]...[/PY] im Chat erzeugt.

Was es tut:
  1. Pollt stu_ember_py_jobs nach 'queued' Jobs (atomar geclaimt, wie der Browse-Worker).
  2. Schreibt den Code in ein temporaeres Arbeitsverzeichnis ausserhalb von www.
  3. Startet einen wegwerfbaren Docker-Container und laesst den Code dort laufen.
  4. Schreibt stdout/stderr/Exitcode/Dauer zurueck in die Job-Zeile.

Warum Docker und nicht einfach subprocess:
  Der Worker laeuft als root (er braucht den Docker-Socket). Der Code, den Ember
  schreibt, laeuft NIEMALS in diesem Prozess, sondern nur im Container:
    - kein Mount des Ember-CoreUI-Projekts, daher keine Konfigurationsdatei im Container
    - --network bridge: volles Internet, aber die Loopback-Dienste des Hosts
      (MariaDB, Ollama, SearXNG auf 127.0.0.1) sind aus dem Container nicht erreichbar
    - --read-only Rootfs, Schreiben nur im Job-Verzeichnis und in /tmp (tmpfs)
    - --cap-drop ALL, --security-opt no-new-privileges, unprivilegierter Nutzer
    - harte Grenzen fuer RAM, CPU, Prozesse und Laufzeit

WICHTIG: Dieser Worker haelt bewusst NICHT den globalen Ollama-Lock. Der aufrufende
PHP-Request haelt ihn bereits fuer den kompletten Turn (Call #1 -> Python -> Call #2).
Wuerde der Worker ihn ebenfalls anfordern, gaebe es einen garantierten Deadlock.

Abhaengigkeiten:
    pip install pymysql          (die vorhandene /opt/ember-venv reicht)
    docker + gebautes Image      (siehe tools/ember_py.Dockerfile)

Start:
    python3 tools/ember_py_worker.py --once     # genau einen Job, dann Ende
    python3 tools/ember_py_worker.py --poll 2   # Dauerloop (systemd)
"""

import os
import re
import sys
import time
import shutil
import signal
import argparse
import subprocess
import tempfile
import traceback

try:
    import pymysql
except ImportError:
    sys.exit("Fehlt: pip install pymysql")

# ----------------------------------------------------------------------------- config

PROJECT_ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))

DEFAULTS = {
    "STU_DB_HOST": "127.0.0.1",
    "STU_DB_PORT": "13306",
    "STU_DB_NAME": "ember_coreui",
    "STU_DB_USER": "ember_coreui",
    "STU_DB_PASS": "",
    "STU_EMBER_PY_IMAGE": "ember-py:1",
    "STU_EMBER_PY_EXEC_TIMEOUT": "60",
    "STU_EMBER_PY_MEMORY": "512m",
    "STU_EMBER_PY_CPUS": "1.0",
    "STU_EMBER_PY_PIDS": "128",
    "STU_EMBER_PY_TMPFS_SIZE": "64m",
    "STU_EMBER_PY_STORE_CHARS": "16000",
    "STU_EMBER_PY_WORKDIR": os.path.join(PROJECT_ROOT, "var", "ember_py"),
}

DEFAULT_CONFIG = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", "api", "config.local.php"))


def parse_php_config(path):
    """Liest define('KEY', <expr>) aus config.local.php - identische Logik wie im
    Browse-Worker, damit es genau eine Quelle der Wahrheit gibt."""
    cfg = dict(DEFAULTS)
    try:
        with open(path, "r", encoding="utf-8", errors="replace") as fh:
            src = fh.read()
    except OSError as exc:
        print("[config] WARN konnte %s nicht lesen: %s" % (path, exc), flush=True)
        return cfg

    for key in list(cfg.keys()) + ["STU_DB_PORT"]:
        m = re.search(r"define\(\s*['\"]" + re.escape(key) + r"['\"]\s*,\s*(.+?)\)\s*;",
                      src, re.DOTALL)
        if not m:
            continue
        expr = m.group(1).strip()
        if "getenv(" in expr:
            env_m = re.search(r"getenv\(\s*['\"]([^'\"]+)['\"]\s*\)", expr)
            if env_m and os.environ.get(env_m.group(1)):
                cfg[key] = os.environ[env_m.group(1)]
                continue
        lit = re.findall(r"'((?:[^'\\]|\\.)*)'|\"((?:[^\"\\]|\\.)*)\"", expr)
        if lit:
            val = lit[-1][0] or lit[-1][1]
            cfg[key] = val.replace("\\'", "'").replace('\\"', '"')
        else:
            num = re.search(r"(-?\d+(?:\.\d+)?)", expr)
            if num:
                cfg[key] = num.group(1)
    return cfg


# ----------------------------------------------------------------------------- db


def db_connect(cfg):
    return pymysql.connect(
        host=cfg["STU_DB_HOST"],
        user=cfg["STU_DB_USER"],
        password=cfg["STU_DB_PASS"],
        database=cfg["STU_DB_NAME"],
        port=int(cfg.get("STU_DB_PORT", "13306") or "13306"),
        charset="utf8mb4",
        autocommit=True,
        cursorclass=pymysql.cursors.DictCursor,
    )


def ensure_jobs_table(conn):
    with conn.cursor() as cur:
        cur.execute("""
            CREATE TABLE IF NOT EXISTS stu_ember_py_jobs (
              id INT UNSIGNED NOT NULL AUTO_INCREMENT,
              status ENUM('queued','running','done','error') NOT NULL DEFAULT 'queued',
              code MEDIUMTEXT NOT NULL,
              stdout MEDIUMTEXT NULL,
              stderr MEDIUMTEXT NULL,
              exit_code INT NULL,
              duration_ms INT NULL,
              channel VARCHAR(32) NOT NULL DEFAULT 'global',
              trigger_user_id INT NULL,
              recipient_uid INT NULL,
              error TEXT NULL,
              worker_pid INT NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              started_at DATETIME NULL,
              finished_at DATETIME NULL,
              PRIMARY KEY (id),
              KEY idx_status (status, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        """)


def claim_next_job(conn):
    """Atomar genau einen queued-Job auf running setzen und zurueckgeben."""
    with conn.cursor() as cur:
        cur.execute("SELECT id FROM stu_ember_py_jobs WHERE status='queued' ORDER BY id ASC LIMIT 1")
        row = cur.fetchone()
        if not row:
            return None
        job_id = row["id"]
        cur.execute(
            "UPDATE stu_ember_py_jobs SET status='running', started_at=NOW(), worker_pid=%s "
            "WHERE id=%s AND status='queued'",
            (os.getpid(), job_id),
        )
        if cur.rowcount != 1:
            return None  # ein anderer Worker war schneller
        cur.execute("SELECT * FROM stu_ember_py_jobs WHERE id=%s", (job_id,))
        return cur.fetchone()


def finish_job(conn, job_id, status, stdout="", stderr="", exit_code=None,
               duration_ms=0, error=None, limit=16000):
    def clip(s):
        s = s or ""
        if len(s) <= limit:
            return s
        head = int(limit * 0.4)
        return s[:head] + "\n[... gekuerzt ...]\n" + s[-(limit - head):]

    with conn.cursor() as cur:
        cur.execute(
            "UPDATE stu_ember_py_jobs SET status=%s, stdout=%s, stderr=%s, exit_code=%s, "
            "duration_ms=%s, error=%s, finished_at=NOW() WHERE id=%s",
            (status, clip(stdout), clip(stderr), exit_code, int(duration_ms), error, job_id),
        )


# ----------------------------------------------------------------------------- ausfuehrung


def run_in_container(cfg, job_id, code):
    """Fuehrt den Code im Container aus. Rueckgabe: (stdout, stderr, exit_code, dauer_ms, fehler)."""
    base = cfg.get("STU_EMBER_PY_WORKDIR") or os.path.join(PROJECT_ROOT, "var", "ember_py")
    os.makedirs(base, exist_ok=True)
    workdir = tempfile.mkdtemp(prefix="job%d_" % job_id, dir=base)
    container = "ember-py-%d" % job_id
    timeout = int(float(cfg.get("STU_EMBER_PY_EXEC_TIMEOUT", "60")))

    try:
        script = os.path.join(workdir, "main.py")
        with open(script, "w", encoding="utf-8") as fh:
            fh.write(code)
        # Der Container laeuft unprivilegiert (65534) und muss ins Arbeitsverzeichnis
        # schreiben duerfen, falls Ember Dateien anlegt.
        os.chmod(workdir, 0o777)
        os.chmod(script, 0o644)

        cmd = [
            "docker", "run", "--rm",
            "--name", container,
            "--network", "bridge",          # volles Internet, aber kein Host-Loopback
            "--memory", str(cfg.get("STU_EMBER_PY_MEMORY", "512m")),
            "--memory-swap", str(cfg.get("STU_EMBER_PY_MEMORY", "512m")),
            "--cpus", str(cfg.get("STU_EMBER_PY_CPUS", "1.0")),
            "--pids-limit", str(cfg.get("STU_EMBER_PY_PIDS", "128")),
            "--cap-drop", "ALL",
            "--security-opt", "no-new-privileges",
            "--read-only",
            "--tmpfs", "/tmp:rw,noexec,nosuid,size=%s" % cfg.get("STU_EMBER_PY_TMPFS_SIZE", "64m"),
            "--user", "65534:65534",
            "-v", "%s:/work:rw" % workdir,
            "-w", "/work",
            "-e", "HOME=/tmp",
            "-e", "MPLBACKEND=Agg",
            "-e", "PYTHONUNBUFFERED=1",
            str(cfg.get("STU_EMBER_PY_IMAGE", "ember-py:1")),
            "python", "-I", "/work/main.py",
        ]

        started = time.time()
        try:
            proc = subprocess.run(
                cmd,
                stdout=subprocess.PIPE,
                stderr=subprocess.PIPE,
                timeout=timeout + 10,   # Reserve fuer Containerstart/-abbau
            )
            dur = int((time.time() - started) * 1000)
            return (
                proc.stdout.decode("utf-8", "replace"),
                proc.stderr.decode("utf-8", "replace"),
                proc.returncode,
                dur,
                None,
            )
        except subprocess.TimeoutExpired:
            dur = int((time.time() - started) * 1000)
            # Container haengt -> hart abraeumen, sonst laeuft er weiter.
            subprocess.run(["docker", "kill", container],
                           stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
            return ("", "", None, dur,
                    "timeout nach %ds - der Code lief zu lange" % timeout)
        except FileNotFoundError:
            return ("", "", None, 0, "docker ist auf diesem Host nicht installiert/erreichbar")
    finally:
        shutil.rmtree(workdir, ignore_errors=True)


def process_job(conn, cfg, job):
    job_id = int(job["id"])
    code = job.get("code") or ""
    limit = int(cfg.get("STU_EMBER_PY_STORE_CHARS", "16000"))
    print("[job %d] starte (%d Zeichen Code)" % (job_id, len(code)), flush=True)

    stdout, stderr, rc, dur, err = run_in_container(cfg, job_id, code)

    if err:
        finish_job(conn, job_id, "error", stdout, stderr, rc, dur, err, limit)
        print("[job %d] FEHLER: %s" % (job_id, err), flush=True)
        return

    # Auch ein Exitcode != 0 ist ein gueltiges Ergebnis: Ember soll den Traceback
    # sehen und selbst darauf reagieren duerfen, statt nur "ging nicht" zu hoeren.
    finish_job(conn, job_id, "done", stdout, stderr, rc, dur, None, limit)
    print("[job %d] fertig rc=%s in %dms (%d Zeichen stdout)"
          % (job_id, rc, dur, len(stdout)), flush=True)


# ----------------------------------------------------------------------------- main

_STOP = False


def _on_signal(signum, frame):
    global _STOP
    _STOP = True
    print("[worker] Signal %s - beende nach dem aktuellen Job" % signum, flush=True)


def main():
    ap = argparse.ArgumentParser(description="Ember Python-Worker")
    ap.add_argument("--config", default=DEFAULT_CONFIG, help="Pfad zu config.local.php")
    ap.add_argument("--once", action="store_true", help="genau einen Job abarbeiten")
    ap.add_argument("--poll", type=float, default=2.0, help="Pollintervall in Sekunden")
    args = ap.parse_args()

    cfg = parse_php_config(args.config)
    signal.signal(signal.SIGTERM, _on_signal)
    signal.signal(signal.SIGINT, _on_signal)

    conn = db_connect(cfg)
    ensure_jobs_table(conn)
    print("[worker] startet. Image=%s DB=%s Timeout=%ss"
          % (cfg.get("STU_EMBER_PY_IMAGE"), cfg.get("STU_DB_NAME"),
             cfg.get("STU_EMBER_PY_EXEC_TIMEOUT")), flush=True)

    while not _STOP:
        try:
            job = claim_next_job(conn)
        except Exception as exc:
            print("[worker] DB-Problem: %s - verbinde neu" % exc, flush=True)
            time.sleep(3)
            try:
                conn = db_connect(cfg)
            except Exception:
                time.sleep(5)
            continue

        if not job:
            if args.once:
                print("[worker] kein Job offen.", flush=True)
                return 0
            time.sleep(max(0.5, args.poll))
            continue

        try:
            process_job(conn, cfg, job)
        except Exception:
            tb = traceback.format_exc()
            print("[worker] unerwarteter Fehler:\n%s" % tb, flush=True)
            try:
                finish_job(conn, int(job["id"]), "error", "", "", None, 0, tb[:800])
            except Exception:
                pass

        if args.once:
            return 0

    return 0


if __name__ == "__main__":
    sys.exit(main())
