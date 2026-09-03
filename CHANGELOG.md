# Project STΛRLIɢHT: Ember CoreUI

Ember CoreUI wird bis zur finalen Aufnahme in den STU-Repack eigenstaendig versioniert.

## v0.5.2-alpha - 02.09.2026

- Produktiv gemeldeten Stillstand bei exakten Rechenauftraegen wie `123456789 * 987654321` behoben. Der bisherige Pfad konnte erst eine Streaming-Generierung, danach den synchronen Modellfallback, den Python-Worker und einen weiteren Modellaufruf starten.
- Deterministischen lokalen Schnellpfad fuer genau einen binaeren Ganzzahlausdruck eingefuehrt. Addition, Subtraktion und Multiplikation mit `*`, `x` oder `×` werden ohne Ollama und ohne Python abgeschlossen.
- Eigene dezimale String-Arithmetik fuer vorzeichenbehaftete Operanden bis 512 Ziffern implementiert. Das Ergebnis bleibt unabhaengig von der PHP-Integerbreite exakt.
- Schnellpfad bewusst auf eindeutige Aufgaben begrenzt. Anhaenge, Bilder, Dezimalzahlen, Divisionen, Potenzen, Kettenrechnungen, mehrere numerische Vorgaben und verlangte Rechenwege bleiben im normalen Modell- und Werkzeugpfad.
- Privaten SSE-Pfad vor dem Promptbau um die lokale Berechnung erweitert. Das Ergebnis wird weiterhin als regulaere Ember-Nachricht mit derselben `reply_to_id` gespeichert und ueber das normale `done`-Ereignis abgeschlossen.
- Fuer eine lokal abgeschlossene Rechnung wird kein Console-AFK gesetzt. Modell- und Werkzeuglaeufe behalten ihr bisheriges AFK-Verhalten.
- Nachgelagerte Memory-, Reflexions- und Reputationsauswertung fuer den lokalen Rechenschritt unterbunden. Das Ergebnis bleibt als Chatnachricht gespeichert, startet aber keinen verborgenen weiteren Modellaufruf und wird nicht als Langzeitgedaechtnis behandelt.
- Gemeinsamen synchronen Antwortgenerator ebenfalls abgesichert, damit globale oder nicht streamende Aufrufer denselben exakten Abschluss erhalten.
- Metadaten des lokalen Abschlusses explizit gesetzt, damit kein alter Guardrail-, Thinking- oder Timeoutzustand in den neuen Turn hineinragt.
- Reply-Pipeline-Selftest um den gemeldeten Multiplikationsfall, grosse Zahlen, Vorzeichen, Uebertraege, negative Ergebnisse und strikte Ablehnungsfaelle erweitert.
- Nach dem ersten `0.5.2-alpha`-Paket gemeldeten Preflight-Fehler korrigiert: Ein Punkt oder Komma direkt nach dem zweiten Operanden gilt nun als Satzzeichen und nicht mehr pauschal als Dezimaltrennzeichen.
- Schnellpfad auf ASCII-Ganzzahlen begrenzt und ungueltige Operanden werden sicher abgelehnt. Unicode-Ziffern koennen dadurch nicht mehr als falsches Ergebnis `0` enden.
- Routing gegen Bereiche, Kapitel- und Episodenangaben, Hexadezimalwerte sowie deutsche und englische Erklaerungswuensche gehaertet.
- Beschriftete Bereiche besitzen Vorrang vor Rechenwoertern, waehrend eindeutige Ergebnisformulierungen wie `Was ergibt 12*12?` und `Nenne das Ergebnis von 12*12.` lokal bleiben.
- Lokale Rechenschritte loeschen keinen fremden Console-AFK-Status mehr. Der synchrone Pfad prueft die Rechnung vor dem globalen Ollama-Lock.
- Regressionstest um Satzzeichen, Unicode-Ziffern, `0x10`, Bereichsangaben, englische Erklaerungswuensche, AFK-Eigentum und lockfreien Synchronabschluss erweitert.
- Keine neue Datenbankmigration. Migration 009, Konten, Sitzungen, Nachrichten, Reaktionen, Revisionen, Anhaenge und lokale Konfiguration bleiben unveraendert erhalten.
- Version, Cache-Buster, Setup, README, Changelog-Archiv, Branding-Test, Preflight und Uebergabeprotokoll auf `0.5.2-alpha` aktualisiert.

## v0.5.1-alpha - 01.09.2026

- Praktischen Produktivtest von `0.5.0-alpha` ausgewertet: Markdown, Codebloecke, Kopieren, Neugenerierung, Antwortdetails und alle bestehenden Werkzeuge funktionierten; persistentes Feedback und der gewuenschte Bearbeitungsfluss benoetigten Korrekturen.
- Feedbackspeicherung fuer bereits bestehende STU-Reaktionstabellen gehaertet. Migration `009_message_editing` erweitert den privaten Kanalwert auf `console`, normalisiert die Emoji-Spalte auf `utf8mb4` und CoreUI speichert neue Bewertungen als altkompatible ASCII-Tokens.
- Fehlerhafte Feedbackaufrufe bleiben nicht mehr als stilles rotes Aufblitzen stehen, sondern zeigen zusaetzlich einen konkreten Aktionsfehler an.
- Alternative Ember-Antworten werden nach `reply_to_id` gruppiert. Eine Vorher-/Naechster-Navigation mit Zaehler zeigt alle dauerhaft gespeicherten Varianten auch nach einem Seitenreload.
- Fortsetzungen bleiben an ihre konkrete Quellvariante gebunden und werden nur zusammen mit dieser Variante angezeigt.
- Bearbeiten einer Benutzernachricht arbeitet nun wirklich auf derselben Nachrichten-ID und derselben Blase. Der bisherige "als neue Nachricht"-Entwurfspfad wurde entfernt.
- Migration 009 fuehrt eine kontogebundene Revisionshistorie und den Generierungsmodus `edit` ein. Der Verlauf vor der bearbeiteten Nachricht bleibt Modellkontext; spaetere, auf dem alten Text beruhende Nachrichten werden aus dem aktiven Zweig ausgeblendet und bleiben im Kontoexport nachvollziehbar.
- Modellkontext und SSE-Idempotenz ignorieren ausgeblendete Nachrichten konsequent.
- Der Nachrichteneditor bleibt waehrend einer laufenden Ember-Antwort benutzbar. Abgesendete Folgetexte erscheinen als wartende Benutzer-Turns und werden nach Abschluss der aktuellen Antwort automatisch gesendet.
- Vorgemerkte Texte koennen vor dem Senden wieder entfernt werden. Der vorhandene lokale Sende-Cooldown wird beim automatischen Weiterreichen eingehalten.
- Fortsetzungsaktion verstaendlicher als Aktion fuer unvollstaendige Antworten beschriftet.
- Kontoexport um Nachrichtenrevisionen und ausgeblendete Zweige erweitert, ohne Thinking-Inhalte oder temporaere Anforderungstoken offenzulegen.
- Healthcheck, Compose- und Native-Preflight, Installer und Nachrichtenaktionen-Selftest auf Migration 009, Revisionsspeicherung, ASCII-Feedback, echte Bearbeitung und Variantenanzeige erweitert.
- Version, Cache-Buster, Setup, README, Changelog-Archiv, Branding-Test und Uebergabeprotokoll auf `0.5.1-alpha` aktualisiert.

## v0.5.0-alpha - 01.09.2026

