# Project STΛRLIɢHT: Ember CoreUI

Ember CoreUI ist ein eigenstaendiges WebUI fuer einen lokalen E.M.B.E.R.-Kern. Bis zur finalen Aufnahme in den STU-Repack besitzt es eine eigene Versionierung und einen eigenen Release-Zyklus.

Aktuelle Version: `0.3.2-alpha`

## 🚀 Installation

Führe einfach diesen Befehl in deinem Linux-Terminal aus, um den automatischen Setup-Prozess zu starten:

```bash
curl -fsSL https://raw.githubusercontent.com/pschildgen87-code/coreui/main/setup.sh -o /tmp/coreui-setup.sh && bash /tmp/coreui-setup.sh
```


## Ergebnis dieses Releases

`0.3.2-alpha` ergaenzt die echten serverseitigen Sitzungen um eine kontrollierte, endgueltige Loeschfunktion. Aktive Unterhaltungen koennen weiterhin nur archiviert werden. Erst in der Archivansicht erscheint `ENDGÜLTIG LÖSCHEN`; Warnung und Texteingabe schuetzen vor einem versehentlichen Klick. Der Server entfernt die Sitzung zusammen mit ihren Nachrichten, Reaktionen, abgeschlossenen Browser-Recherchen, Schritten, Frames und nicht mehr referenzierten Upload-Datensaetzen in einer SQL-Transaktion.

Die in `0.3.1-alpha` eingefuehrten echten Sitzungs-Scopes, Turn-Zuordnungen und verlustfreien Altverlaeufe bleiben erhalten. Game, Homepage, Apache, KeyHelp, Host-PHP, Repack-Pfade und die bestehende STU-Datenbank bleiben unberuehrt.

## Echte Sitzungen statt Demo-Zeiger

Vor `0.3.1-alpha` bestand eine Sitzung nur aus Titel und `since_id`. Alle privaten Nachrichten lagen trotzdem in einem gemeinsamen Benutzerstrom. Das fuehrte zu falschen Ausschnitten, springender Reihenfolge und fehlenden letzten Nachrichten. Der neue Pfad behebt das strukturell:

- `stu_chat_messages.session_id` trennt Unterhaltungen dauerhaft in der Datenbank.
- `reply_to_id` ordnet jede Ember-Antwort dem exakten User-Turn zu.
- `console_messages.php` liefert ausschliesslich Nachrichten einer geprueften, eigenen Sitzung.
- Der Client merkt sich die aktive Sitzungs-ID statt eines instabilen Listenindex.
- Asynchrone History-, Poll-, SSE- und Browser-Callbacks pruefen ihre Sitzungs-ID, bevor sie die sichtbare Oberflaeche veraendern.
- Lesemarkierungen veraendern nicht mehr `updated_at`; nur echte neue Nachrichten sortieren eine Sitzung nach oben.
- Neueste Nachrichten werden direkt geladen. Aeltere Seiten koennen ohne Rechenfenster oder `since_id`-Schaetzung nachgeladen werden.
- Sitzungen werden nicht mehr automatisch geloescht. Archivieren ist eine reversible Metadatenoperation; der Nachrichtenverlauf bleibt erhalten.
- Eine eigene Archivansicht erlaubt das Wiederherstellen archivierter Sitzungen. Kurzzeitige Ladefehler verdraengen bereits bestaetigte Nachrichten nicht mehr aus der Ansicht.
- Endgueltiges Loeschen ist ausschliesslich in der Archivansicht verfuegbar und verlangt zwei bewusste Bestaetigungen.
- Der API-Endpunkt akzeptiert die Loeschung nur fuer eine eigene, archivierte Sitzung und prueft eine sitzungsspezifische Bestaetigung nochmals serverseitig.
- Eine SQL-Transaktion entfernt Reaktionen, Browserframes, Browserschritte, abgeschlossene Browserjobs, Nachrichten, verwaiste Upload-Datensaetze und zuletzt die Sitzungszeile. Ein Fehler rollt die gesamte Datenbankoperation zurueck.
- Sitzungen mit einem Browserjob im Zustand `queued` oder `running` werden nicht geloescht. Dadurch kann dem Worker keine aktive Recherche unter den Fuessen entfernt werden.
- Physische Upload-Dateien und der abschliessende Screenshot entfernter Browserjobs werden erst nach erfolgreichem Datenbank-Commit und nur aus `var/console_media`, `assets/chat_media` oder `uploads/ember_browse` entfernt. Dateien mit weiteren Nachrichtenreferenzen bleiben erhalten.
- Migration `003_console_sessions` sammelt nicht eindeutig trennbare Alt-Nachrichten verlustfrei in einem wiederhergestellten Verlauf.
- Der eigene Sitzungs-Selftest prueft Schema, History-Scope, Turn-Zuordnung, Client-Rennen, Browser-Worker-Weitergabe und die vollstaendige transaktionale Loeschkaskade mit Rollback.

