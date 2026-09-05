from __future__ import annotations

from pathlib import Path
import os
import shutil
import subprocess

OLD_DOT = "0.5.2-alpha"
OLD_UNDER = "0_5_2_ALPHA"
NEW_DOT = "0.5.3-alpha"
NEW_UNDER = "0_5_3_ALPHA"
ROOT = "EMBER_COREUI_0_5_3_ALPHA"
RELEASE_DIR = Path("releases/v0.5.3-alpha")
ARCHIVE = "EMBER_COREUI_0_5_3_ALPHA.zip"


def run(*args: str, cwd: Path | None = None) -> None:
    print("+", " ".join(args))
    subprocess.run(args, cwd=cwd, check=True)


def tracked_files() -> list[str]:
    out = subprocess.check_output(["git", "ls-files", "-z"])
    return [x for x in out.decode().split("\0") if x]


# Update active version references, but preserve historical release documentation.
changed: list[str] = []
for raw in tracked_files():
    if raw == "CHANGELOG.md" or raw.startswith(("docs/", "releases/", ".github/")):
        continue
    path = Path(raw)
    try:
        text = path.read_text(encoding="utf-8")
    except (UnicodeDecodeError, OSError):
        continue
    updated = text.replace(OLD_DOT, NEW_DOT).replace(OLD_UNDER, NEW_UNDER)
    if updated != text:
        path.write_text(updated, encoding="utf-8")
        changed.append(raw)

Path("VERSION").write_text(NEW_DOT + "\n", encoding="utf-8")
if "VERSION" not in changed:
    changed.append("VERSION")

# README: keep the cumulative 0.5.2 calculation fixes, but describe the new focus first.
readme = Path("README.md")
text = readme.read_text(encoding="utf-8")
old_intro = "`0.5.3-alpha` is a focused completion hotfix for exact integer calculations in the private Core Channel.\n\n"
new_intro = (
    "`0.5.3-alpha` aligns the production Gemma 4 + Ollama request path with the minimal known-good inference profile established during extended live CoreUI validation.\n\n"
    "- Normal generation sends only `temperature=1.0`, `top_p=0.95`, and `top_k=64` inside Ollama `options` by default.\n"
    "- `num_ctx`, `num_predict`, thread, repeat, seed, and stop controls are no longer injected into normal generation automatically.\n"
    "- Synchronous and SSE generation share the same option-policy helper so the two paths cannot silently drift.\n"
    "- Vision and truncated-continuation paths no longer force the old context or predict budgets.\n"
    "- Legacy backend-specific tuning remains available only behind the explicit experimental `STU_EMBER_UNVERIFIED_TUNING_ENABLED` gate plus explicit configuration.\n"
    "- There is no new database migration. Existing accounts, sessions, messages, revisions, uploads, RAG data, and local configuration remain unchanged.\n\n"
    "The deterministic calculation fixes from `0.5.2-alpha` remain included:\n\n"
)
if old_intro not in text:
    raise SystemExit("README release-result marker not found after version update")
readme.write_text(text.replace(old_intro, new_intro, 1), encoding="utf-8")

# Main changelog.
changelog = Path("CHANGELOG.md")
text = changelog.read_text(encoding="utf-8")
marker = "Ember CoreUI wird bis zur finalen Aufnahme in den STU-Repack eigenstaendig versioniert.\n\n"
section = """## v0.5.3-alpha - 05.09.2026

- Produktionspfad fuer Gemma 4 + Ollama auf das waehrend der mehrstuendigen CoreUI-Validierung ermittelte Minimalprofil ausgerichtet.
- Normale Ollama-Aufrufe senden standardmaessig nur `temperature=1.0`, `top_p=0.95` und `top_k=64` innerhalb von `options`.
- `num_ctx`, `num_predict`, Thread-, Repeat-, Seed- und Stop-Parameter werden im normalen Generierungspfad nicht mehr automatisch injiziert.
- Synchroner Antwortpfad und privater SSE-Pfad verwenden dieselbe gemeinsame Known-Good-Options-Policy und koennen dadurch nicht mehr still auseinanderlaufen.
- Vision-Aufrufe und Fortsetzungen abgeschnittener Antworten erzwingen keine alten Kontext- oder Predict-Budgets mehr.
- Historische beziehungsweise backend-spezifische Tuningparameter bleiben fuer gezielte A/B-Tests hinter `STU_EMBER_UNVERIFIED_TUNING_ENABLED` und expliziter Konfiguration verfuegbar.
- Die Aenderung behandelt die beobachtete Performanceverbesserung bewusst als Ergebnis des gesamten Minimalprofils und schreibt sie nicht allein `num_ctx` zu.
- Keine neue Datenbankmigration. Migration 009 bleibt der aktuelle Schema-Stand; Konten, Sitzungen, Nachrichten, Reaktionen, Revisionen, Anhaenge, Uploads, private RAG-Daten und lokale Konfiguration bleiben erhalten.
- Version, Cache-Buster, Setup, README, Changelog-Archiv, Preflight-relevante Versionsreferenzen, Uebergabeprotokoll, Release-ZIP und SHA-256 auf `0.5.3-alpha` aktualisiert.

"""
if marker not in text:
    raise SystemExit("CHANGELOG insertion marker not found")