- Sicheren lokalen Markdown-Renderer fuer Ueberschriften, Hervorhebungen, Listen, Zitate, Links, Tabellen, Inline-Code und eingezaeunte Codebloecke eingefuehrt.
- Rohes HTML bleibt immer Text. Nur HTTP-, HTTPS- und Mail-Links werden klickbar. Der Renderer verwendet weder `innerHTML` noch `eval`, Remote-CDNs oder ausfuehrbare Modellausgaben.
- Codebloecke um Sprachkennung, lokale Syntaxfarben, horizontales Scrollen und eine eigene Kopieraktion erweitert.
- Gemeinsame Aktionsleiste unter Benutzer- und Ember-Nachrichten eingefuehrt. Benutzernachrichten koennen kopiert oder als neuer Entwurf in den Editor uebernommen werden, ohne den gespeicherten Verlauf zu veraendern.
- Ember-Antworten koennen kopiert, positiv oder negativ bewertet, als Alternative neu erzeugt, fortgesetzt und mit technischen Antwortdetails angezeigt werden.
- Feedback serverseitig an authentifiziertes Konto, private Sitzung und konkrete Ember-Nachricht gebunden. Positive und negative Bewertung schliessen sich je Benutzer gegenseitig aus.
- Alternative Antworten behalten die bisherige Antwort und dieselbe `reply_to_id`. Antworten desselben Turns werden beim Neuaufbau gezielt aus dem Modellkontext ausgeschlossen, damit die Alternative nicht nur die alte Fassung kopiert.
- Fortsetzungen als getrennte Ember-Nachrichten gespeichert und weiterhin mit dem urspruenglichen Benutzer-Turn verknuepft.
- Migration `008_message_actions` fuer kurzlebige, kontogebundene Neugenerierungsanforderungen eingefuehrt. Eine feste Nachrichten-ID-Grenze ordnet nach SSE-Unterbrechungen nur wirklich spaeter entstandene Antworten zu, ohne temporaere Anforderungstoken im Kontoexport offenzulegen.
- Gleichzeitige Nachrichtenaktionen derselben Sitzung werden durch eine Datenbanksperre serialisiert. Eine Sitzung kann nicht endgueltig geloescht werden, solange eine Alternative oder Fortsetzung noch laeuft.
- Neue API `console_actions.php` mit CSRF-Schutz, Benutzer- und Sitzungspruefung sowie getrenntem Store-Modul fuer Feedback und Generierungsanforderungen eingefuehrt.
- Live-SSE-Antworten und geladener Verlauf verwenden nun denselben Markdown-, Codeblock- und Aktionsleistenpfad.
- Browser-Recherche innerhalb einer Nachrichtenaktion wird ueber ihre exakte Job-ID mit der Anforderung verbunden. Wiederaufnahme, Workerfehler und fehlendes Ergebnis werden dadurch eindeutig abgeschlossen.
- Kontoexport um eigene Bewertungen und Generierungshistorie erweitert. Interne Thinking-Inhalte und temporaere Anforderungstoken bleiben ausgeschlossen.
- Healthcheck, Compose- und Native-Preflight, Installer und Selbsttests auf Migration 008 und die neuen Endpunkte erweitert.
- Neuer Markdown-Selftest prueft gefaehrliche Linkprotokolle, HTML-Text, Tabellen und Codebloecke. Neuer Nachrichtenaktionen-Selftest prueft Feedback, Turnbindung, Isolation und sichere Renderer-Verdrahtung.
- Version, Cache-Buster, Setup, README, Changelog-Archiv, Branding-Test und Uebergabeprotokoll auf `0.5.0-alpha` aktualisiert.
- Audioeingabe, nativer Modell-Audiotransport und gesprochene TTS-Ausgabe bleiben bewusst dem getrennten Audioausbau vorbehalten.

## v0.4.5-alpha - 31.08.2026

- Navigationsschleife zwischen Einstellungen und Admin Core behoben. `CORE CHANNEL` verwendet kein `history.back()` mehr, sondern oeffnet die App deterministisch.
- Rueckweg aus Admin Core ersetzt den Admin-Verlaufseintrag. Die Abfolge Core Channel, Einstellungen, Admin Core, Einstellungen und Core Channel bleibt dadurch bedienbar.
- TXT-Decodierung vor die Unicode-Bereinigung verschoben. Ungueltig decodierte Bytes koennen den Inhalt nicht mehr vorzeitig in einen leeren String verwandeln.
- UTF-8 mit BOM, UTF-16 LE/BE mit und ohne BOM, UTF-32 mit BOM sowie Windows-1252 als reale TXT-Varianten ergaenzt.
- Binaerpruefung fuer falsch benannte Textdateien eingebaut. Auffaellige C0-Steuerzeichen werden nicht als vermeintlicher Text an das Modell weitergereicht.
- Anhang-Pipeline-Selftest um deutsche Umlaute, Windows-1252 und mehrere UTF-Codierungen erweitert.
- Chat-Profilbilder von einem nicht ueberpruefbaren CSS-Hintergrund auf echte `<img>`-Elemente mit Lade- und Fehlerbehandlung umgestellt.
- Initialen bleiben sichtbar, bis das Benutzer- oder CoreAI-Bild erfolgreich geladen wurde. Fehlende oder unlesbare Dateien erzeugen wieder einen eindeutigen Fallback.
- Profil-API und Medienendpunkt verwenden nun dieselbe serverseitige Dateiaufloesung. Nicht vorhandene Datenbankdateien werden nicht mehr als gueltige Avatar-URL ausgegeben.
- Profil/RAG-Selftest erzeugt ein echtes PNG, prueft dessen kontogebundene URL und die lesbare Datei fuer den Medienendpunkt.
- Reply-Pipeline-Selftest definiert vor dem Laden der Installation keine Produktionskonstanten mehr. Die PHP-Warnung zu bereits definiertem `STU_EMBER_NUM_PREDICT` und verwandten Werten entfaellt.
- Reine Budgetpruefung als eigene Funktion aus dem Laufzeitpfad getrennt, damit historische 7200-Zeichen-Konfigurationen ohne Eingriff in `config.local.php` getestet werden koennen.
- Neuer Frontend-Regressions-Selftest fuer Navigation, Bildladezustand, Avatar-Fallback und gemeinsame Medienaufloesung in Compose und Native ergaenzt.
- Freigegebenes Original-Logo unter neuem Assetnamen eingebunden. Start, Login, Einstellungen und Admin Core verwenden dieselbe Datei ohne die fehlerhaften schwarzen Konturen.
- Logo-Selftest an den exakten SHA-256-Fingerabdruck des freigegebenen Originals gebunden. Die alte fehlerhafte Assetdatei wurde entfernt.
- Version, Cache-Buster, Setup, Kontoexport, README, Changelog-Archiv und Branding-Test auf `0.4.5-alpha` aktualisiert.
- Neues Uebergabeprotokoll dokumentiert Quellstand, Tests, Installationspfade, bekannte Grenzen und die geplanten Funktionsstufen ab `0.5.x`.
- Keine neue Datenbankmigration. Konten, Sitzungen, Nachrichten, Uploads, private RAG-Quellen, Profilbilder und lokale Konfiguration bleiben beim Update erhalten.

## v0.4.4-alpha - 31.08.2026