## Sicherer Denkstatus und Bereinigung alter Sitzungen

CoreUI trennt die fluechtige interne Modellanalyse strikt von der sichtbaren Oberflaeche:

- SSE liefert waehrend der Generierung nur feste Statusstufen wie `KONTEXT WIRD ABGEGLICHEN` und `ANTWORT WIRD FORMULIERT`.
- `message.content` und `message.thinking` werden vollstaendig gesammelt und erst nach der gemeinsamen Sicherheitspruefung verarbeitet.
- Englische und deutsche Prompt-, Analyse-, Entwurfs- und Selbstkorrektur-Muster werden vor SSE, Datenbank und History abgefangen.
- Neue `thinking_content`-Werte enthalten nur den sicheren Abschlussstatus.
- Der Fetch-Endpunkt ersetzt alte Raw-Werte vor der JSON-Ausgabe fail-closed.
- Der Browser besitzt eine zusaetzliche Whitelist und schreibt keinen beliebigen Thinking-Text in den DOM.
- Das Antwortbudget wird pro Benutzer an Ollama uebergeben. Ein erkanntes Tokenlimit setzt an der vorhandenen Antwort fort, statt die gesamte Generierung neu zu beginnen.
- Private Console-Nachrichten duerfen bis zu 12000 Zeichen enthalten, mit Anhang bis zu 20000 Zeichen. Zeilenumbrueche und Code-Einrueckungen bleiben erhalten.
- Der SSE-Aufruf uebergibt nur die authentifizierte Turn-ID. Der Nachrichtentext wird aus der CoreUI-Datenbank geladen und erscheint nicht in der EventSource-URL oder in Proxy-Zugriffslogs.
- Die Nachrichtenspalte wird durch Migration `002_coreui_management` auf `MEDIUMTEXT` erweitert.

Nach dem Update koennen bereits vorhandene Raw-Blöcke einmalig in der isolierten CoreUI-Datenbank ersetzt werden:

```bash
cd /opt/ember-coreui
sudo ./scripts/stack.sh scrub-thinking
```

Der Befehl veraendert weder die Game-Datenbank noch den Global-Chat.

Nur Ollama wird als bereits vorhandene lokale Modell-Schnittstelle gemeinsam benutzt. CoreUI erzeugt dafuer das getrennte Modell `ember-coreui:latest`. Ein vorhandenes STU-Modell wie `gemma4:26b` wird weder ersetzt noch umkonfiguriert.

## Benutzerprofil und CoreUI-Einstellungen

`settings.html` verbindet lokale Anzeigeoptionen mit einem echten serverseitigen KI-Profil:

- eigener System-Prompt fuer Stil und Arbeitsweise innerhalb der unveraenderten CoreUI-Sicherheits- und Identitaetsregeln
- Memory ein oder aus und einstellbares Memory-Limit
- Antwortbudget von 256 bis 16384 Ollama-Tokens
- Temperatur von 0,1 bis 1,5
- eigene sichtbare Memory-Fakten anlegen und loeschen
- Konto, Berechtigungsstufe, Health-Matrix, Modell und Version
- lokaler Referenzbetrieb mit Gemma 4 und Ollama

Der vorbereitete externe Provider-Adapter bleibt standardmaessig deaktiviert. API-Schluessel werden nie im Browser gespeichert. Erst eine spaetere serverseitig getestete Implementierung kann ihn freischalten; es gibt kein Kompatibilitaetsversprechen fuer beliebige Cloud-Anbieter.

## Admin Core

`admin/index.html` ist nur fuer Berechtigungsstufe 0 und 1 erreichbar und arbeitet ausschliesslich gegen die CoreUI-Datenbank. Enthalten sind:

- Uebersicht fuer Konten, Sitzungen, Nachrichten, Memories, Lore, Browse-Jobs und Logs
- Benutzerrechte, zeitlich begrenzte Sperren und Sperrgruende
- Memory CRUD fuer globale, Benutzer- und Charakter-Sichtbarkeit
- Lore-Quellen und Chunk-Suche
- Registrierung, Wartungsmodus und Wartungsmeldung
- zentrale Schalter fuer schnelle Websuche, Browser-Recherche und isoliertes Python
- vorbereitete externe Provider-Metadaten ohne Browser-Schluessel
- Fehlerprotokoll und unveraenderliches Admin-Audit

