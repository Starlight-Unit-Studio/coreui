# Project STΛRLIɢHT: Ember CoreUI

Ember CoreUI is an independent WebUI for a local E.M.B.E.R. core. Until its final inclusion in the STU Repack, it has its own versioning and its own release cycle.

Current version: `0.4.2-alpha`

## Independence and mandatory product name

The product name is always **Ember CoreUI**. The full designation is **STΛRLIɢHT: Ember CoreUI by Starlight Unit Studios**. The standalone designation `CoreUI` is not used as a product name for this project.

Ember CoreUI is independently developed. The project is not a fork of the separate admin template and UI component project at `coreui.io`, contains no dependency on it, and is neither affiliated with, sponsored by, nor endorsed by it. Technical compatibility names such as `coreui`, `COREUI_*`, `ember-coreui`, `/opt/ember-coreui`, repository components, and domain components remain purely machine identifiers.

## License and free community model

Starting with `0.4.2-alpha`, the project's own code is licensed under the **Starlight Unit Studios Ember CoreUI Community Source License 1.0**. The legally authoritative German version is located in `LICENSE.de.md`; `LICENSE.md` is the English translation.

- private, community, and internal use remains free
- internal use by companies remains free with visible Ember CoreUI branding
- no user limit and no license telemetry
- no paid licensing, white-label, donation, advertising, or support program for the official Ember CoreUI project
- Ember CoreUI may not be sold by third parties as a paid SaaS or managed service
- branding changes always require prior written case-by-case approval
- modified redistributions must retain source code, license, attribution notices, and branding

Further details are provided in `LICENSE_HISTORY.md`, `TRADEMARKS.md`, `COMMUNITY_POLICY.md`, `NOTICE.md`, and `COPYRIGHT.md`. The license is Source Available and is not OSI-certified. Third-party components retain their own terms.

## Result of this release

`0.4.2-alpha` is a licensing, provenance, and branding-only release. It introduces no new database migration and no monetization functionality. Product text consistently uses `Ember CoreUI`; the interface, documentation, and metadata identify Starlight Unit Studios as the independent publisher.

The full functional state from `0.4.1-alpha` remains included: secure password changes, revocable login sessions, private JSON account export, and model selection validated against the local Ollama instance.

Directly in the settings, TXT, Markdown, text-based PDF, and DOCX files can be uploaded, extracted, and chunked as private RAG-Lite knowledge. Sources, original files, and chunks are always bound to the authenticated user and remain separate from the globally curated studio canon. The Admin Core can create new users with an initial password, role, dedicated operator, and dedicated profile.

The message composer accepts up to ten files per turn. The ordered list persists after reloading, is bound to the authenticated user and exactly the associated message, and is cleaned up in a reference-safe manner when a session is permanently deleted. Text attachments share a limited context budget; image, video, and scanned-PDF inputs additionally share a common vision limit.

The real session scopes, turn assignments, lossless legacy histories, and controlled permanent session deletion from `0.3.1-alpha` and `0.3.2-alpha` remain included without changes. Game, homepage, Apache, KeyHelp, host PHP, Repack paths, and the existing STU database remain untouched.

## Real sessions instead of demo pointers

Before `0.3.1-alpha`, a session consisted only of a title and `since_id`. All private messages were still stored in a shared user stream. This caused incorrect excerpts, jumping order, and missing latest messages. The new path fixes this structurally:

- `stu_chat_messages.session_id` permanently separates conversations in the database.
- `reply_to_id` assigns each Ember response to the exact user turn.
- `console_messages.php` returns only messages from a verified session owned by the user.
- The client remembers the active session ID instead of an unstable list index.
- Asynchronous history, poll, SSE, and browser callbacks verify their session ID before changing the visible interface.
- Read markers no longer change `updated_at`; only genuinely new messages move a session to the top.
- The newest messages are loaded directly. Older pages can be loaded without calculation windows or `since_id` estimation.
- Sessions are no longer deleted automatically. Archiving is a reversible metadata operation; the message history remains preserved.
- A dedicated archive view allows archived sessions to be restored. Temporary loading errors no longer displace already confirmed messages from the view.
- Permanent deletion is available only in the archive view and requires two deliberate confirmations.
- The API endpoint accepts deletion only for a session owned by the user that is already archived and verifies a session-specific confirmation again on the server side.
- An SQL transaction removes reactions, browser frames, browser steps, completed browser jobs, messages, orphaned upload records, and finally the session row. An error rolls back the entire database operation.
- Sessions with a browser job in the `queued` or `running` state are not deleted. This prevents an active research task from being removed underneath the worker.
- Physical upload files and the final screenshot of removed browser jobs are deleted only after a successful database commit and only from `var/console_media`, `assets/chat_media`, or `uploads/ember_browse`. Files with additional message references remain preserved.
- Migration `003_console_sessions` collects legacy messages that cannot be separated unambiguously into a recovered history without data loss.
- The dedicated session self-test checks schema, history scope, turn assignment, client races, browser worker propagation, and the complete transactional deletion cascade including rollback.

## Secure thinking status and cleanup of old sessions

Ember CoreUI strictly separates transient internal model analysis from the visible interface:

- During generation, SSE returns only fixed status stages such as `KONTEXT WIRD ABGEGLICHEN` and `ANTWORT WIRD FORMULIERT`.
- `message.content` and `message.thinking` are collected in full and processed only after the shared safety check.
- English and German prompt, analysis, draft, and self-correction patterns are intercepted before SSE, database, and history.
- New `thinking_content` values contain only the safe final status when Thinking is enabled; when Thinking is disabled, they remain empty.
- The fetch endpoint replaces old raw values before JSON output using fail-closed behavior.
- The browser has an additional whitelist and does not write arbitrary Thinking text into the DOM.
- The response budget is passed to Ollama per user. A detected token limit continues from the existing response instead of restarting the entire generation.
- Private Console messages may contain up to 12000 characters, or up to 20000 characters with attachments. Line breaks and code indentation are preserved.
- The SSE call passes only the authenticated turn ID. The message text is loaded from the Ember CoreUI database and does not appear in the EventSource URL or in proxy access logs.
- The message column is expanded to `MEDIUMTEXT` by migration `002_coreui_management`.

After the update, existing raw blocks can be replaced once in the isolated Ember CoreUI database:

```bash
cd /opt/ember-coreui
sudo ./scripts/stack.sh scrub-thinking
```

The command changes neither the Game database nor the Global Chat.

Only Ollama is shared as an already existing local model interface. Ember CoreUI creates the separate model `ember-coreui:latest` for this purpose. An existing STU model such as `gemma4:26b` is neither replaced nor reconfigured.

## Profiles, AI settings, and private knowledge

`settings.html` connects local display options with a real server-side user and AI profile:

- custom visible username without changing the login email or internal user ID
- private user profile image as a server-side re-rendered PNG file
- custom visible CoreAI name and separate CoreAI profile image per account
- custom system prompt for style and working method within the unchanged Ember CoreUI safety and identity rules
- Memory on or off and configurable Memory limit
- model Thinking on or off per account; raw thought processes remain private in both settings
- response budget from 256 to 16384 Ollama tokens
- temperature from 0.1 to 1.5
- optional local Ollama model tag that is actually used in the chat path
- create and delete custom visible Memory facts
- upload private RAG-Lite sources directly, use them chunk by chunk, and delete them completely
- account, permission level, health matrix, model, and version
- local reference operation with Gemma 4 and Ollama

Profile images are not served as freely accessible static files. The private media endpoint verifies the session and always serves only the current avatar of the signed-in account. JPEG and PNG uploads are decoded, center-cropped to a square, limited to a maximum of 512 pixels, and saved as a new PNG file without third-party metadata.