- Offizielles Repository auf die Starlight-Unit-Studio-Organisation umgestellt. Installer, README, Metadaten und Releasepfade verwenden nun `Starlight-Unit-Studio/coreui`.
- TTY-Abbruch der Blitzinstallation nach dem erfolgreichen Image-Build behoben.
- Die beiden temporaeren `docker compose run`-Aufrufe fuer PHP-Lint und Account-Bootstrap verwenden nun explizit `-T`.
- Bestehende und nicht-interaktive Installationen koennen dadurch ohne Pseudo-TTY weiterlaufen.
- Schnellinstallation in der README in einen getrennten Download- und Ausfuehrungsschritt aufgeteilt. Die eigentliche Installation laeuft damit direkt am echten Terminal.
- Irrefuehrende Root-Fehlermeldung in `setup.sh` korrigiert. Sie verweist nicht mehr auf den alten Pipe-Aufruf.
- Releasepaar, Paketwurzel und Pflichtdateien werden weiterhin vor jeder Aenderung an `/opt/ember-coreui` geprueft.
- Alle Upload-, RAG-Lite-, Python-, Datenschutz- und Installerkorrekturen aus `0.4.3-alpha` bleiben unveraendert enthalten.
- Game, Homepage, Repack, KeyHelp, Apache, Host-PHP und Game-Datenbank bleiben unangetastet.

## v0.4.3-alpha - 30.08.2026

- Blitzinstallationsbefehl wieder direkt am Anfang der README aufgenommen.
- `setup.sh` vollstaendig neu aufgebaut. ZIP und SHA-256-Datei werden in ein eindeutiges temporaeres Verzeichnis geladen, gemeinsam geprueft und erst danach nach `/opt/ember-coreui` uebertragen.
- Veraltete Festverdrahtung auf `0.3.2-alpha` entfernt. Der Installer prueft Paketordner, Versionsdatei und alle zwingenden Skripte, bevor eine bestehende Installation veraendert wird.
- Updatepfad des Blitzinstallers erhaelt `api/config.local.php`, `var`, Logs, Uploads, Chatmedien und Profilbilder. Erstinstallationen koennen weiter interaktiv oder ueber sichere Umgebungsvariablen laufen.
- Release- und Setup-Pruefung lehnt Pakete mit den historischen privaten Manuskriptdateien ab.
- Die zwei versehentlich mitgefuehrten privaten Studio-Dokumente aus dem Releasebaum entfernt. Sie werden nicht mehr installiert oder automatisch in RAG-Lite importiert.
- Migration `007_remove_private_studio_lore` ergaenzt. Sie entfernt ausschliesslich die historischen Quellen `bibel_v10_4` und `kompendium_v6` aus bereits vorhandenen globalen Ember-CoreUI-Chunks.
- Voreingestellte lokale Pfade zu privaten Studio-Manuskripten aus `api/config.php` entfernt. Der generische CLI-Importer bleibt fuer rechtmaessig bereitgestellte Betreiberquellen verfuegbar.
- Private Console-Nachrichten adressieren Ember nun serverseitig. Das Frontend haengt kein kuenstliches `@Ember` mehr an, wodurch reine Datei-Turns nicht mehr als einzelnes `@` im Modellprompt erscheinen.
- Gespeicherte 1:n-Anhangzuordnungen werden im SSE-Turn in stabiler Reihenfolge rekonstruiert und in interne Dateimarker umgewandelt, bevor die gemeinsame Anhangsauswertung laeuft.
- Neuer End-to-End-Anhangtest legt echte TXT-, Python-, DOCX- und Text-PDF-Dateien an, bindet sie transaktional an eine Nachricht, rekonstruiert sie wie der SSE-Pfad und prueft ihren Inhalt im Modellprompt.
- Maximal zehn Dateien pro Nachricht bleiben unveraendert. Rohe `[file:...]`-Marker und das historische Adresspraefix duerfen den Modellprompt nach dem Test nicht erreichen.
- Gemeinsamen `rag_lite.php`-Kern fuer Extraktion, UTF-8-Normalisierung, ueberschneidendes Chunking, Suchbegriffe und Ranking eingefuehrt.
- Privates RAG-Lite nutzt diesen gemeinsamen Kern, behaelt aber strikt benutzergebundene Quellen- und Chunk-Tabellen. Eine globale Tabelle ohne `user_id` wird nicht fuer private Daten wiederverwendet.
- Beide Modell-Promptpfade begrenzen privates Benutzerwissen hart auf `channel === 'console'`. Globale Chatantworten koennen keine privaten RAG-Chunks mehr erhalten.
- DOCX-Ueberschriften bleiben bei der Extraktion als Strukturhinweise erhalten. Volltextsuche verwendet fehlertolerantes OR-Ranking statt einer zu strikten Pflichtwort-Abfrage.
- Generische Fragen wie "Fass meine hochgeladene Datei zusammen" koennen kontrolliert auf die zuletzt aktualisierte eigene Wissensquelle zurueckfallen.
- Private RAG-Uploads zusaetzlich fuer Python-, CSV-, JSON-, XML-, YAML-, INI-, PHP-, JavaScript-, HTML-, CSS- und SQL-Textdateien freigegeben.
- Fehlenden Python-Queue-Worker in den Compose-Stack aufgenommen. Der vertrauenswuerdige Worker besitzt den Docker-Socket, der generierte Modellcode laeuft dagegen ausschliesslich in einem wegwerfbaren Container ohne Projekt-, Konfigurations- oder Datenbank-Mount.
- Eigenes `docker/pyworker`-Image und Native-systemd-Dienst ergaenzt. Das eigentliche Sandbox-Image `ember-py:1` wird beim ersten Start gebaut.
- Echter Python-Preflight reiht einen Job ein, wartet auf den Worker und prueft die Ausgabe aus dem isolierten Sandbox-Container.
- Compose-, Native-, Health-, Installer- und Preflight-Pfade auf Version `0.4.3-alpha`, Migration 007, Python-Worker und neue Pipeline-Selftests aktualisiert.
- Veraltete Zukunftsaussage zur STU Console aus der README entfernt. Die interne STU Console wurde bereits mit Game Alpha `v1.1.1.98` am 20.08.2026 entfernt; Ember CoreUI ist ihre eigenstaendige Nachfolge- und Referenzoberflaeche und ersetzt nicht den Global-Chat.
- Produktive Dokumentationsadresse bleibt `https://coreui.starlight-unit.de`.
- Game, Homepage, KeyHelp, Apache, STU-Repack, Game-Datenbank und andere Projektpfade bleiben unangetastet.

## v0.4.2-alpha - 29.08.2026