Rechteaenderungen und aktive Sperren werden bei jedem authentifizierten Request erneut aus der Datenbank gelesen. Sie wirken damit auch auf bereits angemeldete Sitzungen.

Die Anzeigeoptionen werden unter `emberCoreUI.preferences.v1` im lokalen Browser gespeichert. Sie aendern weder Datenbankschema noch Konten, Sessions, Erinnerungen oder Modellkonfiguration.

## Sichere Runtime-Aktualisierung

Der Update-Workflow behaelt `var/compose.env`, Datenbanken, Uploads und Caches bei. Gleichzeitig werden die nicht geheimen Nginx- und PHP-FPM-Runtime-Dateien vor jedem relevanten Stack-Start aus den aktuellen Paket-Templates neu erzeugt:

```bash
sudo ./scripts/stack.sh refresh-runtime
sudo ./scripts/stack.sh restart web
```

Bei `sudo ./scripts/stack.sh up -d --force-recreate php web` erfolgen Runtime-Refresh und ausstehende additive Datenbankmigrationen automatisch. Die Runtime-Werte fuer Bind-Adresse und Ports stammen weiterhin aus der lokal geschuetzten `var/compose.env`; Zugangsdaten werden weder in Templates geschrieben noch ausgegeben.

## Isolationsgrenzen

| Bereich | CoreUI-Parallelbetrieb |
|---|---|
| Projektdateien | Ausschliesslich unter `/opt/ember-coreui` |
| Webserver | Eigener Nginx-Container auf `127.0.0.1:9080` |
| PHP | Eigener PHP-FPM-Container auf `127.0.0.1:19073` |
| Datenbank | Eigener MariaDB-Container auf `127.0.0.1:13306` |
| Websuche | Eigener SearXNG-Container auf `127.0.0.1:18889` |
| Browser-Recherche | Eigener Playwright-Container |
| Live-Browserbilder | Private CoreUI-Datenbank, authentifizierte Job-SSE, automatische Bereinigung |
| Modell | Eigenes Ollama-Modell `ember-coreui:latest` |
| Login-Sessions | Eigener Cookie-Name `EMBERCOREUISESSID` |
| Chat-Sitzungen | Eigene `session_id`, exakte History und Turn-Zuordnung in der CoreUI-Datenbank |
| Locks | Eigener Namespace `ember_coreui:*` |
| Laufzeitdaten | Lokale Ordner `var/`, `logs/`, `uploads/` und `assets/chat_media/` |
| PDF-Seitenbilder | Eigener kurzlebiger Runtime-Pfad `var/pdf_pages` |

Die Container verwenden auf Linux das Host-Netzwerk, damit sie Ollama auf `127.0.0.1:11434` erreichen koennen, ohne den bestehenden Ollama-Dienst auf `0.0.0.0` umzubinden. Alle CoreUI-Dienste erhalten eigene, vor der Installation gepruefte Loopback-Ports.

Gemeinsame Ollama-Nutzung bedeutet weiterhin gemeinsame CPU-, RAM- und GPU-Ressourcen. Das ist keine Daten- oder Konfigurationskopplung, kann bei gleichzeitigen Generierungen aber die Antwortzeit beider Oberflaechen beeinflussen.

## Game und CoreUI gleichzeitig antworten lassen

Game und CoreUI blockieren sich auf Anwendungsebene bereits nicht:

- Das Game verwendet seine vorhandene STU-Datenbank und den Lock `ember_global_ollama`.
- CoreUI verwendet seine eigene MariaDB-Instanz und standardmaessig den Lock `ember_coreui:global_ollama`.
- Chatverlaeufe, Sitzungen, Uploads, Memories und RAG-Daten bleiben getrennt.
- Gemeinsam ist nur der lokale Ollama-Dienst auf `127.0.0.1:11434`.

Damit zwei verschiedene Modellnamen gleichzeitig geladen werden koennen, braucht Ollama genug RAM oder VRAM. Zuerst den rein lesenden Bericht ausfuehren:

```bash
cd /opt/ember-coreui
sudo ./scripts/ollama-parallel-report.sh
```

Wenn beide Modelle gemeinsam in den vorhandenen RAM oder VRAM passen, kann Ollama ueber einen systemd-Drop-in auf zwei geladene Modelle begrenzt werden:

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

