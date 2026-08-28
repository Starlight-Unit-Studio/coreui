# Project STΛRLIɢHT: Ember CoreUI

Ember CoreUI wird bis zur finalen Aufnahme in den STU-Repack eigenstaendig versioniert.

## v0.3.2-alpha - 27.08.2026

- Endgueltige Sitzungsloeschung als eigenstaendige Serverfunktion ergaenzt. Die bisherige Aktion `delete` archivierte lediglich; `delete_permanently` fuehrt nun eine echte SQL-Loeschung aus.
- Schutzfolge bewusst zweistufig gestaltet: Aktive Sitzungen lassen sich nur archivieren. Der rote Befehl `ENDGÜLTIG LÖSCHEN` erscheint ausschliesslich bei archivierten Sitzungen und verlangt zusaetzlich die Texteingabe `LÖSCHEN` oder `LOESCHEN`.
- Serverseitige Bestaetigung mit dem konkreten Sitzungsbezug `DELETE:<session_id>` eingefuehrt. Eine manipulierte Oberflaeche kann die Archivpflicht oder die Bestaetigung nicht umgehen.
- Eigentuemerpruefung und Zeilensperre mit `SELECT ... FOR UPDATE` direkt vor der Loeschung aufgenommen. Fremde, bereits entfernte oder zwischenzeitlich wiederhergestellte Sitzungen werden abgelehnt.
- Laufende und wartende Browser-Recherchen schuetzen die Sitzung vor Loeschung. Jobs im Zustand `queued` oder `running` liefern `session_busy`, statt dem Worker seine Daten waehrend der Verarbeitung zu entziehen.
- Vollstaendige SQL-Transaktion fuer alle eindeutig sitzungsgebundenen Datensaetze umgesetzt. Sie entfernt Reaktionen, Browserframes, Browserschritte, abgeschlossene Browserjobs, Chatnachrichten, verwaiste Console-Medienzeilen und zuletzt die Sitzung selbst.
- Fehlerbehandlung auf vollstaendiges Rollback ausgelegt. Scheitert eine einzelne SQL-Operation, bleiben Sitzung und alle abhaengigen Zeilen unveraendert erhalten.
- Upload-Bereinigung referenzsicher gemacht. Ein `stu_console_media`-Datensatz wird nur entfernt, wenn seine UUID nach dem Nachrichten-DELETE nirgends mehr referenziert wird.
- Physische Mediendateien und der Abschluss-Screenshot entfernter Browserjobs werden erst nach erfolgreichem Datenbank-Commit entfernt. Der Pfadfilter erlaubt ausschliesslich einzelne Dateien unter `var/console_media`, `assets/chat_media` und `uploads/ember_browse`; Traversal, fremde Pfade und Symlink-Ziele ausserhalb werden abgewiesen.
- Loeschergebnis liefert getrennte Zaehler fuer Nachrichten, Reaktionen, Jobs, Schritte, Frames, Medienzeilen sowie geloeschte, fehlende oder nicht entfernbare Dateien. Die Oberflaeche bestaetigt damit die tatsaechliche SQL-Wirkung.
- Archivierung waehrend einer noch offenen lokalen Antwort blockiert. Dadurch kann auch ein direkter, noch nicht als Browse-Job sichtbarer Modellturn nicht versehentlich aus der aktiven Ansicht entfernt werden.
- Browser-Runtime-Caches, Poll-Timer, Pending-Zustand, Verlaufsseiten und Lesecursor der geloeschten Sitzung werden gezielt verworfen; andere Sitzungen bleiben unberuehrt.
- Sitzungs-Selftest um eine produktionsgleiche Loeschtransaktion erweitert. Er legt zwei isolierte Testsitzungen sowie Nachricht, Antwort, Reaktion, Upload, Browse-Job, Schritt und Frame an, prueft Archivpflicht und Busy-Sperre, loescht nur Sitzung A und rollt anschliessend den gesamten Test zurueck.
- Fuer diese Funktion ist keine neue DDL-Migration erforderlich. `0.3.2-alpha` nutzt die in `003_console_sessions` vorhandenen Indizes und fuehrt die eigentliche Loeschung als transaktionales SQL-DML aus.
- Oeffentliche Basisadresse bleibt `https://coreui.starlight-unit.de`. Die erhaltene lokale `config.local.php`, Benutzerkonten, KI-Profile, Memories, Lore, Game, Homepage und STU-Datenbank werden nicht veraendert.

## v0.3.1-alpha - 27.08.2026