- Neue `Starlight Unit Studios Ember CoreUI Community Source Lizenz 1.0` eingefuehrt. Die deutsche Fassung in `LICENSE.de.md` ist rechtlich massgeblich; `LICENSE.md` enthaelt die englische Uebersetzung.
- Lizenzmodell als Source Available und nicht als OSI-zertifiziertes Open Source gekennzeichnet.
- Projekt-eigener Code bleibt fuer private, gemeinschaftliche und interne Nutzung kostenlos. Auch interne Unternehmensnutzung ist ohne Lizenzgebuehr erlaubt, solange alle Bedingungen und das Branding eingehalten werden.
- Nutzergrenze vollstaendig verworfen. Es gibt weder eine 50-Nutzer-Ausnahme noch irgendeine andere Schwelle fuer Branding-Aenderungen.
- Entfernung, Ersetzung, Verschleierung oder irrefuehrende Abschwaechung des Ember-CoreUI- und Starlight-Unit-Studios-Brandings fuer jede Bereitstellung untersagt.
- Verbindliche Namensregel aufgenommen: Der Produktname lautet immer `Ember CoreUI`; die alleinstehende Produktbezeichnung `CoreUI` ist fuer dieses Projekt unzulaessig.
- Technische Kennungen wie `coreui`, `ember-coreui`, `COREUI_*`, `/opt/ember-coreui`, `X-CoreUI-CSRF`, Repository-Slugs und Domainbestandteile als reine Kompatibilitaetsbezeichner dokumentiert.
- Kostenpflichtiges White-Label-, Enterprise-, Abonnement-, Spenden-, Werbe- und Supportmodell fuer das offizielle Ember-CoreUI-Projekt ausgeschlossen.
- Monetarisierten Weiterverkauf als SaaS oder Managed Service durch Dritte untersagt. Kostenlose Selbstbereitstellung und interne Unternehmensnutzung bleiben erlaubt.
- Schriftliche Branding-Ausnahmen nur als individuelle Ermessensentscheidung vorgesehen. Die aktuelle Community-Richtlinie verlangt dafuer keine Ember-CoreUI-Lizenzgebuehr und begruendet keinen Anspruch auf Genehmigung.
- Bei veraenderter Weitergabe muessen der vollstaendige entsprechende Quellcode, dieselbe Lizenz, Herkunftshinweise und das geschuetzte Branding erhalten bleiben. Rein interne Aenderungen muessen nicht veroeffentlicht werden.
- Ausdruecklich festgehalten, dass die Lizenz keine Nutzerzaehlung, Registrierung, Lizenztelemetrie oder Phone-home-Funktion verlangt.
- `LICENSE_HISTORY.md` ergaenzt. Fruehere Kopien behalten exakt die mit ihnen verbreiteten Bedingungen; Kopien mit ausdruecklichem CC-BY-NC-SA-4.0-Hinweis behalten diese Gewaehrung. Die neue Lizenz beginnt ohne rueckwirkende Neulizenzierung mit `0.4.2-alpha`.
- `TRADEMARKS.md`, `COMMUNITY_POLICY.md` und `NOTICE.md` fuer Markenfuehrung, kostenlose Projektpolitik, Herkunft und Drittkomponenten ergaenzt.
- Unabhaengigkeitserklaerung aufgenommen: Ember CoreUI ist kein Fork des getrennten Admin-Template- und UI-Komponentenprojekts unter `coreui.io`, enthaelt keine Abhaengigkeit davon und ist damit nicht verbunden.
- Sichtbare Produkttexte, Fehlermeldungen, Installer-Ausgaben, Quellkommentare und historische Dokumentation auf die vollstaendige Bezeichnung `Ember CoreUI` bereinigt. Protokollrelevante technische Header bleiben aus Kompatibilitaetsgruenden unveraendert.
- Oeffentliche Startseite um eindeutige Autoren-, Beschreibungs-, Open-Graph-, Canonical- und strukturierte `SoftwareApplication`-Metadaten ergaenzt. Darin werden Ember CoreUI, Starlight Unit Studios und der kostenlose Zugang maschinenlesbar zugeordnet. Authentifizierte Seiten tragen `noindex,nofollow`.
- Keine neue Datenbankmigration, keine Bezahlschranke, keine Lizenzschluessel und keine Aenderung an Benutzer-, Chat-, RAG-, Profil- oder Sitzungsdaten.
- Alle Funktionen und Sicherheitskorrekturen aus `0.4.1-alpha` unveraendert uebernommen.

## v0.4.1-alpha - 29.08.2026

- Kontosicherheitsbereich in den Benutzereinstellungen ergaenzt. Er trennt Geraete-Anmeldungen klar von den bereits vorhandenen Chat-Sitzungen.
- Sicheren Passwortwechsel mit aktuellem Passwort, Mindestlaenge 12, Bestaetigung, Rate-Limit, Passwort-Hashing und Audit eingefuehrt.
- Nach einem Passwortwechsel werden alle vorhandenen Login-Sitzungen widerrufen und ausschliesslich fuer das aktuelle Geraet ein frisches Token ausgegeben.
- Neue SQL-Tabelle `stu_auth_sessions` fuer widerrufbare Anmeldungen ergaenzt. In der Datenbank liegt nur SHA-256 des zufaelligen Tokens, niemals das Roh-Token.
- Bestehende authentifizierte PHP-Sitzungen werden beim ersten Aufruf transparent in die SQL-Schicht uebernommen.
- Aktive Anmeldungen zeigen Geraetetyp, Browser, letzte Aktivitaet und Ablaufzeit. Token und Token-Hash werden nie an den Browser gesendet.
- Einzelwiderruf einer fremden eigenen Anmeldung sowie Sammelwiderruf aller anderen Geraete umgesetzt. Die aktuelle Sitzung kann nicht versehentlich ueber den Einzelwiderruf entfernt werden.
- SQL-Anmeldungen laufen nach 30 Tagen Inaktivitaet ab und werden bei Aktivitaet hoechstens einmal pro Minute aktualisiert.
- Passwort-Reset widerruft nun ebenfalls alle aktiven Login-Sitzungen.
- Privaten JSON-Kontoexport ergaenzt. Enthalten sind Konto-Metadaten, Profil, Avatar-Daten, KI-Einstellungen, eigene Charaktere, eigene Memories, private RAG-Quellen und Chunks, Chat-Sitzungen, Nachrichten sowie Anhangmetadaten.
- Passwort-Hashes, Reset-Codes, Login-Token, Token-Hashes, interne Thinking-Inhalte, private Serverpfade und hochgeladene Dokumentbinaerdaten sind vom Export ausgeschlossen.
- Jede Exportabfrage ist serverseitig an die authentifizierte `user_id` gebunden. Globaler Studio-Kanon wird nicht als persoenlicher Besitz exportiert.
- Freie Texteingabe fuer Ollama-Modellnamen durch eine echte Auswahlliste der lokal installierten Modelle ersetzt.
- Der Modellkatalog wird nur von der betreiberkonfigurierten `STU_EMBER_OLLAMA_URL` abgeleitet. Benutzer koennen weder Host noch Protokoll beeinflussen; HTTP-Redirects sind deaktiviert.
- Eine Modellwahl wird unmittelbar vor dem Speichern erneut gegen `/api/tags` geprueft. Nicht installierte oder ungueltige Modellnamen werden abgelehnt.
- Serverstandard bleibt als explizite Option erhalten. Bereits gespeicherte, inzwischen entfernte Modelle werden sichtbar als nicht mehr installiert markiert.
- Nginx-Allowlists fuer Compose und Native um `account_security.php`, `account_export.php` und `models.php` erweitert.
- Migration `006_account_security` ergaenzt `password_changed_at`, `last_login_at` und die neue Auth-Sitzungstabelle mit Benutzer-Fremdschluessel und Aktivindex.
- Healthcheck, Compose-Preflight, Native-Preflight und beide Installer auf Migration 006 und die neuen Endpunkte erweitert.
- Neuer Account-Security-Selftest prueft Tokenabschirmung, CSRF- und Passwortgrenzen, Exportausschluesse, Modellvalidierung sowie SQL-Listen- und Widerrufslogik in einer zurueckgerollten Testtransaktion.
- PHP-Grenze `max_file_uploads` fuer Compose und Native auf zehn angeglichen. Die bestehende produktive Anhanglogik bleibt weiterhin bei maximal zehn Dateien je Nachricht.
- Versionsparameter, Healthcheck, README, TXT-Changelog-Archiv und Uebergabeprotokoll auf `0.4.1-alpha` aktualisiert.
- Produktive Adresse bleibt `https://coreui.starlight-unit.de`. Die fruehere Adresse `webui.starlight-unit.de` ist nur noch historischer Kontext.
- Game, Homepage, KeyHelp-VHosts, Apache, STU-Repack, Game-Datenbank, bestehende Ember CoreUI-Konfiguration und persistente Nutzerdaten bleiben unveraendert.

## v0.4.0-alpha - 29.08.2026