Ohne eigene Begrenzung erlaubt Ollama laut offizieller Dokumentation standardmaessig bereits mindestens drei geladene Modelle. Der Drop-in ist deshalb nur noetig, wenn der Bericht eine abweichende bestehende Begrenzung zeigt oder die Obergrenze bewusst auf genau zwei festgeschrieben werden soll. `OLLAMA_NUM_PARALLEL=1` gilt je geladenem Modell und vermeidet zusaetzliche Kontextkopien. Falls Game und CoreUI spaeter denselben Modellnamen verwenden sollen, kann `OLLAMA_NUM_PARALLEL=2` noetig sein. Das vervielfacht jedoch den Speicherbedarf des Kontextes und sollte erst nach einem RAM-/VRAM-Test gesetzt werden. Reicht der Speicher nicht, reiht Ollama Anforderungen in seine Warteschlange ein, statt echte Gleichzeitigkeit zu erzwingen.

Der CoreUI-Installer schreibt diese systemweite Ollama-Konfiguration bewusst nicht selbst. Der Ollama-Dienst ist die einzige gemeinsam genutzte Ressource und bleibt unter Kontrolle des Serverbetreibers.

Offizielle Referenz: <https://docs.ollama.com/faq#how-does-ollama-handle-concurrent-requests>

CoreUI ersetzt spaeter die alte STU-Console, nicht den Global-Chat im Game. Die Entfernung der alten Console gehoert deshalb in ein separates Repack-Update, nachdem CoreUI im Alltag stabil getestet wurde. Dieses Standalone-Paket loescht keine Repack-Dateien und aendert keine Game-Routen.

## Unterstuetzte Zielsysteme

- Debian 12 oder neuer
- Ubuntu Server 24.04 oder neuer
- Root- oder sudo-Zugriff per SSH
- Docker oder Erlaubnis, Docker automatisch zu installieren
- Lokales Ollama oder Erlaubnis, Ollama automatisch zu installieren
- Genuegend RAM, VRAM und Speicher fuer den ausgewaehlten Gemma-4-Tag
- Ausgehender Internetzugriff waehrend des ersten Image-Builds

Ollamas Linux-Anleitung: <https://docs.ollama.com/linux>

Gemma-4-Modellseite: <https://ollama.com/library/gemma4>

SearXNG-Docker-Dokumentation: <https://docs.searxng.org/admin/installation-docker.html>

## Sichere Parallelinstallation per SSH

Das Archiv dauerhaft nach `/opt/ember-coreui` verschieben:

```bash
cd /tmp
sudo apt-get update
sudo apt-get install -y ca-certificates curl unzip
export COREUI_ARCHIVE_URL='https://DEIN-SERVER/EMBER_COREUI_0_3_2_ALPHA.zip'
curl -fL "$COREUI_ARCHIVE_URL" -o EMBER_COREUI_0_3_2_ALPHA.zip
curl -fL "$COREUI_ARCHIVE_URL.sha256" -o EMBER_COREUI_0_3_2_ALPHA.zip.sha256
sha256sum -c EMBER_COREUI_0_3_2_ALPHA.zip.sha256
unzip -q EMBER_COREUI_0_3_2_ALPHA.zip
sudo mv EMBER_COREUI_0_3_2_ALPHA /opt/ember-coreui
cd /opt/ember-coreui
sudo chmod 0750 scripts/*.sh
sudo ./scripts/install.sh
```

Der Installer fragt nach:

- Admin-E-Mail
- Admin-Passwort mit mindestens 12 Zeichen
- optionalem Anzeigenamen ueber `COREUI_ADMIN_NAME`

Anschliessend werden ausschliesslich der eigene Compose-Stack, die eigene Datenbank, das eigene CoreUI-Modell und die lokalen Projektdateien eingerichtet.

## Update auf 0.3.2-alpha

Die vorhandene Datenbank, Uploads, Sitzungen und Zugangsdaten bleiben erhalten. Das neue Paket wird nur ueber die statischen Projektdateien gelegt; Runtime-Ordner werden bewusst ausgeschlossen.