- Unfertige Demo-Sitzungen durch echte serverseitige Chat-Sitzungen ersetzt. `stu_chat_messages.session_id` ist jetzt die verbindliche Zugehoerigkeit jeder privaten Nachricht.
- Exakte Turn-Zuordnung mit `reply_to_id` eingefuehrt. Eine Ember-Antwort kann nicht mehr nur deshalb einer falschen Anfrage zugeordnet werden, weil ihre Nachrichten-ID spaeter liegt.
- Eigenen History-Endpunkt `api/console_messages.php` hinzugefuegt. Er prueft Authentifizierung, Sitzungsbesitz und Charakter und filtert jede Abfrage nach Benutzer plus Sitzung.
- Fehlerhafte History-Heuristik `since_id - 80` entfernt. Die neueste Seite wird korrekt rueckwaerts geladen; aeltere Seiten werden ueber einen echten `before_id`-Cursor nachgeladen.
- Instabilen `activeIdx` im Browser durch eine feste `activeSessionId` ersetzt. Sortiert sich die Liste neu, bleibt die geoeffnete Unterhaltung unveraendert.
- Asynchrone History-, Poll-, SSE- und Browser-Callbacks gegen Sitzungswechsel abgesichert. Spaete Antworten duerfen nur ihre Ursprungssitzung aktualisieren und niemals den gerade sichtbaren fremden Verlauf.
- Console-SSE verlangt jetzt `session_id`, laedt den User-Turn aus genau dieser Sitzung und dedupliziert nur ueber die Kombination aus Sitzung plus `reply_to_id`.
- Modellkontext fuer private Unterhaltungen nach `session_id` isoliert. Ohne gueltige Sitzungs-ID liefert der Kontextpfad absichtlich keinen Verlauf.
- Browser-Recherchejobs um `session_id` und `trigger_message_id` erweitert. Systemstatus, finales Rechercheergebnis und Live-Browser-SSE bleiben bis zum Workerabschluss am urspruenglichen Turn gebunden.
- Sitzungsmetadaten um `last_message_id`, `last_read_message_id` und `archived_at` erweitert. Lesemarkierungen veraendern die Aktivitaetsreihenfolge nicht mehr.
- Automatische MariaDB-Aktualisierung von `updated_at` entfernt. Nur echte neue Nachrichten oder eine bewusste Wiederherstellung sortieren Sitzungen neu.
- Automatisches Loeschen der jeweils aeltesten Sitzung entfernt. Es gilt ein klares Limit von 100 aktiven Sitzungen; Archivierung bleibt reversibel und Nachrichten werden nicht geloescht.
- Kanalweite Game-Chat-Kommandos im privaten Console-Kanal deaktiviert, einschliesslich des seltenen modellgenerierten Fallbackpfads ohne SSE. Insbesondere kann ein Console-Text keinen Verlauf anderer Sitzungen ueber einen globalen Prune-Pfad veraendern.
- Sitzungsnamen koennen direkt im Drawer umbenannt, verlustfrei archiviert und ueber die neue Archivansicht wiederhergestellt werden. Neue Unterhaltungen erhalten nach der ersten Nachricht weiterhin automatisch einen sinnvollen Titel.
- Unread-Zaehler, Nachrichtenanzahl, stabile Auswahl nach Reload und Cache pro Sitzung in die Oberflaeche aufgenommen.
- Bereits bestaetigte Nachrichten bleiben bei einem kurzzeitigen History- oder Sitzungslistenfehler sichtbar. Fehlerantworten leeren weder den Sitzungscache noch die lokale Liste.
- Der Hintergrund-Poll beendet den Wartestatus nur noch bei einer Ember-Antwort, deren `reply_to_id` exakt dem offenen Turn dieser Sitzung entspricht.
- Migration `003_console_sessions` hinzugefuegt. Alte, wegen des Demo-Modells nicht verlaesslich trennbare Console-Nachrichten werden pro Benutzer verlustfrei in `WIEDERHERGESTELLTER VERLAUF` gesammelt; leere Demo-Zeiger werden lediglich archiviert.
- `stack.sh up`, `start` und `restart` wenden neue idempotente Migrationen automatisch vor dem Dienststart an. Der explizite Befehl `stack.sh migrate` bleibt verfuegbar.
- Nginx-Allowlists fuer den neuen authentifizierten Endpunkt `console_messages.php` in Compose und Native aktualisiert.
- Eigenen `session-selftest.php` und Preflight-Pruefungen fuer Schema, Scope, Turn-Zuordnung, Legacy-Erhalt, Client-Rennen und Worker-Weitergabe aufgenommen.
- Produktive Dokumentationsadresse von `webui.starlight-unit.de` auf `coreui.starlight-unit.de` aktualisiert. Die lokale `config.local.php` bleibt bei Updates absichtlich erhalten.
- Thinking-Schranke, lange Ollama-Antworten, Benutzer-KI-Einstellungen, Admin Core, Game, Homepage, KeyHelp, Apache, Host-PHP und Repack-Pfade bleiben unveraendert.

## v0.3.0-alpha - 27.08.2026

