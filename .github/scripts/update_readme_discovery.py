from pathlib import Path

path = Path("README.md")
text = path.read_text(encoding="utf-8")
old = """# Project STΛRLIɢHT: Ember CoreUI
[![DOI](https://zenodo.org/badge/1349352973.svg)](https://doi.org/10.5281/zenodo.22201310)

Ember CoreUI is an independent WebUI for a local E.M.B.E.R. core.

Current version: `0.5.2-alpha`
"""
new = """# Project STΛRLIɢHT: Ember CoreUI
[![DOI](https://zenodo.org/badge/1349352973.svg)](https://doi.org/10.5281/zenodo.22201310)

**Ember CoreUI** is an independent local AI WebUI and control environment by **Starlight Unit Studios**, designed for local **Gemma 4 models via Ollama** and the E.M.B.E.R. cognitive architecture.

It combines private conversations and sessions, user-scoped RAG-Lite, local long-term memory, Playwright-based browser research with SearXNG, isolated Python execution, multimodal file handling, deterministic exact integer calculations, and strict separation of internal model analysis from visible answers.

Ember CoreUI runs on its own containerized Nginx, PHP-FPM, MariaDB, SearXNG, browser, and Python-worker stack and remains isolated from STΛRLIɢHT The Game and other Starlight Unit systems.

Current version: `0.5.2-alpha`
"""
if old not in text:
    raise SystemExit("Expected README intro not found; refusing to modify")
text = text.replace(old, new, 1)
path.write_text(text, encoding="utf-8")

check = path.read_text(encoding="utf-8")
required = [
    "designed for local **Gemma 4 models via Ollama**",
    "user-scoped RAG-Lite",
    "Playwright-based browser research with SearXNG",
    "isolated Python execution",
    "deterministic exact integer calculations",
    "Current version: `0.5.2-alpha`",
]
for needle in required:
    if needle not in check:
        raise SystemExit(f"README validation failed: missing {needle}")
print("README discovery intro updated and validated")