```bash
cd /home/users/game/tmp
sha256sum -c EMBER_COREUI_0_3_2_ALPHA.zip.sha256
unzip -q -o EMBER_COREUI_0_3_2_ALPHA.zip
sudo apt-get update
sudo apt-get install -y rsync

sudo rsync -a \
  --exclude='api/config.local.php' \
  --exclude='var/' \
  --exclude='logs/' \
  --exclude='uploads/' \
  --exclude='assets/chat_media/' \
  --exclude='assets/profile_photos/' \
  EMBER_COREUI_0_3_2_ALPHA/ /opt/ember-coreui/

sudo install -d -m 0770 -o 33 -g 33 /opt/ember-coreui/var/ember_frames
sudo install -d -m 0770 -o 33 -g 33 /opt/ember-coreui/var/pdf_pages
cd /opt/ember-coreui
sudo ./scripts/stack.sh migrate
sudo ./scripts/stack.sh up -d --force-recreate browse php web
sudo ./scripts/stack.sh scrub-thinking
sudo ./scripts/preflight.sh
```

`migrate` legt die Benutzer-KI- und Admin-Tabellen an, erweitert die private Nachrichtenspalte auf `MEDIUMTEXT` und aktiviert die echte Sitzungsstruktur. Der Befehl ist idempotent und arbeitet nur in der isolierten CoreUI-Datenbank. Nicht eindeutig trennbare Alt-Nachrichten bleiben im wiederhergestellten Verlauf erhalten. `scrub-thinking` ersetzt historische Raw-Thinking-Werte durch den sicheren festen Status.

Ein erneutes Erzeugen des Modells, ein neuer Lore-Import oder ein Account-Bootstrap sind nicht erforderlich. Die vorhandene `api/config.local.php` bleibt erhalten. Selbst ein dort verbliebener `STU_EMBER_MAX_REPLY_CHARS`-Altwert von 7200 kann das neue Benutzerbudget nicht mehr unter die sichere CoreUI-Untergrenze klemmen. Bei sehr alten Staenden vor `0.2.3-alpha` muss das PHP-Image einmal mit `sudo ./scripts/stack.sh build --no-cache php` neu gebaut werden, damit Poppler vorhanden ist.

## Testen ohne Eingriff in bestehende Domains

CoreUI lauscht standardmaessig nur auf `127.0.0.1:9080`. Vom eigenen Rechner wird ein SSH-Tunnel geoeffnet:

```bash
ssh -L 9080:127.0.0.1:9080 DEIN_SSH_USER@DEIN_SERVER
```

Danach im lokalen Browser oeffnen:

```text
http://127.0.0.1:9080/
```

Damit ist kein neuer Host-Nginx-VHost, keine Portfreigabe und keine Aenderung an einer vorhandenen Homepage notwendig.

## Oeffentliche Adresse

Die aktuelle produktive Adresse ist:

```text
https://coreui.starlight-unit.de
```

Der Reverse Proxy leitet diese Domain weiterhin intern an `http://127.0.0.1:9080` weiter. In der erhaltenen Datei `/opt/ember-coreui/api/config.local.php` muss dazu genau eine Definition aktiv sein:

```php
define('STU_PUBLIC_BASE_URL', 'https://coreui.starlight-unit.de');
```

Die fruehere Adresse `webui.starlight-unit.de` ist keine aktuelle CoreUI-Basisadresse mehr. Da `config.local.php` bei Updates absichtlich ausgeschlossen wird, veraendert das Release diese lokale Betreiberkonfiguration nicht automatisch.

## Was der Standardinstaller nicht tut

- Er entfernt keine Datei aus `/etc/nginx/sites-enabled/`.
- Er schreibt keine Host-Nginx- oder Apache-Konfiguration.
- Er schreibt keine globale PHP-Konfiguration und startet kein vorhandenes PHP-FPM neu.
- Er legt keine Tabellen in der bestehenden STU-Datenbank an.
- Er aendert keinen bestehenden MariaDB-Benutzer.
- Er installiert keinen systemd-Dienst fuer den Browse-Worker.
- Er schreibt nicht nach `/home/users/game`, `/var/www` oder in einen Repack-Pfad.
- Er ersetzt kein vorhandenes STU-Ollama-Modell.

Falls Docker fehlt und `COREUI_INSTALL_DOCKER=1` aktiv ist, installiert und startet der Installer Docker. Falls Ollama fehlt und `COREUI_INSTALL_OLLAMA=1` aktiv ist, installiert er Ollama. Bereits vorhandene erreichbare Dienste werden nicht umkonfiguriert.

## Installationsablauf