- Console-SSE auf gepruefte Endausgabe umgestellt. Der Browser erhaelt keine Roh-Tokens aus `message.content` oder `message.thinking`, sondern erst die vollstaendige serverseitig bereinigte Antwort.
- Thinking-Schranke fuer Live-SSE, Datenbankpersistenz, History, Reconnect und Client-DOM geschlossen. Deutsche und englische Prompt-, Entwurfs-, Analyse- und Selbstkorrekturmuster werden fail-closed behandelt.
- Ollama-Abschlussmetadaten `done`, `done_reason` und `eval_count` werden ausgewertet. Antworten am Tokenlimit werden mit bis zu zwei gezielten Segmenten fortgesetzt, ohne die komplette Anfrage erneut zu generieren.
- Frueheren 1000-Token-Deckel entfernt. Das per Benutzer gesetzte `num_predict` wird bis 16384 an Ollama weitergegeben; der gemeinsame Modellpfad erlaubt technisch bis 32768.
- Console-Modelltimeout auf bis zu 900 Sekunden angeglichen. SSE-Heartbeats halten Reverse Proxy und Browser waehrend lokaler langer Generierungen aktiv.
- Erhaltene 0.2.x-Konfigurationen mit `STU_EMBER_MAX_REPLY_CHARS=7200` koennen neue Antworten nicht mehr still kuerzen. Die sichere Ausgabegrenze skaliert mit dem Tokenbudget bis 60000 Zeichen.
- Private Console-Eingaben auf 12000 Zeichen ohne und 20000 Zeichen mit Anhang erweitert. Zeilenumbrueche, Leerzeilen und Code-Einrueckungen bleiben erhalten.
- EventSource uebergibt nur noch die authentifizierte Turn-ID. Lange oder vertrauliche Nachrichten werden aus der CoreUI-Datenbank geladen und landen weder in der URL noch in Proxy-Zugriffslogs.
- Privaten History-Kontext auf bis zu 1200 Zeichen je Nachricht erweitert. Die engen Global-Chat-Grenzen bleiben fuer den Game-Pfad unveraendert.
- Migration `002_coreui_management` erweitert `stu_chat_messages.message` auf `MEDIUMTEXT` und fuegt Benutzer-KI-Profile sowie Admin-Audit hinzu.
- Servergespeichertes KI-Profil je Benutzer hinzugefuegt: System-Prompt, Memory-Schalter, Memory-Limit, Antwortbudget und Temperatur.
- Memory-Verwaltung in den CoreUI-Einstellungen ergaenzt. Benutzer koennen eigene sichtbare Fakten anlegen und loeschen; globale Fakten bleiben geschuetzt.
- Externen Provider als deaktivierte, serverseitig vorbereitete Schnittstelle aufgenommen. API-Schluessel werden nie im Browser gespeichert; lokales Gemma 4 ueber Ollama bleibt der Referenzbetrieb.
- Eigenstaendiges Admin Core mit Uebersicht, Benutzerrechten, Sperren, Memory CRUD, Lore-Suche, Runtime-Schaltern, Registrierung, Wartungsmodus, Logs und Audit hinzugefuegt.
- Werkzeugpfade fuer schnelle Websuche, tiefe Browser-Recherche und isoliertes Python koennen zentral im Admin Core aktiviert oder deaktiviert werden.
- Berechtigungsstufen und aktive Sperren werden bei jedem authentifizierten Request aus der CoreUI-Datenbank aktualisiert und wirken auf bestehende Sitzungen.
- Gehaertete Nginx-Allowlists fuer `settings.html`, `admin/index.html`, `user_settings.php` und `admin.php` in Compose und Native aktualisiert.
- Transparentes Studio-Logo unter `starlight_unit_studios_logo_transparent_v030.png` neu referenziert. Der eindeutige Dateiname umgeht den bis zu sieben Tage alten Browsercache der frueheren schwarzen Datei.
- Preflight um Migration-002-Pruefung, Admin-Route, Reply-Pipeline-Selftest, 15 Thinking-Regressionen und Logo-Cache-Buster-Test erweitert.
- Game, Homepage, KeyHelp, Apache, Host-PHP, Repack-Pfade und bestehende STU-Datenbank bleiben unveraendert.

## v0.2.9-alpha - 19.08.2026