RAG-Lite accepts `.txt`, `.md`, `.pdf`, and `.docx`. For this settings import, PDFs require a real text layer; scanned PDFs without readable text are explicitly rejected. By default, limits are 20 MiB per file, 40 sources, and a total of 5,000,000 extracted characters per account. Account-specific MariaDB locks prevent parallel uploads from bypassing these quotas together. Document contents are explicitly marked in the prompt as untrusted data and never as system instructions.

The prepared external provider adapter remains disabled by default. API keys are never stored in the browser. Only a later server-side tested implementation can enable it; there is no compatibility guarantee for arbitrary cloud providers.

## Admin Core

`admin/index.html` is accessible only to permission levels 0 and 1 and operates exclusively against the Ember CoreUI database. It includes:

- overview for accounts, sessions, messages, Memories, Lore, browse jobs, and logs
- create new user accounts with display name, login email, initial password, and permitted role
- user permissions, time-limited suspensions, and suspension reasons
- Memory CRUD for global, user, and character visibility
- Lore sources and chunk search
- registration, maintenance mode, and maintenance message
- central switches for fast web search, browser research, and isolated Python
- prepared external provider metadata without browser keys
- error log and immutable admin audit

Permission changes and active suspensions are read from the database again on every authenticated request. They therefore also apply to sessions that are already signed in.

The display options are stored under `emberCoreUI.preferences.v1` in the local browser. They change neither the database schema nor accounts, sessions, memories, or model configuration.

## Safe runtime update

The update workflow preserves `var/compose.env`, databases, uploads, and caches. At the same time, the non-secret Nginx and PHP-FPM runtime files are regenerated from the current package templates before every relevant stack start:

```bash
sudo ./scripts/stack.sh refresh-runtime
sudo ./scripts/stack.sh restart web
```

With `sudo ./scripts/stack.sh up -d --force-recreate php web`, the runtime refresh and pending additive database migrations are performed automatically. The runtime values for bind address and ports continue to come from the locally protected `var/compose.env`; credentials are neither written into templates nor output.

## Isolation boundaries

| Area | Ember CoreUI parallel operation |
|---|---|
| Project files | Exclusively under `/opt/ember-coreui` |
| Web server | Dedicated Nginx container on `127.0.0.1:9080` |
| PHP | Dedicated PHP-FPM container on `127.0.0.1:19073` |
| Database | Dedicated MariaDB container on `127.0.0.1:13306` |
| Web search | Dedicated SearXNG container on `127.0.0.1:18889` |
| Browser research | Dedicated Playwright container |
| Live browser images | Private Ember CoreUI database, authenticated job SSE, automatic cleanup |
| Model | Dedicated Ollama model `ember-coreui:latest` |
| Login sessions | Dedicated cookie name `EMBERCOREUISESSID` |
| Chat sessions | Dedicated `session_id`, exact history, and turn assignment in the Ember CoreUI database |
| Profiles | Account-specific names and private media metadata in the Ember CoreUI database |
| Profile images | Authenticated delivery from `var/profile_media`, no direct web path |
| Private RAG-Lite | User-bound sources and chunks in migration 004, originals under `var/knowledge_uploads` |
| Locks | Dedicated namespace `ember_coreui:*` |
| Runtime data | Local directories `var/`, `logs/`, `uploads/`, and `assets/chat_media/` |
| PDF page images | Dedicated short-lived runtime path `var/pdf_pages` |

On Linux, the containers use the host network so that they can reach Ollama at `127.0.0.1:11434` without rebinding the existing Ollama service to `0.0.0.0`. All Ember CoreUI services receive dedicated loopback ports that are checked before installation.

Shared Ollama usage still means shared CPU, RAM, and GPU resources. This is not data or configuration coupling, but simultaneous generations can affect the response time of both interfaces.

## Let Game and Ember CoreUI respond simultaneously