- Bisher teilweise lokale Einstellungsseite zu einem echten kontobezogenen Konfigurationsbereich ausgebaut. Profil, KI-Laufzeitwerte, Memory und privates Wissen werden serverseitig dem authentifizierten Benutzer zugeordnet.
- Eigenes Ember CoreUI-Profil je Benutzer mit sichtbarem Anzeigenamen eingefuehrt. Die Login-E-Mail, numerische Benutzer-ID und stabile interne Operator-ID bleiben bei einer Namensaenderung unveraendert.
- Frei waehlbaren CoreAI-Anzeigenamen je Konto ergaenzt. Die sichtbare Bezeichnung wird in Chatblasen, Denkstatus, Tippanzeige und Eingabeplatzhalter verwendet, ohne Embers technische Identitaet oder Sicherheitsregeln umzuschreiben.
- Zwei getrennte private Avatar-Slots aufgenommen: Benutzer und CoreAI. Beide koennen in den Einstellungen hochgeladen, ersetzt und geloescht werden.
- Avatarannahme auf PNG und JPEG bis 4 MiB begrenzt. Abmessungen, Pixelmenge, MIME-Typ und echte Dekodierbarkeit werden serverseitig geprueft.
- Hochgeladene Bilder werden zentral quadratisch zugeschnitten, auf maximal 512 mal 512 Pixel skaliert, neu als PNG kodiert und ohne fremde Bildmetadaten gespeichert.
- Avatar-Dateien aus dem statischen Webroot herausgehalten. `profile_media.php` liefert nur den Avatar des angemeldeten Kontos mit privatem Cache-Verhalten aus.
- Zufallsbasierte Medien-ID als exakten Cache-Buster verwendet. Auch zwei unmittelbar aufeinanderfolgende Avatar-Uploads zeigen sicher den neuesten Stand.
- Neue Profilendpunkte `profile.php` und `profile_media.php` samt gemeinsamem `profile_store.php` hinzugefuegt. Schreiboperationen verlangen Authentifizierung und das technische `X-CoreUI-CSRF`-Token.
- Automatische Altaccount-Provisionierung eingebaut. Bereits vorhandene Benutzer erhalten beim naechsten Login ein Profil und, falls bisher keiner existiert, einen stabilen privaten Operator.
- Gemeinsames transaktionales Account-Provisioning fuer Registrierung und Admin Core eingefuehrt. Neue Benutzer starten nicht mehr ohne Charakter- und Profildaten.
- Admin Core um eine echte Benutzeranlage mit Anzeigename, Login-E-Mail, Startpasswort und Rolle erweitert. System-Admin kann Level 1 bis 4, Admin kann nur niedrigere Rollen Level 2 bis 4 vergeben.
- Startpasswoerter aus dem Admin Core auf mindestens 12 Zeichen begrenzt. Passwortwerte werden weder im Audit noch in API-Antworten gespeichert oder ausgegeben.
- Benutzerliste um Profilname und internen Operator erweitert. Suche findet jetzt Login-E-Mail, sichtbaren Namen und Operatornamen.
- Einstellungsseite um einen optionalen lokalen Ollama-Modelltag erweitert. Der gespeicherte `model_override` wird nun im gemeinsamen Chatpfad tatsaechlich ausgewertet; leer verwendet weiterhin den Serverstandard.
- System-Prompt, Memory-Schalter, Memory-Limit, Antwortbudget und Temperatur bleiben pro Benutzer gespeichert und werden gemeinsam mit Profil und Modellwahl geladen.
- Kontobezogenen Modell-Thinking-Schalter ergaenzt. Ember CoreUI sendet den offiziellen Ollama-Parameter `think` bei Hauptantworten als `true` oder `false`, statt nur die Anzeige zu verstecken.
- Bei deaktiviertem Thinking werden keine Denkstatus-Ereignisse und kein Denkstatus in neuen Verlaufszeilen gespeichert. Die bestehende Schutzschranke bleibt auch bei aktiviertem Thinking bestehen: Rohe Modellgedanken verlassen den Server nicht.
- Composer auf bis zu zehn Dateien pro Nachricht erweitert. Mehrfachauswahl, sequentieller Upload mit Fortschritt, einzelne Entfernung vor dem Senden und Wiederherstellung der Auswahl bei einem Sendefehler sind enthalten.
- Vor dem Senden entfernte Uploads werden ueber einen CSRF-geschuetzten Eigentuemercall geloescht, sofern keine Nachricht sie inzwischen referenziert. Zeilensperren schliessen das Rennen zwischen Entfernen und gleichzeitigem Nachrichten-INSERT.
- Neue Zuordnungstabelle `stu_console_message_attachments` speichert Anhaenge geordnet und benutzergebunden. `file_uuid` bleibt nur fuer Altclients und bereits vorhandene Einzeleintraege erhalten.
- Nachrichtenverlauf und optimistische Clientzeilen liefern jetzt ein `attachments`-Array. Alte Clients koennen weiterhin das erste Element ueber das kompatible Feld `attachment` lesen.
- Serverseitige Eigentumspruefung fuer jede Anhang-UUID eingebaut. Fremde, fehlende oder mehr als zehn UUIDs werden vor dem Nachrichten-INSERT abgelehnt; Nachricht und Zuordnungen werden gemeinsam transaktional gespeichert.
- Gemeinsame Anhangsauswertung auf bis zu zehn Text-, Dokument- oder Mediendateien erweitert. Das Textbudget wird kontrolliert aufgeteilt und Vision-Eingaben werden auf hoechstens 16 Bilder, Frames oder PDF-Seiten begrenzt.
- Sitzungsloeschung um Mehrfachanhaenge erweitert. Zuordnungen werden per Fremdschluessel entfernt und Mediendateien nur geloescht, wenn weder alte `file_uuid`- noch neue 1:n-Referenzen verbleiben.
- Externe OpenAI-kompatible Provider bleiben als deaktivierter Adapter vorbereitet. Der lokale Gemma-4- und Ollama-Pfad bleibt Referenzbetrieb; API-Schluessel gelangen weiterhin nie in Browser oder Datenbankeinstellungen.
- Privates RAG-Lite direkt in den Benutzereinstellungen eingefuehrt. TXT-, Markdown-, Text-PDF- und DOCX-Dateien koennen hochgeladen, gelesen, gechunkt, gelistet und vollstaendig geloescht werden.
- Textnormalisierung, kontrollierte Unicode-Konvertierung und Chunking mit standardmaessig 1400 Zeichen sowie 180 Zeichen Ueberlappung umgesetzt.
- PDF-Import verwendet lokal `pdftotext` mit begrenzter Laufzeit. Scan-PDFs ohne Textebene werden eindeutig abgelehnt, statt einen erfundenen Dokumentinhalt zu erzeugen.
- DOCX-Import liest ausschliesslich `word/document.xml`, deaktiviert externe XML-Netzwerkzugriffe und begrenzt XML-Groesse sowie Absatzanzahl.
- Private Wissensquellen standardmaessig auf 20 MiB je Datei, 40 Quellen, 5.000.000 Zeichen und 1200 Chunks je Einzeldokument begrenzt. Betreiber koennen die Hauptquoten ueber isolierte App-Settings steuern.
- Kontospezifischen MariaDB-Lock um Quotenpruefung und Persistenz gelegt. Gleichzeitige Uploads desselben Benutzers koennen die Quelle- oder Zeichenquote nicht gemeinsam umgehen.
- Originaldateien unter `var/knowledge_uploads` gespeichert und nie direkt durch Nginx ausgeliefert. Quellenmetadaten und Chunks liegen in eigenen Ember CoreUI-Tabellen.
- RAG-Suche filtert in Volltext- und LIKE-Fallback immer nach `user_id` und Quelle. Ein Benutzer kann keine Chunks eines anderen Kontos finden oder loeschen.
- Privates Benutzerwissen klar vom globalen Studio-Kanon in `ember_knowledge_chunks` getrennt. Uploads veraendern weder Bibel- noch Kompendium-Importe.
- Relevante private Chunks in den Modellprompt aufgenommen und ausdruecklich als nicht vertrauenswuerdige Daten markiert. Dokumentanweisungen erhalten keine Systemprioritaet.
- Quellenloeschung entfernt Metadaten und Chunks in einer SQL-Transaktion und danach die gespeicherte Originaldatei. Die Oberflaeche aktualisiert Zaehler und Liste aus der Serverantwort.
- Migration `004_profiles_knowledge` hinzugefuegt. Sie erstellt Profile, Profilmedien, private Wissensquellen und private Chunks mit Benutzer- und Quellindizes, Fremdschluesseln und Volltextindex.
- Migration `005_thinking_attachments` hinzugefuegt. Sie ergaenzt den Thinking-Schalter, erstellt die 1:n-Anhangzuordnung und uebernimmt bestehende `file_uuid`-Eintraege verlustfrei.
- App-Defaults fuer RAG-Quellenzahl, Dateigroesse und Gesamtzeichen in Migration 004 aufgenommen, ohne vorhandene Betreiberwerte zu ueberschreiben.
- Neue private Speicherpfade `var/profile_media` und `var/knowledge_uploads` als eigene beschreibbare PHP-Volumes eingebunden. Der Webcontainer besitzt keinen direkten Mount fuer diese Dateien.
- `stack.sh` legt die neuen privaten Verzeichnisse bei `up`, `start` und `restart` mit passenden Containerrechten an. Das funktioniert auch beim normalen Update, obwohl `var/` absichtlich vom `rsync` ausgeschlossen bleibt.
- Compose- und Native-Nginx-Allowlists um Profil-, Medien- und Knowledge-Endpunkte erweitert. Unauthentifizierte Routenpruefungen erwarten bewusst HTTP 401.
- Healthcheck um Migrationen 004 und 005, alle neuen Tabellen, die Thinking-Spalte und beide privaten Schreibpfade erweitert.
- Eigenen transaktionalen Profil- und Knowledge-Selftest hinzugefuegt. Er prueft Account-Provisioning, Namen, Chunker, Volltextabruf und besonders die Trennung zweier Benutzer und rollt seine Testdaten vollstaendig zurueck.
- Reply-Pipeline- und Sitzungs-Selftests um Thinking-Wahl, Zehn-Dateien-Grenze, persistente Zuordnung sowie deren transaktionale Loeschung erweitert. Der Preflight prueft zusaetzlich PHP-Erweiterungen, Routen, Storage-Rechte und die vollstaendige Verdrahtung der 0.4.0-Funktionen.
- Nativen Fallback-Installer auf Migration 004 und die neuen privaten Speicherpfade angeglichen. Standard-Compose- und Native-Neuinstallation besitzen damit denselben Schema- und Funktionsstand.
- README, TXT-Changelog-Archiv und Uebergabeprotokoll auf `0.4.0-alpha` sowie die produktive Adresse `https://coreui.starlight-unit.de` aktualisiert.
- Echte Sitzungen, archivgeschuetzte endgueltige Loeschung, Thinking-Schranke, lange Ollama-Antworten, Browse-Frames, Game, Homepage, KeyHelp, Apache, Host-PHP, Repack-Pfade und bestehende STU-Datenbank bleiben unveraendert erhalten.

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
- EventSource uebergibt nur noch die authentifizierte Turn-ID. Lange oder vertrauliche Nachrichten werden aus der Ember CoreUI-Datenbank geladen und landen weder in der URL noch in Proxy-Zugriffslogs.
- Privaten History-Kontext auf bis zu 1200 Zeichen je Nachricht erweitert. Die engen Global-Chat-Grenzen bleiben fuer den Game-Pfad unveraendert.
- Migration `002_coreui_management` erweitert `stu_chat_messages.message` auf `MEDIUMTEXT` und fuegt Benutzer-KI-Profile sowie Admin-Audit hinzu.
- Servergespeichertes KI-Profil je Benutzer hinzugefuegt: System-Prompt, Memory-Schalter, Memory-Limit, Antwortbudget und Temperatur.
- Memory-Verwaltung in den Ember CoreUI-Einstellungen ergaenzt. Benutzer koennen eigene sichtbare Fakten anlegen und loeschen; globale Fakten bleiben geschuetzt.
- Externen Provider als deaktivierte, serverseitig vorbereitete Schnittstelle aufgenommen. API-Schluessel werden nie im Browser gespeichert; lokales Gemma 4 ueber Ollama bleibt der Referenzbetrieb.
- Eigenstaendiges Admin Core mit Uebersicht, Benutzerrechten, Sperren, Memory CRUD, Lore-Suche, Runtime-Schaltern, Registrierung, Wartungsmodus, Logs und Audit hinzugefuegt.
- Werkzeugpfade fuer schnelle Websuche, tiefe Browser-Recherche und isoliertes Python koennen zentral im Admin Core aktiviert oder deaktiviert werden.
- Berechtigungsstufen und aktive Sperren werden bei jedem authentifizierten Request aus der Ember CoreUI-Datenbank aktualisiert und wirken auf bestehende Sitzungen.
- Gehaertete Nginx-Allowlists fuer `settings.html`, `admin/index.html`, `user_settings.php` und `admin.php` in Compose und Native aktualisiert.
- Transparentes Studio-Logo unter `starlight_unit_studios_logo_transparent_v030.png` neu referenziert. Der eindeutige Dateiname umgeht den bis zu sieben Tage alten Browsercache der frueheren schwarzen Datei.
- Preflight um Migration-002-Pruefung, Admin-Route, Reply-Pipeline-Selftest, 15 Thinking-Regressionen und Logo-Cache-Buster-Test erweitert.
- Game, Homepage, KeyHelp, Apache, Host-PHP, Repack-Pfade und bestehende STU-Datenbank bleiben unveraendert.