- Rohe `message.thinking`-Tokens aus dem Console-SSE entfernt. Der Server akkumuliert sie nur noch fluechtig fuer die abschliessende Antwortbereinigung und sendet stattdessen feste, inhaltsfreie Fortschrittsstufen.
- Denkpanel zum sicheren `DENKSTATUS` umgebaut. Promptteile, Identitaetsdaten, Systemanweisungen, Entwuerfe und Selbstkorrekturen werden weder live noch aus der Historie in den DOM geschrieben.
- Persistenzschranke in `ember_insert()` ergaenzt: Neue Console-Antworten speichern ausschliesslich einen festen Prozessstatus, niemals den rohen Thinking-Block.
- Console-Fetch fail-closed gehaertet. Historische `thinking_content`-Werte werden serverseitig durch einen sicheren Abschlussstatus ersetzt, bevor die JSON-Antwort erzeugt wird.
- Einmalige Datenbankbereinigung mit `sudo ./scripts/stack.sh scrub-thinking` aufgenommen. Sie ersetzt alte Raw-Thinking-Inhalte nur in der isolierten CoreUI-Datenbank.
- Antwort-Sanitizer um die gemeldeten Muster `Actually, the prompt says`, `The user is asking` und `Let's try` erweitert. Ein fuehrendes, geschlossenes Antwortzitat endet jetzt vor nachfolgender interner Analyse.
- Thinking-Selftest auf zwoelf Faelle erweitert und um Quellcode-Grenzen fuer SSE und Persistenz ergaenzt.
- Studio-Original als echte RGBA-PNG freigestellt. Der schwarze Rechteckhintergrund wurde entfernt; die vorhandenen feinen dunklen Konturen an Wortmarke und Symbolen bleiben erhalten.
- Splash, Login und Einstellungen auf das transparente PNG-Asset umgestellt. Schwarzer Panelhintergrund, Polygonbeschnitt und Rahmen des Logo-Wrappers wurden entfernt.
- Eigene CoreUI-Versionierung auf `0.2.9-alpha` angehoben. Game, Homepage, Repack-Versionierung und auslaufende STU Console bleiben unberuehrt.

## v0.2.8-alpha - 19.08.2026

- 404-Ursache der neuen Einstellungsseite behoben: `settings.html` in die gehaerteten HTML-Allowlists des Compose-Nginx-Templates und des Native-Nginx-Templates aufgenommen.
- Update-Luecke geschlossen, bei der der absichtlich erhaltene `var/`-Ordner eine alte generierte Nginx-Konfiguration behalten konnte.
- `scripts/stack.sh` rendert Nginx- und PHP-FPM-Runtime-Dateien vor `up`, `start` und `restart` automatisch aus den aktuellen Templates und den lokal gespeicherten Portwerten neu.
- Explizite, idempotente Wartungsaktion `scripts/stack.sh refresh-runtime` fuer reine Runtime-Aktualisierungen eingefuehrt.
- Runtime-Refresh validiert Bind-Adresse und Ports, schreibt keine Zugangsdaten in Webserver-Templates und gibt keine Werte aus `compose.env` aus.
- Compose-Preflight liefert bei einer nicht erreichbaren Einstellungsroute nun die konkrete Refresh- und Restart-Anweisung.
- Compose- und Native-Installer lehnen unvollstaendige Pakete ohne Einstellungsseite oder passendes Nginx-Template fruehzeitig ab.
- Keine Schema-, Account-, Session-, Modell-, Game-, Homepage- oder Repack-Aenderung.

## v0.2.7-alpha - 19.08.2026

- Das bereitgestellte originale Starlight Unit Studios-Logo unveraendert als lokales CoreUI-Asset aufgenommen und das fruehere Game-Logo auf Splash und Login ersetzt.
- Die animierte Ren-Sternenmatrix als eigenstaendiges CoreUI-Kernsymbol in der Sidebar erhalten und klar von der Studio-Herausgebermarke getrennt.
- Sichtbare Copyright-Zuordnung fuer Splash, Login, Sidebar, Protokolle und den neuen Info-Bereich ergaenzt.
- Sidebar um den eigenstaendigen Bereich `Einstellungen` erweitert; Einsatz-Protokolle und Abmeldung bleiben direkt erreichbar.
- Funktionale Einstellungsseite fuer reduzierte Bewegung, kompakten Datenstrom, Thinking-Panel-Standard, Desktop-Telemetrie und Live-Browser-Bildfolge eingefuehrt.
- Anzeigeoptionen robust und ohne Schemaaenderung im lokalen Browser gespeichert. Konten, Sessions, Erinnerungen und Modellkonfiguration bleiben unberuehrt.
- Reale Systemstatusmatrix auf Basis von `api/health.php` mit Datenbank-, Schema-, Ollama- und Runtime-Pruefung aufgenommen.
- Angemeldetes Konto und Berechtigungslevel aus der bestehenden isolierten CoreUI-Authentifizierung in den Einstellungen sichtbar gemacht.
- Protokollansicht um Studio-Zuordnung, Copyright und direkten Wechsel zu den Einstellungen erweitert.
- Compose- und Native-Preflight pruefen nun Studio-Logo, Einstellungsseite, gemeinsame Preferences-Logik und die Auslieferung der Einstellungsroute.
- Mobile Viewport-Regel aus dem v1.1.1.96-Patch unveraendert beibehalten; Game, Homepage, Repack-Versionierung und auslaufende STU Console bleiben unberuehrt.