Game and Ember CoreUI already do not block each other at the application level:

- The Game uses its existing STU database and the lock `ember_global_ollama`.
- Ember CoreUI uses its own MariaDB instance and, by default, the lock `ember_coreui:global_ollama`.
- Chat histories, sessions, uploads, Memories, and RAG data remain separate.
- Only the local Ollama service at `127.0.0.1:11434` is shared.

For two different model names to be loaded simultaneously, Ollama needs enough RAM or VRAM. First run the read-only report:

```bash
cd /opt/ember-coreui
sudo ./scripts/ollama-parallel-report.sh
```

If both models fit together in the available RAM or VRAM, Ollama can be limited to two loaded models using a systemd drop-in:

```bash
sudo install -d -m 0755 /etc/systemd/system/ollama.service.d
printf '%s\n' \
  '[Service]' \
  'Environment="OLLAMA_MAX_LOADED_MODELS=2"' \
  'Environment="OLLAMA_NUM_PARALLEL=1"' \
  | sudo tee /etc/systemd/system/ollama.service.d/20-stu-parallel.conf >/dev/null
sudo systemctl daemon-reload
sudo systemctl restart ollama
ollama ps
```

Without a custom limit, Ollama already allows at least three loaded models by default according to the official documentation. The drop-in is therefore needed only if the report shows a different existing limit or if the upper limit is intentionally fixed at exactly two. `OLLAMA_NUM_PARALLEL=1` applies per loaded model and avoids additional context copies. If Game and Ember CoreUI are later intended to use the same model name, `OLLAMA_NUM_PARALLEL=2` may be necessary. However, this multiplies the memory requirement of the context and should be set only after a RAM/VRAM test. If there is not enough memory, Ollama queues requests instead of forcing true concurrency.

The Ember CoreUI installer deliberately does not write this system-wide Ollama configuration itself. The Ollama service is the only shared resource and remains under the control of the server operator.

Official reference: <https://docs.ollama.com/faq#how-does-ollama-handle-concurrent-requests>

Ember CoreUI will later replace the old STU Console, not the Global Chat in the Game. Removal of the old Console therefore belongs in a separate Repack update after Ember CoreUI has been tested stably in everyday use. This standalone package deletes no Repack files and changes no Game routes.

## Supported target systems

- Debian 12 or newer
- Ubuntu Server 24.04 or newer
- Root or sudo access via SSH
- Docker or permission to install Docker automatically
- Local Ollama or permission to install Ollama automatically
- Enough RAM and storage for the selected Gemma 4 tag
- Outbound Internet access during the first image build

Ollama's Linux guide: <https://docs.ollama.com/linux>

Gemma 4 model page: <https://ollama.com/library/gemma4>

SearXNG Docker documentation: <https://docs.searxng.org/admin/installation-docker.html>

## Safe parallel installation via SSH

Move the archive permanently to `/opt/ember-coreui`:

```bash
cd /tmp
sudo apt-get update
sudo apt-get install -y ca-certificates curl unzip
export COREUI_ARCHIVE_URL='https://DEIN-SERVER/EMBER_COREUI_0_4_2_ALPHA.zip'
curl -fL "$COREUI_ARCHIVE_URL" -o EMBER_COREUI_0_4_2_ALPHA.zip
curl -fL "$COREUI_ARCHIVE_URL.sha256" -o EMBER_COREUI_0_4_2_ALPHA.zip.sha256
sha256sum -c EMBER_COREUI_0_4_2_ALPHA.zip.sha256
unzip -q EMBER_COREUI_0_4_2_ALPHA.zip
sudo mv EMBER_COREUI_0_4_2_ALPHA /opt/ember-coreui
cd /opt/ember-coreui
sudo chmod 0750 scripts/*.sh
sudo ./scripts/install.sh
```

The installer asks for:

- Admin email
- Admin password with at least 12 characters
- optional display name via `COREUI_ADMIN_NAME`

Afterwards, only the dedicated Compose stack, dedicated database, dedicated Ember CoreUI model, and local project files are set up.

## Update to 0.4.2-alpha

The existing database, uploads, sessions, and credentials are preserved. The new package is placed only over the static project files; runtime directories are deliberately excluded.

```bash
cd /home/users/game/tmp
sha256sum -c EMBER_COREUI_0_4_2_ALPHA.zip.sha256
unzip -q -o EMBER_COREUI_0_4_2_ALPHA.zip
sudo apt-get update
sudo apt-get install -y rsync

sudo rsync -a \
  --exclude='api/config.local.php' \
  --exclude='var/' \
  --exclude='logs/' \
  --exclude='uploads/' \
  --exclude='assets/chat_media/' \
  --exclude='assets/profile_photos/' \
  EMBER_COREUI_0_4_2_ALPHA/ /opt/ember-coreui/

cd /opt/ember-coreui
sudo ./scripts/stack.sh up -d --force-recreate php web
sudo ./scripts/preflight.sh
```

`stack.sh up` refreshes the static runtime configuration, creates missing private storage directories with the appropriate container permissions, and automatically applies all pending migrations. Migration `006_account_security` adds password and login timestamps as well as revocable, hashed SQL authentication tokens. Existing PHP logins are transparently transferred to the new session layer on the first authenticated request. All changes remain in the isolated Ember CoreUI database; users, chat sessions, messages, Memories, Lore, and uploads are preserved.

Regenerating the model, importing Lore again, or running an account bootstrap is not required. The existing `api/config.local.php` and the production address `https://coreui.starlight-unit.de` are preserved. For very old versions before `0.2.3-alpha`, the PHP image must be rebuilt once with `sudo ./scripts/stack.sh build --no-cache php` so that Poppler, GD, ZIP, and the required PHP extensions are available.

After the update, login, password change, display of the current device login, a second browser login, revocation of that login, JSON account export, and the Ollama model list should be tested. Afterwards, continue by testing the Thinking switch in both positions, a message with ten small test files, session reload, RAG-Lite, and permanent session deletion.

## Testing without affecting existing domains

By default, Ember CoreUI listens only on `127.0.0.1:9080`. An SSH tunnel is opened from the local computer:

```bash
ssh -L 9080:127.0.0.1:9080 DEIN_SSH_USER@DEIN_SERVER
```

Then open in the local browser:

```text
http://127.0.0.1:9080/
```

This requires no new host Nginx virtual host, no port forwarding, and no change to an existing homepage.

## Public address

The current production address is:

```text
https://coreui.starlight-unit.de
```

The reverse proxy continues to forward this domain internally to `http://127.0.0.1:9080`. In the preserved file `/opt/ember-coreui/api/config.local.php`, exactly one definition must be active for this:

```php
define('STU_PUBLIC_BASE_URL', 'https://coreui.starlight-unit.de');
```

The previous address `webui.starlight-unit.de` is no longer a current Ember CoreUI base address. Because `config.local.php` is deliberately excluded from updates, the release does not automatically change this local operator configuration.

## What the standard installer does not do

- It does not remove any file from `/etc/nginx/sites-enabled/`.
- It does not write any host Nginx or Apache configuration.
- It does not write any global PHP configuration and does not restart an existing PHP-FPM.
- It does not create tables in the existing STU database.
- It does not change any existing MariaDB user.
- It does not install a systemd service for the browse worker.
- It does not write to `/home/users/game`, `/var/www`, or a Repack path.
- It does not replace any existing STU Ollama model.

If Docker is missing and `COREUI_INSTALL_DOCKER=1` is active, the installer installs and starts Docker. If Ollama is missing and `COREUI_INSTALL_OLLAMA=1` is active, it installs Ollama. Existing reachable services are not reconfigured.

## Installation process