1. Validierung des Betriebssystems, Projektpfads und der Eingaben.
2. Kollisionspruefung der eigenen Ports und des Compose-Projektnamens.
3. Anlage eigener Laufzeitordner und Zugangsdaten.
4. Validierung und Build der PHP- und Browse-Images.
5. Erkennung oder Download eines Gemma-4-Basismodells.
6. Erzeugung des getrennten Modells `ember-coreui:latest`.
7. Start der eigenen MariaDB und idempotenter Import aller SQL-Migrationen.
8. Anlage von Ember, Admin, Charakter und `stu_kv`.
9. Import der beiden mitgelieferten Lore-Dokumente in RAG-Lite.
10. Start von SearXNG, PHP-FPM, Nginx und Browse-Worker.
11. Abschliessender Healthcheck.

## Nicht-interaktive Installation

Das Passwort sollte nicht als Klartext in der Shell-Historie stehen:

```bash
cd /opt/ember-coreui
export COREUI_ADMIN_EMAIL='admin@example.org'
read -r -s -p 'CoreUI Admin-Passwort: ' COREUI_ADMIN_PASSWORD
printf '\n'
export COREUI_ADMIN_PASSWORD
export COREUI_ADMIN_NAME='Operator'
sudo --preserve-env=COREUI_ADMIN_EMAIL,COREUI_ADMIN_PASSWORD,COREUI_ADMIN_NAME ./scripts/install.sh
unset COREUI_ADMIN_PASSWORD
```

## Wichtige Optionen

| Variable | Standard | Bedeutung |
|---|---:|---|
| `COREUI_HTTP_PORT` | `9080` | Eigener Web-Port |
| `COREUI_PHP_PORT` | `19073` | Eigener PHP-FPM-Port |
| `COREUI_DB_PORT` | `13306` | Eigener MariaDB-Port |
| `COREUI_SEARXNG_PORT` | `18889` | Eigener SearXNG-Port |
| `COREUI_BIND_ADDRESS` | `127.0.0.1` | Sichere Web-Bind-Adresse |
| `COREUI_BASE_MODEL` | vorhandenes `gemma4:*`, sonst `gemma4:e4b` | Ollama-Basismodell |
| `COREUI_MODEL_NAME` | `ember-coreui:latest` | Getrennter CoreUI-Modellname |
| `COREUI_LOCK_NAMESPACE` | `ember_coreui` | Eigener MariaDB-Lock-Namespace |
| `COREUI_VIDEO_FRAMES` | `6` | Gleichmaessig verteilte Frames je Video, maximal 32 |
| `COREUI_VIDEO_FRAME_PX` | `512` | Maximale Breite und Hoehe eines Vision-Frames |
| `COREUI_PDF_MAX_PAGES` | `4` | Repraesentative Seiten je Scan-PDF, maximal 12 |
| `COREUI_PDF_PAGE_PX` | `1024` | Maximale Breite und Hoehe einer gerasterten PDF-Seite |
| `COREUI_INSTALL_DOCKER` | `1` | Installiert Docker, falls erforderlich |
| `COREUI_INSTALL_OLLAMA` | `1` | Installiert Ollama, falls erforderlich |
| `COREUI_INSTALL_SEARXNG` | `1` | Startet die eigene SearXNG-Instanz |
| `COREUI_INSTALL_BROWSE` | `1` | Startet den eigenen Browse-Worker |
| `COREUI_SKIP_LORE` | `0` | Ueberspringt den Lore-Import |
| `COREUI_SKIP_BOOTSTRAP` | `0` | Ueberspringt Admin- und Ember-Bootstrap |
| `COREUI_FORCE_CONFIG` | `0` | Erzeugt `api/config.local.php` neu |

Jede Portkollision fuehrt vor dem Start der CoreUI-Container zu einem Abbruch. Bereits vorhandene fremde Container mit dem Compose-Projektnamen `ember-coreui` werden ebenfalls nicht uebernommen.

## PDF-Erkennung

Der Dokumentpfad arbeitet in zwei Stufen:

1. `pdftotext` liest PDFs mit echter Textebene direkt und begrenzt den Prompt-Auszug wie andere Textanhaenge.
2. Bleibt die Textebene leer, rendert `pdftoppm` bis zu vier gleichmaessig verteilte Seiten als JPEG. Diese Seiten gehen in korrekter Reihenfolge an Gemmas Vision-Pfad.

Bei einem langen Scan-PDF sieht Ember damit eine Stichprobe, nicht automatisch jede Seite. Die Antwort muss diesen Umfang offen benennen. Fuer eine andere Begrenzung koennen in `api/config.local.php` die Konstanten `STU_EMBER_PDF_MAX_PAGES` und `STU_EMBER_PDF_PAGE_PX` gesetzt werden. Mehr Seiten und hoehere Aufloesung vergroessern Vision-Laufzeit, Kontext und Speicherbedarf deutlich.