if "## v0.5.3-alpha" not in text:
    text = text.replace(marker, marker + section, 1)
changelog.write_text(text, encoding="utf-8")

# Standalone changelog archive.
Path("docs/changelogs-txt/CHANGELOG_0_5_3_ALPHA.txt").write_text(
    """STΛRLIɢHT: EMBER COREUI BY STARLIGHT UNIT STUDIOS
CHANGELOG 0.5.3-ALPHA
STAND 05.09.2026

======================================================================
MINIMAL KNOWN-GOOD INFERENCE PROFILE
======================================================================

Die mehrstuendige reale Validierung ueber Ember CoreUI hat gezeigt, dass feste
Backendparameter die Gemma-4/Ollama-Leistung deutlich verschlechtern koennen.
Der sichere Produktionsstandard fuer den aktuell validierten Pfad ist deshalb
bewusst minimal.

Standardmaessig werden nur diese Ollama-Optionen gesendet:

* temperature = 1.0
* top_p = 0.95
* top_k = 64

Nicht mehr automatisch gesendet werden insbesondere:

* num_ctx
* num_predict
* num_thread
* repeat_penalty / repeat_last_n
* seed
* stop

Synchroner und SSE-Pfad verwenden dieselbe Options-Policy. Vision-Aufrufe und
Fortsetzungen abgeschnittener Antworten erzwingen ebenfalls keine historischen
Kontext- oder Predict-Budgets mehr.

Historische beziehungsweise backend-spezifische Tuningparameter bleiben nur
fuer gezielte Tests hinter STU_EMBER_UNVERIFIED_TUNING_ENABLED und expliziter
Konfiguration verfuegbar. Ein einzelner entfernter Parameter wird nicht als
alleinige Ursache der beobachteten Beschleunigung festgeschrieben.

Es gibt keine neue Datenbankmigration. Migration 009 bleibt unveraendert.
Alle Korrekturen aus 0.5.2-alpha und den vorherigen Releases bleiben enthalten.
""",
    encoding="utf-8",
)

Path("docs/UEBERGABEPROTOKOLL_0_5_3_ALPHA.txt").write_text(
    """STΛRLIɢHT: EMBER COREUI BY STARLIGHT UNIT STUDIOS
UEBERGABEPROTOKOLL 0.5.3-ALPHA
STAND 05.09.2026

======================================================================
1. VERBINDLICHER QUELLSTAND
======================================================================

Repository:
https://github.com/Starlight-Unit-Studio/coreui

Arbeitsbranch:
release/0.5.3-alpha

Ziel-Branch:
main

Produktversion:
0.5.3-alpha

Paketwurzel:
EMBER_COREUI_0_5_3_ALPHA

Releasepaar:
EMBER_COREUI_0_5_3_ALPHA.zip
EMBER_COREUI_0_5_3_ALPHA.zip.sha256

Produktive Adresse:
https://coreui.starlight-unit.de

======================================================================
2. RELEASEZIEL
======================================================================

0.5.3-alpha uebernimmt die waehrend der ausgedehnten realen CoreUI-Tests
erarbeitete Minimal-Konfiguration fuer Gemma 4 + Ollama in den offiziellen
Produktionspfad. Der Client soll keine unvalidierten Backendparameter mehr als
vermeintlich hilfreiche Defaults festschreiben.

Der normale Pfad sendet nur temperature=1.0, top_p=0.95 und top_k=64.
Kontext-, Predict-, Thread-, Repeat-, Seed- und Stop-Optionen bleiben
backendverwaltet, solange sie nicht fuer die konkrete Backend-/Modell-/Versions-
und Hardwarekombination isoliert validiert wurden.

Synchroner Aufruf und SSE verwenden dieselbe Policy. Vision und automatische
Fortsetzung erhalten keine gesonderten alten Predict-/Context-Budgets mehr.

======================================================================
3. KOMPATIBILITAET UND ROLLBACK
======================================================================

Die bisherigen Tuningfunktionen bleiben im Quellcode fuer gezielte A/B-Tests
erhalten. Sie werden nur aktiv, wenn STU_EMBER_UNVERIFIED_TUNING_ENABLED
explizit gesetzt und die jeweilige Option ebenfalls konfiguriert wurde.

Es gibt keine Datenbankmigration. Migration 009 bleibt der aktuelle
Schema-Stand. Konten, Sitzungen, Nachrichten, Revisionen, Feedback, Anhaenge,
Uploads, private RAG-Daten und api/config.local.php werden beim Update nicht
zurueckgesetzt.

Ein Rollback auf das vorherige 0.5.2-alpha-Release ist dadurch ohne
Schema-Rueckmigration moeglich.

======================================================================
4. RELEASEPRUEFUNG
======================================================================

Vor der Freigabe werden mindestens geprueft:

* PHP-Syntax von api/chat.php, api/console_stream.php und config-Beispiel
* Bash-Syntax von Setup und Shell-Skripten
* Branding-/Lizenz-Selftest
* git diff --check
* ZIP-Integritaet und Paketwurzel
* VERSION im entpackten Paket
* SHA-256-Pruefung
* Ausschluss historisch gesperrter privater Studio-Dokumente
* konsistente Versionsreferenzen ausserhalb historischer Changelogs/Releases

======================================================================
5. ABGRENZUNG
======================================================================

0.5.3-alpha fuehrt keine neue Runtime-Architektur ein und ersetzt Ollama noch
nicht. Die Client-Aenderung bereitet vielmehr die spaetere Uebergabe von
Backend-, Hardware- und Capability-Entscheidungen an Quantum Runtime vor.

Game, Homepage, Repack, KeyHelp, Host-PHP und externe Datenbanken sind nicht
Bestandteil dieses CoreUI-Releases.
""",
    encoding="utf-8",
)