1. Validation of the operating system, project path, and inputs.
2. Collision check for the dedicated ports and Compose project name.
3. Creation of dedicated runtime directories and credentials.
4. Validation and build of the PHP and browse images.
5. Detection or download of a Gemma 4 base model.
6. Creation of the separate model `ember-coreui:latest`.
7. Start of the dedicated MariaDB and idempotent import of all SQL migrations.
8. Creation of Ember, Admin, character, and `stu_kv`.
9. Import of the two included Lore documents into RAG-Lite.
10. Start of SearXNG, PHP-FPM, Nginx, and browse worker.
11. Final health check.

## Non-interactive installation

The password should not appear as plain text in the shell history:

```bash
cd /opt/ember-coreui
export COREUI_ADMIN_EMAIL='admin@example.org'
read -r -s -p 'Ember CoreUI Admin-Passwort: ' COREUI_ADMIN_PASSWORD
printf '\n'
export COREUI_ADMIN_PASSWORD
export COREUI_ADMIN_NAME='Operator'
sudo --preserve-env=COREUI_ADMIN_EMAIL,COREUI_ADMIN_PASSWORD,COREUI_ADMIN_NAME ./scripts/install.sh
unset COREUI_ADMIN_PASSWORD
```

## Important options

| Variable | Default | Meaning |
|---|---:|---|
| `COREUI_HTTP_PORT` | `9080` | Dedicated web port |
| `COREUI_PHP_PORT` | `19073` | Dedicated PHP-FPM port |
| `COREUI_DB_PORT` | `13306` | Dedicated MariaDB port |
| `COREUI_SEARXNG_PORT` | `18889` | Dedicated SearXNG port |
| `COREUI_BIND_ADDRESS` | `127.0.0.1` | Secure web bind address |
| `COREUI_BASE_MODEL` | existing `gemma4:*`, otherwise `gemma4:e4b` | Ollama base model |
| `COREUI_MODEL_NAME` | `ember-coreui:latest` | Separate Ember CoreUI model name |
| `COREUI_LOCK_NAMESPACE` | `ember_coreui` | Dedicated MariaDB lock namespace |
| `COREUI_VIDEO_FRAMES` | `6` | Evenly distributed frames per video, maximum 32 |
| `COREUI_VIDEO_FRAME_PX` | `512` | Maximum width and height of a vision frame |
| `COREUI_PDF_MAX_PAGES` | `4` | Representative pages per scanned PDF, maximum 12 |
| `COREUI_PDF_PAGE_PX` | `1024` | Maximum width and height of a rasterized PDF page |
| `COREUI_INSTALL_DOCKER` | `1` | Installs Docker if required |
| `COREUI_INSTALL_OLLAMA` | `1` | Installs Ollama if required |
| `COREUI_INSTALL_SEARXNG` | `1` | Starts the dedicated SearXNG instance |
| `COREUI_INSTALL_BROWSE` | `1` | Starts the dedicated browse worker |
| `COREUI_SKIP_LORE` | `0` | Skips the Lore import |
| `COREUI_SKIP_BOOTSTRAP` | `0` | Skips Admin and Ember bootstrap |
| `COREUI_FORCE_CONFIG` | `0` | Regenerates `api/config.local.php` |

Any port collision causes the process to abort before the Ember CoreUI containers are started. Existing third-party containers with the Compose project name `ember-coreui` are likewise not adopted.

## PDF detection

The document path operates in two stages:

1. `pdftotext` reads PDFs with a real text layer directly and limits the prompt excerpt like other text attachments.
2. If the text layer remains empty, `pdftoppm` renders up to four evenly distributed pages as JPEG. These pages are sent to Gemma's vision path in the correct order.

With a long scanned PDF, Ember therefore sees a sample, not automatically every page. The response must openly state this scope. For a different limit, the constants `STU_EMBER_PDF_MAX_PAGES` and `STU_EMBER_PDF_PAGE_PX` can be set in `api/config.local.php`. More pages and higher resolution significantly increase vision runtime, context, and memory requirements.