Wenn Poppler fehlt, der Runtime-Pfad nicht beschreibbar ist oder keine Seite gerendert werden kann, wird die Modellgenerierung fuer den Dokumentinhalt gestoppt. Dadurch entsteht keine frei erfundene PDF-Zusammenfassung.

## Browser-Recherche und Ollama-Laufzeiten

Der Browse-Worker trennt Browsernavigation und Modellentscheidung klar voneinander. SearXNG-Suche, `goto`, Klicks und Seitentext-Erfassung laufen ueber Playwright. Erst danach entscheidet Ollama ueber den naechsten Schritt. Ein Ollama-Read-Timeout ist deshalb kein Playwright-Fehler.

Im Chat ersetzt der aktuelle Browser-Viewport das fruehere Textprotokoll. Die erste Ansicht zeigt die lokal empfangenen SearXNG-Treffer als sichere, escaped Ergebnisseite. Nach Navigation, Klick, Eingabe und Scrollen folgen standardmaessig drei echte JPEG-Frames im Abstand von 240 Millisekunden. So werden nachgeladene Inhalte und sichtbare Seitenveraenderungen erfasst, ohne einen kuenstlichen Videostream zu erfinden. Der Ghost-Cursor behaelt seine letzte Position in jedem Frame. Ein separates Klicksignal wird nur zusammen mit der realen Bounding-Box eines angeklickten Playwright-Ziels gesetzt. Die Bilder werden nicht als oeffentliche Dateien ausgeliefert, sondern jobgebunden in der eigenen Datenbank gespeichert, nur ueber den authentifizierten SSE-Endpunkt gelesen und standardmaessig nach 24 Stunden entfernt.

Im Standard gelten folgende Fristen:

- Aktionsentscheidung durch Ollama: 420 Sekunden
- abschliessender Ember-Report: 420 Sekunden
- Live-Fenster ohne neues Schritt-Ereignis: 480 Sekunden
- gesamter Browse-SSE-Kanal: 900 Sekunden

Auf besonders langsamen CPU-Systemen koennen die beiden Ollama-Fristen in `api/config.local.php` angepasst werden:

```php
define('STU_EMBER_BROWSE_AGENT_TIMEOUT', 600);
define('STU_EMBER_BROWSE_REPORT_TIMEOUT', 600);
define('STU_EMBER_BROWSE_AGENT_RETRIES', 0);
define('STU_EMBER_BROWSE_FRAME_QUALITY', 45);
define('STU_EMBER_BROWSE_FRAME_BURST', 3);
define('STU_EMBER_BROWSE_FRAME_INTERVAL_MS', 240);
define('STU_EMBER_BROWSE_FRAME_RETENTION_HOURS', 24);
```

`STU_EMBER_BROWSE_AGENT_RETRIES` bleibt standardmaessig `0`, weil ein leseseitig abgelaufener Request auf dem Ollama-Server noch rechnen kann. Optionale Wiederholungen greifen nur bei einem unmittelbaren Verbindungsfehler, nicht bei einem Read-Timeout. Pro Aktion sind 1 bis 5 Folgeframes erlaubt, der Abstand ist auf 120 bis 800 Millisekunden begrenzt. Die JPEG-Qualitaet ist auf Werte von 25 bis 75 begrenzt, die Aufbewahrung auf 1 bis 168 Stunden. Nach einer Aenderung muss nur der Worker neu gestartet werden:

```bash
cd /opt/ember-coreui
sudo ./scripts/stack.sh restart browse
```

## Bereits vorhandene SearXNG verwenden

```bash
sudo env \
  COREUI_INSTALL_SEARXNG=0 \
  COREUI_SEARXNG_URL='http://127.0.0.1:8888/search' \
  ./scripts/install.sh
```

Das erzeugt bewusst eine Abhaengigkeit zu dieser vorhandenen Suchinstanz. Fuer maximale Isolation bleibt die eigene SearXNG-Instanz empfohlen.

Ohne Websuche und Browse-Worker:

```bash
sudo env \
  COREUI_INSTALL_SEARXNG=0 \
  COREUI_INSTALL_BROWSE=0 \
  COREUI_SEARXNG_URL='' \
  ./scripts/install.sh
```