## v0.2.6-alpha - 19.08.2026

- Live-Browser nach echten Aktionen auf kurze Frame-Bursts umgestellt. SearXNG und Abschluss erhalten bis zu zwei Frames; Navigation, Klick, Eingabe und Scrollen standardmaessig drei Frames im Abstand von 240 Millisekunden.
- Frame-Anzahl und Abstand ueber `STU_EMBER_BROWSE_FRAME_BURST` sowie `STU_EMBER_BROWSE_FRAME_INTERVAL_MS` konfigurierbar gemacht und auf sichere Bereiche von 1 bis 5 Frames beziehungsweise 120 bis 800 Millisekunden begrenzt.
- Ghost-Cursor in jedem Browserframe sichtbar gehalten. Bis zum ersten echten Klick startet er an einer festen neutralen Position, danach behaelt er die letzte reale Playwright-Position bei.
- Klickposition und Klickimpuls voneinander getrennt. Die neue Datenbankspalte `cursor_click` kennzeichnet nur den Frame eines bestaetigten Playwright-Klicks; normale Folgeframes loesen keinen falschen Klickring mehr aus.
- Ghost-Cursor kleiner, kontrastreicher und auf hellen wie dunklen Webseiten lesbar gestaltet. Den grossen dekorativen Eckwinkel des Browserpanels reduziert, damit er nicht mehr wie ein uebergrosser Cursor wirkt.
- Client-Puffer auf 16 Browserframes erweitert und die Wiedergabezeit fuer normale Frames sowie echte Klickframes getrennt abgestimmt.
- Bestehende Installationen werden durch den Browse-Worker automatisch migriert. Eine vorhandene `api/config.local.php` muss fuer die neuen Standardwerte nicht ersetzt werden.
- Eigene CoreUI-Versionierung auf `0.2.6-alpha` angehoben. Repack, Game, Homepage und die auslaufende STU Console bleiben unberuehrt.

## v0.2.5-alpha - 19.08.2026

- Das fruehere aufklappbare `IM NETZ`-Schrittprotokoll vollstaendig durch eine eigenstaendige Live-Browser-Agentenansicht ersetzt. Das Browserbild ist nun selbst die Rechercheflaeche und nicht mehr Inhalt einer Textbox.
- Lokale SearXNG-Treffer als sichere, escaped Browserseite gerendert. Dadurch existiert bereits ein sichtbarer Browser-Frame, wenn Ember die Recherche direkt anhand der Treffer abschliesst.
- Playwright-Viewport nach Start, Navigation, Klick, Eingabe, Scrollen und Abschluss als komprimierte JPEG-Frames gespeichert und ueber den authentifizierten Browse-SSE-Endpunkt ausgeliefert.
- Private Tabelle `stu_ember_browse_frames` mit Jobbindung, URL, Statuslabel, Viewport und Cursorposition hinzugefuegt. Der Worker migriert bestehende Installationen selbststaendig und entfernt alte Frames standardmaessig nach 24 Stunden.
- Ghost-Cursor mit echten Bounding-Box-Koordinaten des angeklickten Playwright-Elements sowie sichtbarem Klickimpuls umgesetzt. Ohne echten Klick wird keine Bewegung erfunden.
- Schrittstream auf eine kurze Statuszeile reduziert. Technische Protokolle verdecken das Browserbild nicht mehr.
- Thinking-Bleed im direkten CoreUI-SSE-Pfad geschlossen. Der Streaming-Abschluss und der synchrone Werkzeugpfad verwenden jetzt dieselbe Schranke vor Datenbank und Client.
- Interne Gemma-Labels wie `Response Draft`, `Alternative`, `Final decision`, `Selected response` und `Checking constraints` werden erkannt. Eine explizite Nutzerantwort kann aus dem getrennten Denkblock gerettet werden; reines Meta bleibt leer und loest den sauberen Fallback aus.
- Client puffert fuehrende Werkzeug- und Meta-Fragmente, damit interne Labels auch waehrend des Tokenstreams nicht kurz in der Antwortblase aufblitzen.
- Eigenen Thinking-Sanitizer-Selftest mit dem gemeldeten Leak-Muster aufgenommen. Compose- und Native-Preflight pruefen zusaetzlich die private Frame-Tabelle.
- Eigene CoreUI-Versionierung auf `0.2.5-alpha` angehoben. Repack, Game, Homepage und die auslaufende STU Console bleiben unberuehrt.

## v0.2.4-alpha - 19.08.2026