If Poppler is missing, the runtime path is not writable, or no page can be rendered, model generation for the document content is stopped. This prevents an invented PDF summary.

## Browser research and Ollama runtimes

The browse worker clearly separates browser navigation from model decisions. SearXNG search, `goto`, clicks, and page text capture run through Playwright. Only afterwards does Ollama decide on the next step. An Ollama read timeout is therefore not a Playwright error.

In the chat, the current browser viewport replaces the previous text log. The first view shows the locally received SearXNG results as a safe, escaped results page. After navigation, click, input, and scrolling, three real JPEG frames follow by default at intervals of 240 milliseconds. This captures dynamically loaded content and visible page changes without inventing an artificial video stream. The Ghost Cursor retains its last position in every frame. A separate click signal is set only together with the real bounding box of a clicked Playwright target. The images are not served as public files, but are stored job-bound in the dedicated database, read only through the authenticated SSE endpoint, and removed by default after 24 hours.

The following time limits apply by default:

- action decision by Ollama: 420 seconds
- final Ember report: 420 seconds
- live window without a new step event: 480 seconds
- entire browse SSE channel: 900 seconds

On particularly slow CPU systems, the two Ollama time limits can be adjusted in `api/config.local.php`:

```php
define('STU_EMBER_BROWSE_AGENT_TIMEOUT', 600);
define('STU_EMBER_BROWSE_REPORT_TIMEOUT', 600);
define('STU_EMBER_BROWSE_AGENT_RETRIES', 0);
define('STU_EMBER_BROWSE_FRAME_QUALITY', 45);
define('STU_EMBER_BROWSE_FRAME_BURST', 3);
define('STU_EMBER_BROWSE_FRAME_INTERVAL_MS', 240);
define('STU_EMBER_BROWSE_FRAME_RETENTION_HOURS', 24);
```

`STU_EMBER_BROWSE_AGENT_RETRIES` remains `0` by default because a read-side timed-out request may still be processing on the Ollama server. Optional retries apply only to an immediate connection failure, not to a read timeout. Per action, 1 to 5 follow-up frames are allowed, with the interval limited to 120 to 800 milliseconds. JPEG quality is limited to values from 25 to 75, and retention to 1 to 168 hours. After a change, only the worker needs to be restarted:

```bash
cd /opt/ember-coreui
sudo ./scripts/stack.sh restart browse
```

## Use an existing SearXNG instance

```bash
sudo env \
  COREUI_INSTALL_SEARXNG=0 \
  COREUI_SEARXNG_URL='http://127.0.0.1:8888/search' \
  ./scripts/install.sh
```

This deliberately creates a dependency on that existing search instance. For maximum isolation, the dedicated SearXNG instance remains recommended.

Without web search and browse worker:

```bash
sudo env \
  COREUI_INSTALL_SEARXNG=0 \
  COREUI_INSTALL_BROWSE=0 \
  COREUI_SEARXNG_URL='' \
  ./scripts/install.sh
```

## Diagnostics and operation

```bash
cd /opt/ember-coreui
sudo ./scripts/preflight.sh
sudo ./scripts/stack.sh ps
sudo ./scripts/stack.sh logs --tail=100
curl -fsS http://127.0.0.1:9080/api/health.php | python3 -m json.tool
sudo ./scripts/stack.sh exec -T -u 33:33 php php scripts/video-selftest.php
sudo ./scripts/stack.sh exec -T -u 33:33 php php scripts/pdf-selftest.php
sudo ./scripts/stack.sh exec -T -u 33:33 php php scripts/thinking-sanitize-selftest.php
sudo ./scripts/stack.sh exec -T -u 33:33 php php scripts/reply-pipeline-selftest.php
sudo ./scripts/stack.sh exec -T -u 33:33 php php scripts/session-selftest.php
sudo ./scripts/stack.sh exec -T -u 33:33 php php scripts/profile-knowledge-selftest.php
sudo ./scripts/stack.sh exec -T -u 33:33 php php scripts/logo-alpha-selftest.php
sudo ./scripts/ollama-parallel-report.sh
sudo grep -E 'ember_(video|pdf)_|ember_vision_payload' logs/stu_error.log | tail -n 30
```