## Diagnose und Betrieb

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
sudo ./scripts/stack.sh exec -T -u 33:33 php php scripts/logo-alpha-selftest.php
sudo ./scripts/ollama-parallel-report.sh
sudo grep -E 'ember_(video|pdf)_|ember_vision_payload' logs/stu_error.log | tail -n 30
```

Nur den Browse-Worker beobachten:

```bash
sudo ./scripts/stack.sh logs -f browse
```

Stack neu starten:

```bash
sudo ./scripts/stack.sh restart
```

Stack anhalten, ohne Daten zu loeschen:

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
  ember-coreui/var/console_media
```

## Nativer Installer fuer einen leeren Einzelserver

Der fruehere Host-Installer bleibt als `scripts/install-native.sh` enthalten. Er installiert Nginx, PHP-FPM und MariaDB direkt auf dem Host und ist deshalb nicht fuer den parallelen STU-Produktivserver gedacht.

Er kann nur mit einer ausdruecklichen Bestaetigung gestartet werden:

```bash
sudo env COREUI_NATIVE_CONFIRM=DEDICATED_SERVER ./scripts/install-native.sh
```

Der native Installer entfernt auch dann keine bestehende Nginx-Default-Site, solange `COREUI_DISABLE_NGINX_DEFAULT=1` nicht bewusst gesetzt wurde.

## Projektstruktur

```text
ember-coreui/
  admin/               isoliertes Admin Core fuer Level 0 und 1
  api/                 lokale REST-, SSE-, Upload- und CRUD-Endpunkte
  assets/              lokale Fonts und Chat-Medien
  config/              Runtime-, Modelfile- und Native-Templates
  css/                 eigenstaendiges Holo-HUD-Design
  database/migrations/ vollstaendiges CoreUI-Schema
  docker/              eigene PHP- und Playwright-Images
  docs/                lokale Lore-Quellen fuer RAG-Lite
  images/              lokale UI-Bilder
  js/                  Streaming-, Session-, Dedup- und Werkzeuglogik
  scripts/             Parallelinstaller, Native-Installer und Preflight
  tools/               Lore-, Browse- und optionale Python-Worker
  uploads/             lokale Browser-Screenshots
  var/                 Datenbank, Runtime-Konfiguration und Caches
  compose.yaml         isolierter Standard-Stack
```

## Getrennte Versionierung

Ember CoreUI-Versionen sind nicht mit Repack-Versionen gleichzusetzen. Der Mobile-Fix aus dem Repack-Patch `v1.1.1.96` ist enthalten, bestimmt aber nicht die CoreUI-Releasefolge.

## Copyright und rechtliche Finalisierung

Copyright: `© 2026 Patrick Schildgen · Starlight Unit Studios · Lizenziert unter CC BY-NC-SA 4.0`

Das Studio-Logo wird aus der bereitgestellten Originaldatei verwendet. Fuer die helle CoreUI-Oberflaeche wurde ausschliesslich der schwarze Hintergrund transparent freigestellt; Motiv, Wortlaut, Proportionen und vorhandene dunkle Konturen bleiben erhalten. Die Lizenzen der lokal mitgelieferten Schriften sind getrennt unter `assets/fonts/LICENSES.md` dokumentiert.

Ein vollstaendiges deutsches Impressum und eine abschliessende Datenschutzerklaerung sind bewusst noch nicht als fertige Rechtsseiten enthalten. Dafuer muessen vor dem oeffentlichen Final-Release die echten Betreiberangaben, ladungsfaehige Anschrift, Vertretungsdaten, Kontaktwege, Hosting-Auftragslage und die gewuenschten Datenschutztexte feststehen. Das Paket erfindet keine solchen Angaben.

## Alpha-Status

`0.3.2-alpha` stellt echte, dauerhaft getrennte CoreUI-Chat-Sitzungen samt sicherem Archiv und endgueltiger SQL-Loeschung bereit. Die koexistenzsichere Installationsarchitektur, vollstaendig gepruefte Antwortausgabe, fortsetzbaren Ollama-Antworten, Benutzer-KI-Profile und das isolierte Admin Core bleiben enthalten. PDF-Dateien mit Textebene werden direkt gelesen, Scan-PDFs verwenden eine begrenzte Vision-Stichprobe. Die Browser-Agentenansicht zeigt echte Frame-Bursts und bleibt ebenfalls an die ausloesende Sitzung gebunden. Interne Modellgedanken verlassen die Servergrenze nicht. Video- und Scan-Erkennung bleiben von der Qualitaet und den Vision-Faehigkeiten des verwendeten Gemma-4-Modells abhaengig. Der externe Provider-Adapter ist nur vorbereitet und noch nicht fuer produktive Nutzung freigeschaltet.