# Changelog index.
index = Path("docs/changelogs-txt/INDEX.txt")
text = index.read_text(encoding="utf-8")
if "* CHANGELOG_0_5_3_ALPHA.txt" not in text:
    text = text.replace(
        "* CHANGELOG_0_5_2_ALPHA.txt\n",
        "* CHANGELOG_0_5_2_ALPHA.txt\n* CHANGELOG_0_5_3_ALPHA.txt\n",
    )
text = text.replace(
    "Quelle: CHANGELOG.md aus Ember CoreUI 0.5.2-alpha",
    "Quelle: CHANGELOG.md aus Ember CoreUI 0.5.3-alpha",
)
index.write_text(text, encoding="utf-8")

print("Updated active version references in:")
for item in sorted(changed):
    print(" -", item)

# Stage new documentation so it is included by git ls-files while packaging.
run("git", "add", "-A")

# Fail closed on active stale release references outside historical material.
proc = subprocess.run(
    [
        "git", "grep", "-n", "-E", "0\\.5\\.2-alpha|0_5_2_ALPHA", "--",
        ":!CHANGELOG.md", ":!docs/**", ":!releases/**", ":!.github/**",
    ],
    text=True,
    capture_output=True,
)
if proc.returncode == 0:
    print(proc.stdout)
    raise SystemExit("Unexpected active 0.5.2 version reference remains")
if proc.returncode not in (0, 1):
    print(proc.stderr)
    raise SystemExit(proc.returncode)

# Syntax and source checks.
run("php", "-l", "api/chat.php")
run("php", "-l", "api/console_stream.php")
run("php", "-l", "api/config.local.php.example")
run("bash", "-n", "setup.sh")
for shell_file in sorted(Path("scripts").glob("*.sh")):
    run("bash", "-n", str(shell_file))
run("git", "diff", "--check")

# Build a release with one package root. Historical release binaries and temp CI are excluded.
stage = Path(os.environ.get("RUNNER_TEMP", "/tmp")) / "coreui-release-stage"
if stage.exists():
    shutil.rmtree(stage)
package_root = stage / ROOT
package_root.mkdir(parents=True)

for raw in tracked_files():
    if raw.startswith(("releases/", ".github/")):
        continue
    src = Path(raw)
    if not src.is_file():
        continue
    dst = package_root / raw
    dst.parent.mkdir(parents=True, exist_ok=True)
    shutil.copy2(src, dst)

RELEASE_DIR.mkdir(parents=True, exist_ok=True)
archive_path = RELEASE_DIR / ARCHIVE
checksum_path = RELEASE_DIR / (ARCHIVE + ".sha256")
archive_path.unlink(missing_ok=True)
checksum_path.unlink(missing_ok=True)
run("zip", "-q", "-r", str(archive_path.resolve()), ROOT, cwd=stage)

sha = subprocess.check_output(["sha256sum", ARCHIVE], cwd=RELEASE_DIR, text=True)
checksum_path.write_text(sha, encoding="utf-8")
run("sha256sum", "-c", checksum_path.name, cwd=RELEASE_DIR)
run("unzip", "-tq", str(archive_path))

version_inside = subprocess.check_output(
    ["unzip", "-p", str(archive_path), f"{ROOT}/VERSION"], text=True
).strip()
if version_inside != NEW_DOT:
    raise SystemExit(f"Archive VERSION mismatch: {version_inside!r}")

for required in (
    "VERSION",
    "README.md",
    "compose.yaml",
    "scripts/install.sh",
    "scripts/stack.sh",
    "scripts/preflight.sh",
):
    listing = subprocess.check_output(["unzip", "-Z1", str(archive_path)], text=True)
    if f"{ROOT}/{required}" not in listing.splitlines():
        raise SystemExit(f"Required release file missing: {required}")

listing = subprocess.check_output(["unzip", "-Z1", str(archive_path)], text=True).lower()
if "master bibel" in listing or "kompendium_v6" in listing or "kompendium v6" in listing:
    raise SystemExit("Blocked private studio material detected in release archive")

run("python3", "scripts/branding-license-selftest.py")
run("git", "diff", "--check")

print("Release archive:", archive_path)
print(checksum_path.read_text(encoding="utf-8").strip())