Watch only the browse worker:

```bash
sudo ./scripts/stack.sh logs -f browse
```

Restart the stack:

```bash
sudo ./scripts/stack.sh restart
```

Stop the stack without deleting data:

```bash
sudo ./scripts/stack.sh stop
```

## Backup

```bash
cd /opt/ember-coreui
sudo install -d -m 0700 /var/backups/ember-coreui
sudo ./scripts/stack.sh exec -T database sh -ec \
  'mariadb-dump --protocol=socket -uroot -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE"' \
  | sudo tee /var/backups/ember-coreui/database.sql >/dev/null

sudo tar -C /opt -czf /var/backups/ember-coreui/files.tgz \
  ember-coreui/api/config.local.php \
  ember-coreui/var/compose.env \
  ember-coreui/assets/chat_media \
  ember-coreui/uploads \
  ember-coreui/var/console_media \
  ember-coreui/var/profile_media \
  ember-coreui/var/knowledge_uploads
```

## Native installer for an empty standalone server

The previous host installer remains included as `scripts/install-native.sh`. It installs Nginx, PHP-FPM, and MariaDB directly on the host and is therefore not intended for the parallel STU production server.

It can only be started with explicit confirmation:

```bash
sudo env COREUI_NATIVE_CONFIRM=DEDICATED_SERVER ./scripts/install-native.sh
```

Even then, the native installer does not remove an existing Nginx default site unless `COREUI_DISABLE_NGINX_DEFAULT=1` has been deliberately set.

## Project structure

```text
ember-coreui/
  admin/               isolated Admin Core for levels 0 and 1
  api/                 local REST, SSE, upload, and CRUD endpoints
  assets/              local fonts and chat media
  config/              runtime, Modelfile, and native templates
  css/                 independent Holo HUD design
  database/migrations/ complete Ember CoreUI schema
  docker/              dedicated PHP and Playwright images
  docs/                local Lore sources for RAG-Lite
  images/              local UI images
  js/                  streaming, session, deduplication, and tool logic
  scripts/             parallel installer, native installer, and preflight
  tools/               Lore, browse, and optional Python workers
  uploads/             local browser screenshots
  var/                 database, runtime configuration, and caches
  compose.yaml         isolated standard stack
```

## Separate versioning

Ember CoreUI versions are not equivalent to Repack versions. The mobile fix from Repack patch `v1.1.1.96` is included, but does not determine the Ember CoreUI release sequence.

## Copyright and legal finalization

Copyright: `© 2026 Patrick Schildgen, Starlight Unit Studios.`

The studio logo is used from the provided original file. For the light Ember CoreUI interface, only the black background was made transparent; the motif, wording, proportions, and existing dark outlines remain unchanged. The licenses of the locally bundled fonts are documented separately under `assets/fonts/LICENSES.md`. The new license does not replace the still-pending legal review of a later final release.

A complete German legal notice and a final privacy policy are deliberately not yet included as finished legal pages. Before the public final release, the actual operator information, serviceable address, representation details, contact channels, hosting data-processing arrangement, and desired privacy texts must be established. The package does not invent such information.

## Alpha status

`0.4.2-alpha` introduces the free Ember CoreUI Community Source License, an explicit license history, binding trademark rules, an independence statement, and a zero-monetization policy. All features from `0.4.1-alpha` remain included. There is no user counting, no phone-home, no license keys, no paywall, and no paid Ember CoreUI offering.