- Ursache des vermeintlichen Playwright-Fehlers isoliert: SearXNG und Browsernavigation liefen korrekt, der nachfolgende Ollama-Aktionsaufruf besass jedoch eine fest verdrahtete Lesefrist von nur 90 Sekunden.
- Browse-Aktionsfrist auf einen konfigurierbaren Standard von 420 Sekunden angehoben. Die getrennte Report-Frist betraegt ebenfalls 420 Sekunden.
- Verbindungsaufbau und Antwortlesen fuer Ollama getrennt behandelt. Ein nicht erreichbarer Dienst faellt schnell auf, waehrend eine laufende lokale Inferenz genuegend Zeit behaelt.
- Optionalen, begrenzten Retry fuer unmittelbare Ollama-Verbindungsfehler vorbereitet. Read-Timeouts werden bewusst nicht automatisch wiederholt, damit ein serverseitig noch rechnender Request nicht dupliziert wird.
- Browse-Protokoll unterscheidet nun eindeutig zwischen Ollama-Read-Timeout, Ollama-Verbindungsfehler und Playwright-Fehler. Der bisher irrefuehrende Text `agent-call fehlgeschlagen` wurde ersetzt.
- SearXNG-Treffer bleiben bei einem Modellfehler als belastbarer Fallback erhalten; der Browse-Job und die Ember-Antwort gehen dadurch nicht verloren.
- Idle-Frist des Live-Browse-Fensters auf 480 Sekunden und Sicherheits-Cap des Browse-SSE-Endpunkts auf 900 Sekunden angehoben.
- Neue Laufzeitwerte in Standardkonfiguration, Beispielkonfiguration sowie Compose- und Native-Installer aufgenommen. Bestehende `config.local.php`-Dateien muessen fuer den neuen Standard nicht ersetzt werden.
- Eigene CoreUI-Versionierung auf `0.2.4-alpha` angehoben. Die Repack-Version und die auslaufende STU Console bleiben unberuehrt.

## v0.2.3-alpha - 19.08.2026

- `poppler-utils` in das isolierte PHP-Image aufgenommen. `pdftotext`, `pdfinfo` und `pdftoppm` stehen damit auch im Docker-Compose-Betrieb wirklich zur Verfuegung.
- Zweistufige PDF-Erkennung umgesetzt: Dokumente mit Textebene werden direkt extrahiert, Scan-PDFs ohne Textebene werden als repraesentative Seitenbilder an Gemmas Vision-Pfad uebergeben.
- Eigene beschreibbare Laufzeitablage `var/pdf_pages` als PHP-Volume hinzugefuegt. Der schreibgeschuetzte Projekt-Mount bleibt unveraendert.
- PDF-Stichprobe auf standardmaessig vier gleichmaessig verteilte Seiten und maximal 1024 Pixel je Seite begrenzt. Seitenverhaeltnis und Seitenreihenfolge bleiben erhalten.
- SSE-Routing um PDF-Anhaenge erweitert, damit ein moeglicher Scan-Fallback nicht im reinen Text-Streaming-Pfad ohne Bild-Payload landet.
- Vision-Dateiaufloesung auf zwei kanonische CoreUI-Runtime-Wurzeln begrenzt: `var/ember_frames` und `var/pdf_pages`.
- Prompt-Injection-Schutz fuer Scan-PDFs eingebaut. Sichtbare Dokumentanweisungen werden als Inhalt, nicht als Systemanweisung behandelt.
- Halluzinationsschutz fuer PDFs eingebaut: Wenn weder Textebene noch Seitenbilder lesbar sind, wird keine modellgenerierte Inhaltsbeschreibung angefordert.
- Healthcheck, Compose-Preflight und Native-Preflight um den PDF-Seitenordner erweitert.
- Preflight um einen vollstaendigen PHP-Syntaxcheck fuer API-, Skript- und Tool-Dateien erweitert, damit auch ein reines Update ohne erneuten Installer fehlerhafte PHP-Dateien sofort stoppt.
- Eigenen PDF-Selftest hinzugefuegt. Er validiert eine bekannte Textebene und ein bildbasiertes Scan-PDF samt Rasterung, Skalierung, Metadaten und Cleanup.
- Anwendungssperren fuer den Parallelbetrieb dokumentiert: Game und CoreUI verwenden getrennte Datenbanken und getrennte Lock-Namen. Sie blockieren sich auf Anwendungsebene nicht.
- Rein lesenden `ollama-parallel-report.sh` fuer RAM-, VRAM-, Modell- und Dienstdiagnose hinzugefuegt. Der CoreUI-Installer veraendert den gemeinsam genutzten Ollama-Dienst weiterhin nicht.
- Eigene CoreUI-Versionierung auf `0.2.3-alpha` angehoben. Die Repack-Version bleibt davon unberuehrt.

## v0.2.2-alpha - 19.08.2026