## v0.2.9-alpha - 19.08.2026

- Rohe `message.thinking`-Tokens aus dem Console-SSE entfernt. Der Server akkumuliert sie nur noch fluechtig fuer die abschliessende Antwortbereinigung und sendet stattdessen feste, inhaltsfreie Fortschrittsstufen.
- Denkpanel zum sicheren `DENKSTATUS` umgebaut. Promptteile, Identitaetsdaten, Systemanweisungen, Entwuerfe und Selbstkorrekturen werden weder live noch aus der Historie in den DOM geschrieben.
- Persistenzschranke in `ember_insert()` ergaenzt: Neue Console-Antworten speichern ausschliesslich einen festen Prozessstatus, niemals den rohen Thinking-Block.
- Console-Fetch fail-closed gehaertet. Historische `thinking_content`-Werte werden serverseitig durch einen sicheren Abschlussstatus ersetzt, bevor die JSON-Antwort erzeugt wird.
- Einmalige Datenbankbereinigung mit `sudo ./scripts/stack.sh scrub-thinking` aufgenommen. Sie ersetzt alte Raw-Thinking-Inhalte nur in der isolierten Ember CoreUI-Datenbank.
- Antwort-Sanitizer um die gemeldeten Muster `Actually, the prompt says`, `The user is asking` und `Let's try` erweitert. Ein fuehrendes, geschlossenes Antwortzitat endet jetzt vor nachfolgender interner Analyse.
- Thinking-Selftest auf zwoelf Faelle erweitert und um Quellcode-Grenzen fuer SSE und Persistenz ergaenzt.
- Studio-Original als echte RGBA-PNG freigestellt. Der schwarze Rechteckhintergrund wurde entfernt; die vorhandenen feinen dunklen Konturen an Wortmarke und Symbolen bleiben erhalten.
- Splash, Login und Einstellungen auf das transparente PNG-Asset umgestellt. Schwarzer Panelhintergrund, Polygonbeschnitt und Rahmen des Logo-Wrappers wurden entfernt.
- Eigene Ember CoreUI-Versionierung auf `0.2.9-alpha` angehoben. Game, Homepage, Repack-Versionierung und auslaufende STU Console bleiben unberuehrt.

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

