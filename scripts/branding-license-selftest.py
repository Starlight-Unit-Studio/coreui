#!/usr/bin/env python3
"""Deterministic release checks for Ember CoreUI branding and licensing."""

from __future__ import annotations

import json
import re
import sys
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
EXPECTED_VERSION = "0.5.1-alpha"
PUBLIC_PAGES = (
    "index.html",
    "login.html",
    "app.html",
    "settings.html",
    "protocols.html",
    "admin/index.html",
)
REQUIRED_FILES = (
    "LICENSE.de.md",
    "LICENSE.md",
    "LICENSE_HISTORY.md",
    "TRADEMARKS.md",
    "COMMUNITY_POLICY.md",
    "NOTICE.md",
    "COPYRIGHT.md",
    "docs/changelogs-txt/CHANGELOG_0_5_1_ALPHA.txt",
    "docs/UEBERGABEPROTOKOLL_0_5_1_ALPHA.txt",
)


def read(relative_path: str) -> str:
    path = ROOT / relative_path
    if not path.is_file() or path.stat().st_size == 0:
        raise AssertionError(f"required file missing or empty: {relative_path}")
    return path.read_text(encoding="utf-8")


def require(condition: bool, message: str) -> None:
    if not condition:
        raise AssertionError(message)


def main() -> int:
    require(read("VERSION").strip() == EXPECTED_VERSION, "VERSION mismatch")

    for relative_path in REQUIRED_FILES:
        read(relative_path)

    german_license = read("LICENSE.de.md")
    policy = read("COMMUNITY_POLICY.md")
    trademarks = read("TRADEMARKS.md")
    require(
        "Starlight Unit Studios Ember CoreUI Community Source Lizenz 1.0"
        in german_license,
        "controlling license name missing",
    )
    require("keine Nutzergrenze" in german_license, "no-user-limit rule missing")
    require("no paid license tier" in policy, "free community policy incomplete")
    require("no license phone-home" in policy, "no-telemetry policy incomplete")
    require("Ember CoreUI" in trademarks, "trademark naming rule missing")

    for relative_path in PUBLIC_PAGES:
        page = read(relative_path)
        require(
            re.search(
                r'<meta\s+name="application-name"\s+content="[^"]*Ember CoreUI[^"]*"',
                page,
                re.IGNORECASE,
            )
            is not None,
            f"Ember CoreUI application-name missing: {relative_path}",
        )

    landing_page = read("index.html")
    structured_match = re.search(
        r'<script\s+type="application/ld\+json">\s*(\{.*?\})\s*</script>',
        landing_page,
        re.DOTALL,
    )
    require(structured_match is not None, "structured software metadata missing")
    structured_data = json.loads(structured_match.group(1))
    require(structured_data.get("@type") == "SoftwareApplication", "structured software type mismatch")
    require(structured_data.get("name") == "Ember CoreUI", "structured product name mismatch")
    require(
        structured_data.get("publisher", {}).get("name") == "Starlight Unit Studios",
        "structured publisher mismatch",
    )
    require(structured_data.get("isAccessibleForFree") is True, "free-access metadata mismatch")

    settings = read("js/settings.js")
    profile_store = read("api/profile_store.php")
    require("EMBER COREUI-DATENBANK" in settings, "database label is not fully branded")
    require("--- EMBER COREUI-PROFIL ---" in profile_store, "profile marker is not fully branded")
    require("--- COREUI-PROFIL ---" not in profile_store, "legacy profile marker remains")

    text_extensions = {
        ".css", ".html", ".js", ".json", ".md", ".php", ".py",
        ".sh", ".sql", ".txt", ".yaml", ".yml",
    }
    for path in ROOT.rglob("*"):
        if path.is_file() and path.suffix.lower() in text_extensions:
            content = path.read_text(encoding="utf-8")
            require("\u2014" not in content, f"Unicode em dash found: {path.relative_to(ROOT)}")

    print("Ember CoreUI branding and license selftest: OK")
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except (AssertionError, UnicodeDecodeError, json.JSONDecodeError) as exc:
        print(f"Ember CoreUI branding and license selftest: FAIL: {exc}", file=sys.stderr)
        raise SystemExit(1)