- Kritischen Compose-Fehler im Video-Vision-Pfad behoben: `var/ember_frames` wird jetzt als eigenes beschreibbares PHP-Volume eingebunden.
- Frame-Ausgabe von der allgemeinen Python-Arbeitsablage getrennt und ueber `STU_EMBER_VIDEO_FRAME_DIR` eindeutig konfigurierbar gemacht.
- Video-Skalierung auf eine echte Begrenzung von Breite und Hoehe umgestellt. Das Seitenverhaeltnis bleibt unveraendert und kleine Frames werden nicht unnoetig hochskaliert.
- FFmpeg waehlt den ersten Videostream explizit aus, ignoriert Audio im Vision-Pfad und schreibt JPEG-Frames mit kontrollierter Qualitaet.
- Diagnoseprotokoll um reale Frame-Abmessungen, Zeitstempel und klare Fehlercodes fuer fehlende oder nicht beschreibbare Laufzeitpfade erweitert.
- Halluzinationsschutz eingebaut: Wenn kein Frame erzeugt oder eingebettet werden konnte, wird keine modellgenerierte Videobeschreibung mehr angefordert.
- Vision-Prompt gegen kontextgetriebene Sci-Fi-, Androiden-, HUD- und Hologramm-Erfindungen gehaertet.
- Verdeckten 480-Sekunden-cURL-Deckel auf die bereits fuer PHP-FPM und Nginx vorgesehenen 900 Sekunden angehoben. Die konfigurierte Modellfrist von standardmaessig 600 Sekunden greift damit auch bei mehreren Video-Frames wirklich.
- Healthcheck und Preflight pruefen nun den Frame-Ordner. Ein eigener Video-Selftest erzeugt im PHP-Container einen synthetischen Clip und validiert `ffmpeg`, `ffprobe`, Frame-Extraktion, Skalierung und Cleanup gemeinsam.
- Veraltetes Top-Level-Feld `version` aus `compose.yaml` entfernt, damit aktuelle Docker-Compose-Versionen keine kosmetische Warnung mehr ausgeben.
- Eigene CoreUI-Versionierung auf `0.2.2-alpha` angehoben. Die Repack-Version bleibt davon unberuehrt.

## v0.2.1-alpha - 19.08.2026

- Standardinstallation auf einen eigenen Docker-Compose-Stack umgestellt.
- Eigenen Nginx-Container auf Loopback-Port `9080` eingefuehrt. Der Host-Webserver und vorhandene VHosts bleiben unveraendert.
- Eigenen PHP-FPM-Container auf Loopback-Port `19073` eingefuehrt. Es werden keine globalen PHP-Werte mehr geschrieben und kein Host-PHP-FPM neu gestartet.
- Eigene MariaDB-Instanz auf Loopback-Port `13306` eingefuehrt. Bestehende STU-Datenbanken und Datenbanknutzer werden nicht verwendet oder veraendert.
- Eigene SearXNG-Instanz auf Loopback-Port `18889` eingefuehrt, damit eine vorhandene STU-Suche auf `8888` parallel weiterlaufen kann.
- Den Granian-Server im offiziellen SearXNG-Container explizit an `127.0.0.1:18889` gebunden, statt dessen internen Standardport `8080` vorauszusetzen.
- Browse-Worker in einen eigenen Playwright-Container verschoben. Es wird kein Host-systemd-Dienst mehr benoetigt.
- CoreUI-Modellname von `ember-core:latest` auf `ember-coreui:latest` getrennt und durch eine lokale Eigentumsmarkierung gegen versehentliche Uebernahme fremder Modelle abgesichert.
- MariaDB-Advisory-Locks auf einen konfigurierbaren CoreUI-Namespace umgestellt. Die bisherigen globalen Namen `ember_global_ollama` und `ember_self_xp` werden im Standardbetrieb nicht mehr verwendet.
- Vor jeder Erstinstallation Kollisionstests fuer Ports und Compose-Projektidentitaet eingefuehrt.
- Webzugriff standardmaessig auf `127.0.0.1` begrenzt und einen SSH-Tunnel als sicheren Testweg dokumentiert.
- Eigenen Compose-Preflight sowie `scripts/stack.sh` fuer Status, Logs, Neustart und Stop hinzugefuegt.
- Fast-Poll-Fallback an die ausloesende CoreUI-Sitzung gebunden, damit ein SSE-Rueckfall den Sitzungszeiger korrekt aktualisiert und nicht in einer Poll-Schleife haengen bleibt.
- Den frueheren Host-Installer als expliziten `install-native.sh` fuer dedizierte Einzelserver erhalten und mit einer Pflichtbestaetigung gesichert.
- Entfernen der Nginx-Default-Site im Native-Installer standardmaessig deaktiviert.
- Getrennte CoreUI-Versionierung fortgefuehrt. Repack-Changelogs werden von diesem eigenstaendigen Alpha-Release nicht veraendert.