- Das bereitgestellte originale Starlight Unit Studios-Logo unveraendert als lokales Ember CoreUI-Asset aufgenommen und das fruehere Game-Logo auf Splash und Login ersetzt.
- Die animierte Ren-Sternenmatrix als eigenstaendiges Ember CoreUI-Kernsymbol in der Sidebar erhalten und klar von der Studio-Herausgebermarke getrennt.
- Sichtbare Copyright-Zuordnung fuer Splash, Login, Sidebar, Protokolle und den neuen Info-Bereich ergaenzt.
- Sidebar um den eigenstaendigen Bereich `Einstellungen` erweitert; Einsatz-Protokolle und Abmeldung bleiben direkt erreichbar.
- Funktionale Einstellungsseite fuer reduzierte Bewegung, kompakten Datenstrom, Thinking-Panel-Standard, Desktop-Telemetrie und Live-Browser-Bildfolge eingefuehrt.
- Anzeigeoptionen robust und ohne Schemaaenderung im lokalen Browser gespeichert. Konten, Sessions, Erinnerungen und Modellkonfiguration bleiben unberuehrt.
- Reale Systemstatusmatrix auf Basis von `api/health.php` mit Datenbank-, Schema-, Ollama- und Runtime-Pruefung aufgenommen.
- Angemeldetes Konto und Berechtigungslevel aus der bestehenden isolierten Ember CoreUI-Authentifizierung in den Einstellungen sichtbar gemacht.
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
- Eigene Ember CoreUI-Versionierung auf `0.2.6-alpha` angehoben. Repack, Game, Homepage und die auslaufende STU Console bleiben unberuehrt.

## v0.2.5-alpha - 19.08.2026

- Das fruehere aufklappbare `IM NETZ`-Schrittprotokoll vollstaendig durch eine eigenstaendige Live-Browser-Agentenansicht ersetzt. Das Browserbild ist nun selbst die Rechercheflaeche und nicht mehr Inhalt einer Textbox.
- Lokale SearXNG-Treffer als sichere, escaped Browserseite gerendert. Dadurch existiert bereits ein sichtbarer Browser-Frame, wenn Ember die Recherche direkt anhand der Treffer abschliesst.
- Playwright-Viewport nach Start, Navigation, Klick, Eingabe, Scrollen und Abschluss als komprimierte JPEG-Frames gespeichert und ueber den authentifizierten Browse-SSE-Endpunkt ausgeliefert.
- Private Tabelle `stu_ember_browse_frames` mit Jobbindung, URL, Statuslabel, Viewport und Cursorposition hinzugefuegt. Der Worker migriert bestehende Installationen selbststaendig und entfernt alte Frames standardmaessig nach 24 Stunden.
- Ghost-Cursor mit echten Bounding-Box-Koordinaten des angeklickten Playwright-Elements sowie sichtbarem Klickimpuls umgesetzt. Ohne echten Klick wird keine Bewegung erfunden.
- Schrittstream auf eine kurze Statuszeile reduziert. Technische Protokolle verdecken das Browserbild nicht mehr.
- Thinking-Bleed im direkten Ember CoreUI-SSE-Pfad geschlossen. Der Streaming-Abschluss und der synchrone Werkzeugpfad verwenden jetzt dieselbe Schranke vor Datenbank und Client.
- Interne Gemma-Labels wie `Response Draft`, `Alternative`, `Final decision`, `Selected response` und `Checking constraints` werden erkannt. Eine explizite Nutzerantwort kann aus dem getrennten Denkblock gerettet werden; reines Meta bleibt leer und loest den sauberen Fallback aus.
- Client puffert fuehrende Werkzeug- und Meta-Fragmente, damit interne Labels auch waehrend des Tokenstreams nicht kurz in der Antwortblase aufblitzen.
- Eigenen Thinking-Sanitizer-Selftest mit dem gemeldeten Leak-Muster aufgenommen. Compose- und Native-Preflight pruefen zusaetzlich die private Frame-Tabelle.
- Eigene Ember CoreUI-Versionierung auf `0.2.5-alpha` angehoben. Repack, Game, Homepage und die auslaufende STU Console bleiben unberuehrt.

## v0.2.4-alpha - 19.08.2026

- Ursache des vermeintlichen Playwright-Fehlers isoliert: SearXNG und Browsernavigation liefen korrekt, der nachfolgende Ollama-Aktionsaufruf besass jedoch eine fest verdrahtete Lesefrist von nur 90 Sekunden.
- Browse-Aktionsfrist auf einen konfigurierbaren Standard von 420 Sekunden angehoben. Die getrennte Report-Frist betraegt ebenfalls 420 Sekunden.
- Verbindungsaufbau und Antwortlesen fuer Ollama getrennt behandelt. Ein nicht erreichbarer Dienst faellt schnell auf, waehrend eine laufende lokale Inferenz genuegend Zeit behaelt.
- Optionalen, begrenzten Retry fuer unmittelbare Ollama-Verbindungsfehler vorbereitet. Read-Timeouts werden bewusst nicht automatisch wiederholt, damit ein serverseitig noch rechnender Request nicht dupliziert wird.
- Browse-Protokoll unterscheidet nun eindeutig zwischen Ollama-Read-Timeout, Ollama-Verbindungsfehler und Playwright-Fehler. Der bisher irrefuehrende Text `agent-call fehlgeschlagen` wurde ersetzt.
- SearXNG-Treffer bleiben bei einem Modellfehler als belastbarer Fallback erhalten; der Browse-Job und die Ember-Antwort gehen dadurch nicht verloren.
- Idle-Frist des Live-Browse-Fensters auf 480 Sekunden und Sicherheits-Cap des Browse-SSE-Endpunkts auf 900 Sekunden angehoben.
- Neue Laufzeitwerte in Standardkonfiguration, Beispielkonfiguration sowie Compose- und Native-Installer aufgenommen. Bestehende `config.local.php`-Dateien muessen fuer den neuen Standard nicht ersetzt werden.
- Eigene Ember CoreUI-Versionierung auf `0.2.4-alpha` angehoben. Die Repack-Version und die auslaufende STU Console bleiben unberuehrt.

## v0.2.3-alpha - 19.08.2026

