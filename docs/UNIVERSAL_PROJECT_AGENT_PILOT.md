# E.M.B.E.R. CoreUI: Universal Project Agent Pilot

Status: **experimental, opt-in, NOT enabled in production**.

Standard-TXT-Uebergabe: [UEBERGABEPROTOKOLL_UNIVERSAL_PROJECT_AGENT_PILOT.txt](UEBERGABEPROTOKOLL_UNIVERSAL_PROJECT_AGENT_PILOT.txt), inklusive aktuellem produktiven style.css-Befund und offener Testabnahme.
Baseline: CoreUI 0.5.32 (main integrated on 24 October 2026).
This is a staged replacement design for the numerous ZIP/file-type routing exceptions,
not a claim that every generated language or media type is now supported.

## Objective

The selected Ollama model decides the project file layout instead of CoreUI
guessing \`index.html\`, \`style.css\`, and \`main.js\` from words such as
"homepage". The model remains accessible from the **local Ollama model list**,
including Ollama-managed \`*-cloud\` models. No separate Cloud API is used.

Architecture, strictly serial per E.M.B.E.R.:

1. A small, bounded planning response produces JSON with archive, files,
   purpose, and dependencies. This is a textual JSON adapter to the existing
   Ollama chat endpoint, **not yet native Ollama tool calling or enforced
   server-side \`format\` schemas**.
2. CoreUI validates every relative path, extension, unique filename, count,
   dependency, and cycle. It sorts the files in dependency order.
3. CoreUI requests just **one text file per serial model call**. Raw, fenced
   and optionally marked sources use the common completion adapter. Confirmed
   truncation allows at most two continuations per bounded file attempt.
4. Source bytes are held as inert PHP data. There is no generated Python
   file-writing code, shell command, or model-defined executable tool call.
5. Generic source checks plus HTML asset-reference checks must pass. The
   server-owned Python ZIP writer receives only validated compressed/base64
   bytes. It stages files in a temporary sandbox directory (including nested
   folders), verifies ZIP members and CRC, and emits **one ZIP only** into the
   worker output directory.
6. The existing account/session/revision-bound artifact manifest must
   confirm the exact ZIP before a success message or download card appears.

A strictly unique search/replace function is provided as a primitive for a
later Aider-style edit operation. It is not yet an autonomous multi-step
project revision loop.


## 24 October 2026: adapting proven coding-agent file protocols

The productive 0.5.32 screenshot demonstrates that a multi-file homepage ZIP is
now detected, but the first CSS file may be rejected after two model calls.
"Quelle nicht lesbar" alone does **not** prove whether the model reached an
Ollama output limit, returned a normal but unconventional code format, or
produced invalid CSS. The historical JavaScript-only continuation cannot
repair a truncated CSS/Python/HTML source.

Relevant upstream design references:
- OpenCode: its generic read/write/edit/patch tools let the program own the
  file operations rather than asking the LLM to generate file-writing Python.
  https://docs.opencode.ai/docs/tools/
- Aider: whole-file, fenced and search/replace formats are interchangeable
  model adapters; architect mode separates intent from concrete edits.
  https://aider.chat/docs/more/edit-formats.html
- OpenHands: the structured str-replace editor uses explicit file paths and
  one-match edits, with inspect/create/edit operations instead of a separate
  protocol for every project type.
  https://github.com/OpenHands/openhands-aci
- Ollama: the EXISTING local /api/chat endpoint exposes done_reason=stop and
  done_reason=length, including for locally selected Cloud model tags.
  https://github.com/ollama/ollama/blob/main/docs/api.md

Pilot improvement:
- New reusable inert source protocol in
  api/console_universal_source_protocol.php.
- Accept raw, once-fenced or explicitly marked text from **any** supported
  project extension only after a trusted normal Ollama stop or unambiguous
  completion marker and the existing type-aware source check.
- A confirmed length stop retains the source bytes for up to two bounded
  continuation calls without restarting the whole file. This also covers CSS,
  Python, HTML and TypeScript, not just JavaScript.
- Reject tool markers and suspicious extra fences. Do not accept unknown
  unmarked completions or falsely package truncated sources.
- The completion reason is taken from the SAME Ollama call; no new API,
  no changed Gemma Modelfile parameters, and no parallel model calls.
- The existing planner and deterministic ZIP writer remain separate.
- Regression coverage includes raw CSS without a marker, CSS continuation,
  fenced sources, no-result retry, and truncated plans. A focused standalone
  PHP 8.4 test of the source protocol completed successfully. The full repo,
  Python-worker and live-Ollama tests are still pending.

This is an **experimental adapter**, not proof that all generated CSS/JS/Python
is valid. It cannot replace actual language parsers and runtime/browser
verification. No user-facing production release has been published.

## Current limits (intentionally conservative)

- Exactly one explicit ZIP project request per turn.
- Up to 10 **UTF-8 text source files**, each <= 120 KB, total <= 400 KB,
  directory depth <= 4; no duplicate full paths.
- No native image/video/audio/font/binary creation or binary references.
- No external model-generated shell commands, untrusted paths or script
  execution. HTML/CSS/JS/Python/TypeScript etc. are carried as DATA.
- JSON and HTML source structure checked. Other languages may still contain
  syntactically invalid or semantically incorrect code. A final browser,
  build, linter or runtime test remains necessary.
- Streaming/concurrent agent actions, more than 10 files, resumable
  across-turn projects, semantic diff repair, source repository edits and
  binary assets are **future phases**, not features silently promised here.

## Opt-in (STAGING ONLY, after running tests)

The pilot is off unless the private \`api/config.local.php\` explicitly
defines this constant:

    define('STU_EMBER_UNIVERSAL_FILES_ENABLED', true);

Without that switch, 0.5.32 routing and downloads run unchanged.
Opt-in does not edit the approved Gemma Modelfile. Neither \`num_ctx\` nor
\`num_predict\` is added to Gemma requests. Model calls stay strictly
serial through the existing Ollama queue.

## Offline test (not a live inference test)

    php -l api/console_universal_project.php
    php -l api/chat.php
    php -l api/console_stream.php
    php scripts/universal-source-protocol-selftest.php
    php scripts/universal-project-agent-selftest.php
    php scripts/universal-project-writer-selftest.php --emit-payload | python3 scripts/universal-project-writer-selftest.py
    php scripts/local-file-tool-selftest.php


The Docker preflight sends the synthetic writer payload from its PHP service
through a pipe to the existing Python 3 browser service. The PHP image does
not install Python 3; this avoids adding a new server dependency and avoids
a false preflight failure. Native installs pipe from PHP to their existing
Python 3 interpreter. Both scripts use pipefail.
A stand-alone source protocol test (PHP 8.4) and a synthetic Python
ZIP-byte verifier were actually run successfully outside the repository.
The **full** project-agent test suite and real Docker/live Ollama runs remain
pending.

The writer test invokes Python 3 in a disposable temporary directory, reads the
ZIP back, compares every member against the expected original UTF-8 bytes and
checks the archive CRC. Both existing Docker/native preflight scripts now
include this test and the planner regression, even while the pilot flag is off.
These checks have been **added but not executed on the target machine yet**.

The new selftest fakes model replies and validates a browser project and
a completely different Python-CLI project. It also checks dependency
ordering, parent-directory protection, marker truncation and continuation,
relative asset paths, encoded writer data and unique edits.

## Acceptance still required

1. Run the offline tests **outside the productive checkout**.
2. Verify the emitted trusted ZIP writer in the existing isolated Python
   worker and inspect the archive member bytes, CRC and paths.
3. Try the same request with locally executing \`gemma4:e4b\`,
   \`gemma4:26b\`, and locally selected \`gemma4:31b-cloud\`.
4. Confirm exact artifact cards, account isolation, cancellation, and
   *absence* of duplicate assistant responses.
5. Confirm actual application operation in mobile Chrome.
6. Only then consider enabling the pilot on production and retiring the
   old per-structure rules in a separate, reviewed release.

No GitHub Actions are required to create the branch or PR; do not trigger
GitHub Actions while the account's quota is exhausted.


## Full-checkout offline acceptance, 24 October 2026

The pilot has now been synchronized with main 0.5.32. PHP 8.3.6 syntax,
source protocol, project agent, both real ZIP writer tests, the PHP-to-Python
payload pipeline, existing local-file/frontend/Gemma regressions, preflight
shell syntax, branding and cache checks passed on the complete checkout.
These used synthetic model fixtures and temporary host directories, not the
production Docker worker or real Ollama inference. Docker/account-scoped
artifact integration and staging model/browser acceptance remain open.
PR #69 stays draft and the feature stays disabled by default.

Current handover: [UEBERGABEPROTOKOLL_PILOT_OFFLINE_2026_10_24.txt](UEBERGABEPROTOKOLL_PILOT_OFFLINE_2026_10_24.txt).