Bewusste gemeinsame Ressource:

- Der lokale Ollama-Dienst wird weiterhin gemeinsam genutzt, damit vorhandene Gemma-4-Modelldaten nicht dupliziert werden. CoreUI verwendet einen eigenen Modellnamen und eigene Daten, gleichzeitige Generierungen koennen aber dieselbe CPU, GPU und denselben RAM beanspruchen.

## v0.2.0-alpha - 19.08.2026

- Eigenstaendiges Distributionspaket ohne Includes, Assets, Proxies oder Laufzeitpfade aus dem Repack aufgebaut.
- Alle Frontend-API-Aufrufe auf die lokale, deployment-relative Schnittstelle `./api` umgestellt.
- Lokale Rajdhani- und Share-Tech-Mono-Fonts eingebunden.
- Eigenes MariaDB-Basisschema fuer Accounts, Charaktere, Sessions, Chat, Medien, RAG-Lite, LZG, Browse-Jobs und Reputation hinzugefuegt.
- CLI-Bootstrap fuer Ember, Admin-Account, Charakter und `stu_kv` hinzugefuegt.
- Standalone-Konfiguration ueber `api/config.local.php` oder `COREUI_*`-Umgebungsvariablen eingefuehrt.
- Eigenen Health-Endpunkt und authentifiziertes CRUD-API fuer `ember_memories` hinzugefuegt.
- Gemma-4-Modelfile mit CoreUI-Werkzeugprotokoll und empfohlenen Sampling-Werten hinzugefuegt.
- Lokales SearXNG-Template mit aktivierter JSON-Search-API hinzugefuegt.
- Asynchronen Playwright-Browse-Worker als eigenen systemd-Dienst paketiert.
- SSH-Installer fuer Debian und Ubuntu sowie einen wiederholbaren Preflight-Test hinzugefuegt.
- Nginx-Allowlist fuer ausschliesslich oeffentliche API-Endpunkte und Schutz interner Projektordner hinzugefuegt.
- Vision-Dateiaufloesung auf das lokale Projektverzeichnis `assets/chat_media` begrenzt.
- Getrennte CoreUI-Versionierung gegenueber der Repack-Versionierung verbindlich dokumentiert.

Bekannte Alpha-Grenze:

- Die visuelle CRUD-LZG-Oberflaeche und weitere spezialisierte Werkzeug-Panels folgen in einem spaeteren Frontend-Schritt. Das lokale CRUD-API ist bereits enthalten.

## v0.1.1-alpha - 19.08.2026

- Kritischer Mobile-Viewport-Fix fuer Ansichten bis 899 px integriert.
- `html`, `body`, `.app-body` und `.app-shell` hart auf die Viewportbreite begrenzt.
- Horizontalen Seiten-Scroll im mobilen Layout gesperrt.
- Drawer auf 290 px festgelegt und im Ruhezustand vollstaendig links ausserhalb des Viewports positioniert.
- Drawer-Einflug auf 380 ms mit eigener Holo-Schattenkante abgestimmt.
- Overlay-Ebene direkt unterhalb des Drawers abgesichert.
- `.drawer.open` ist mobil die einzige Quelle fuer den sichtbaren Drawer-Zustand. Der Checkbox-State steuert dort nicht die Sichtbarkeit, da `#sidebarControl` im aktuellen HTML standardmaessig aktiviert ist.

## v0.1.0-alpha - 19.08.2026

- Eigenstaendiges HTML-Grundgeruest fuer Ember CoreUI erstellt.
- Helles Ayal'ren-HUD, SVG-Runenmatrix, Sidebar, Ticker, Chat-Feed und Composer gestaltet.
- Bestehende DOM-Kopplung fuer die aktuelle Console-JavaScript-Laufzeit erhalten.
- Grundlayout in feste Navigations-, Kopf-, Verlaufs- und Eingabebereiche gegliedert, damit die spaetere funktionale Erweiterung ohne Austausch des visuellen Kerns moeglich bleibt.
- Eigene Ayal'ren-Runenmatrix als CoreUI-Kernsymbol eingefuehrt und vom spaeter getrennt gefuehrten Studio-Branding abgegrenzt.
- Chatblasen, Statusanzeige, Nachrichtenzeiten, Eingabefeld und primaere Aktionen als konsistentes HUD-System angelegt.
- Erste Desktop-Proportionen, Typografie, Farbvariablen, Raster, Schatten und Bewegungsmuster in einer gemeinsamen CoreUI-Styleschicht gebuendelt.
- Game, Homepage und bestehende Repack-Oberflaechen nicht ersetzt; der Stand war ein eigenstaendiger Alpha-Frontendkern.