- `poppler-utils` in das isolierte PHP-Image aufgenommen. `pdftotext`, `pdfinfo` und `pdftoppm` stehen damit auch im Docker-Compose-Betrieb wirklich zur Verfuegung.
- Zweistufige PDF-Erkennung umgesetzt: Dokumente mit Textebene werden direkt extrahiert, Scan-PDFs ohne Textebene werden als repraesentative Seitenbilder an Gemmas Vision-Pfad uebergeben.
- Eigene beschreibbare Laufzeitablage `var/pdf_pages` als PHP-Volume hinzugefuegt. Der schreibgeschuetzte Projekt-Mount bleibt unveraendert.
- PDF-Stichprobe auf standardmaessig vier gleichmaessig verteilte Seiten und maximal 1024 Pixel je Seite begrenzt. Seitenverhaeltnis und Seitenreihenfolge bleiben erhalten.
- SSE-Routing um PDF-Anhaenge erweitert, damit ein moeglicher Scan-Fallback nicht im reinen Text-Streaming-Pfad ohne Bild-Payload landet.
- Vision-Dateiaufloesung auf zwei kanonische Ember CoreUI-Runtime-Wurzeln begrenzt: `var/ember_frames` und `var/pdf_pages`.
- Prompt-Injection-Schutz fuer Scan-PDFs eingebaut. Sichtbare Dokumentanweisungen werden als Inhalt, nicht als Systemanweisung behandelt.
- Halluzinationsschutz fuer PDFs eingebaut: Wenn weder Textebene noch Seitenbilder lesbar sind, wird keine modellgenerierte Inhaltsbeschreibung angefordert.
- Healthcheck, Compose-Preflight und Native-Preflight um den PDF-Seitenordner erweitert.
- Preflight um einen vollstaendigen PHP-Syntaxcheck fuer API-, Skript- und Tool-Dateien erweitert, damit auch ein reines Update ohne erneuten Installer fehlerhafte PHP-Dateien sofort stoppt.
- Eigenen PDF-Selftest hinzugefuegt. Er validiert eine bekannte Textebene und ein bildbasiertes Scan-PDF samt Rasterung, Skalierung, Metadaten und Cleanup.
- Anwendungssperren fuer den Parallelbetrieb dokumentiert: Game und Ember CoreUI verwenden getrennte Datenbanken und getrennte Lock-Namen. Sie blockieren sich auf Anwendungsebene nicht.
- Rein lesenden `ollama-parallel-report.sh` fuer RAM-, VRAM-, Modell- und Dienstdiagnose hinzugefuegt. Der Ember CoreUI-Installer veraendert den gemeinsam genutzten Ollama-Dienst weiterhin nicht.
- Eigene Ember CoreUI-Versionierung auf `0.2.3-alpha` angehoben. Die Repack-Version bleibt davon unberuehrt.

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
- Eigene Ember CoreUI-Versionierung auf `0.2.2-alpha` angehoben. Die Repack-Version bleibt davon unberuehrt.

## v0.2.1-alpha - 19.08.2026

- Standardinstallation auf einen eigenen Docker-Compose-Stack umgestellt.
- Eigenen Nginx-Container auf Loopback-Port `9080` eingefuehrt. Der Host-Webserver und vorhandene VHosts bleiben unveraendert.
- Eigenen PHP-FPM-Container auf Loopback-Port `19073` eingefuehrt. Es werden keine globalen PHP-Werte mehr geschrieben und kein Host-PHP-FPM neu gestartet.
- Eigene MariaDB-Instanz auf Loopback-Port `13306` eingefuehrt. Bestehende STU-Datenbanken und Datenbanknutzer werden nicht verwendet oder veraendert.
- Eigene SearXNG-Instanz auf Loopback-Port `18889` eingefuehrt, damit eine vorhandene STU-Suche auf `8888` parallel weiterlaufen kann.
- Den Granian-Server im offiziellen SearXNG-Container explizit an `127.0.0.1:18889` gebunden, statt dessen internen Standardport `8080` vorauszusetzen.
- Browse-Worker in einen eigenen Playwright-Container verschoben. Es wird kein Host-systemd-Dienst mehr benoetigt.
- Ember CoreUI-Modellname von `ember-core:latest` auf `ember-coreui:latest` getrennt und durch eine lokale Eigentumsmarkierung gegen versehentliche Uebernahme fremder Modelle abgesichert.
- MariaDB-Advisory-Locks auf einen konfigurierbaren Ember CoreUI-Namespace umgestellt. Die bisherigen globalen Namen `ember_global_ollama` und `ember_self_xp` werden im Standardbetrieb nicht mehr verwendet.
- Vor jeder Erstinstallation Kollisionstests fuer Ports und Compose-Projektidentitaet eingefuehrt.
- Webzugriff standardmaessig auf `127.0.0.1` begrenzt und einen SSH-Tunnel als sicheren Testweg dokumentiert.
- Eigenen Compose-Preflight sowie `scripts/stack.sh` fuer Status, Logs, Neustart und Stop hinzugefuegt.
- Fast-Poll-Fallback an die ausloesende Ember CoreUI-Sitzung gebunden, damit ein SSE-Rueckfall den Sitzungszeiger korrekt aktualisiert und nicht in einer Poll-Schleife haengen bleibt.
- Den frueheren Host-Installer als expliziten `install-native.sh` fuer dedizierte Einzelserver erhalten und mit einer Pflichtbestaetigung gesichert.
- Entfernen der Nginx-Default-Site im Native-Installer standardmaessig deaktiviert.
- Getrennte Ember CoreUI-Versionierung fortgefuehrt. Repack-Changelogs werden von diesem eigenstaendigen Alpha-Release nicht veraendert.

Bewusste gemeinsame Ressource:

- Der lokale Ollama-Dienst wird weiterhin gemeinsam genutzt, damit vorhandene Gemma-4-Modelldaten nicht dupliziert werden. Ember CoreUI verwendet einen eigenen Modellnamen und eigene Daten, gleichzeitige Generierungen koennen aber dieselbe CPU, GPU und denselben RAM beanspruchen.

## v0.2.0-alpha - 19.08.2026

- Eigenstaendiges Distributionspaket ohne Includes, Assets, Proxies oder Laufzeitpfade aus dem Repack aufgebaut.
- Alle Frontend-API-Aufrufe auf die lokale, deployment-relative Schnittstelle `./api` umgestellt.
- Lokale Rajdhani- und Share-Tech-Mono-Fonts eingebunden.
- Eigenes MariaDB-Basisschema fuer Accounts, Charaktere, Sessions, Chat, Medien, RAG-Lite, LZG, Browse-Jobs und Reputation hinzugefuegt.
- CLI-Bootstrap fuer Ember, Admin-Account, Charakter und `stu_kv` hinzugefuegt.
- Standalone-Konfiguration ueber `api/config.local.php` oder `COREUI_*`-Umgebungsvariablen eingefuehrt.
- Eigenen Health-Endpunkt und authentifiziertes CRUD-API fuer `ember_memories` hinzugefuegt.
- Gemma-4-Modelfile mit Ember CoreUI-Werkzeugprotokoll und empfohlenen Sampling-Werten hinzugefuegt.
- Lokales SearXNG-Template mit aktivierter JSON-Search-API hinzugefuegt.
- Asynchronen Playwright-Browse-Worker als eigenen systemd-Dienst paketiert.
- SSH-Installer fuer Debian und Ubuntu sowie einen wiederholbaren Preflight-Test hinzugefuegt.
- Nginx-Allowlist fuer ausschliesslich oeffentliche API-Endpunkte und Schutz interner Projektordner hinzugefuegt.
- Vision-Dateiaufloesung auf das lokale Projektverzeichnis `assets/chat_media` begrenzt.
- Getrennte Ember CoreUI-Versionierung gegenueber der Repack-Versionierung verbindlich dokumentiert.

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
- Eigene Ayal'ren-Runenmatrix als Ember CoreUI-Kernsymbol eingefuehrt und vom spaeter getrennt gefuehrten Studio-Branding abgegrenzt.
- Chatblasen, Statusanzeige, Nachrichtenzeiten, Eingabefeld und primaere Aktionen als konsistentes HUD-System angelegt.
- Erste Desktop-Proportionen, Typografie, Farbvariablen, Raster, Schatten und Bewegungsmuster in einer gemeinsamen Ember CoreUI-Styleschicht gebuendelt.
- Game, Homepage und bestehende Repack-Oberflaechen nicht ersetzt; der Stand war ein eigenstaendiger Alpha-Frontendkern.
