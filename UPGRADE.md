# Upgrade-Hinweise für Betreiber

Was Betreiber bei einem Update tun müssen und was sich für Anwender spürbar ändert.
Die vollständige Liste der Änderungen steht in [CHANGELOG.md](CHANGELOG.md); hier steht nur, was
eine Handlung verlangt oder Verhalten bricht.

## Ablauf bei jedem Update

1. Backup ziehen (Admin > Backup oder `app:backup:create`), siehe
   [Disaster Recovery](docs/operations/DISASTER_RECOVERY.md).
2. Code bzw. Image aktualisieren ([Plesk](docs/deployment/DEPLOYMENT_PLESK.md),
   [Docker](docs/deployment/DOCKER_PRODUCTION.md)).
3. `/quick-fix` öffnen → „Ausstehende Migrationen anwenden“ ([Quick-Fix](docs/user-guide/QUICK_FIX.md)).
   Alle Migrationen sind wiederholbar: Bricht eine ab, den Knopf erneut drücken.
4. Die Punkte des Abschnitts zur neuen Version abarbeiten.

## Nach 3.16.1 (nächstes Release)

34 neue Migrationen, alle über `/quick-fix`. Vier davon verändern Daten: Freitext-Verantwortliche
werden Personen (`Version20261108100000_OwnerTextToPersons`, danach entfallen die Textspalten),
Nachweisdateien ziehen aus dem Web-Root (`Version20261108140000_ProtectUploads`);
`Version20261109020000_DropWorkflowStepOverlays` löscht die nie angewendeten Schritt-Overrides
(`workflow:*`/`step:*`-Zeilen in `lifecycle_config`);
`Version20261109010000_RequirementMaturityPerOrganisation` legt MRIS-Ist/Soll und das Soll aus
Branchen-Baselines je Organisation an die Erfüllungszeile. Jede Organisation behält den Wert, den sie
bisher sah; auf Mehr-Organisations-Instanzen sind die Werte danach getrennt. Die globale Anforderung
behält nur das TISAX-Katalog-Soll; dafür ist kein Klick nötig.

### Vor dem Update

- **nginx-Regel für alte Upload-Pfade** (Plesk mit „Statische Dateien über nginx“): Dokumente,
  Versionen und Schulungsmaterial liegen jetzt unter `var/uploads/` und sind nur noch über den
  Download mit Rechteprüfung erreichbar. Die Regel aus
  [DEPLOYMENT_PLESK.md, Schritt 2b](docs/deployment/DEPLOYMENT_PLESK.md) jetzt eintragen; sie schadet
  der laufenden Version nicht. Apache erledigt das über `public/.htaccess`.
- **Docker:** `var/` muss ein persistentes Volume sein (`docker-compose.prod.yml`). Ist nur
  `public/uploads` gemountet, verschiebt die Migration nichts und meldet das.
- **Cron:** Alle Hintergrundaufgaben laufen über den Symfony-Scheduler. Alte Einzelzeilen
  (`app:send-…`, `app:kpi-snapshot`, `app:backup:create` usw.) aus der Crontab löschen und durch die
  eine Zeile aus [HOSTING_WORKER.md](docs/user-guide/HOSTING_WORKER.md) ersetzen, sonst laufen
  Aufgaben doppelt.
- **Proxy:** Steht ein öffentlicher Proxy/CDN vor der Anwendung, dessen Adressen in
  `SYMFONY_TRUSTED_PROXIES` eintragen. Ohne Eintrag gelten nur Loopback und private Netze;
  Anmelde-Drosselung und Protokoll sehen sonst die Proxy-Adresse.
- **`DEFAULT_URI`** auf die öffentliche Adresse setzen (`https://isms.example.com`). Alle Links,
  die die Sitzung verlassen (Mails, Lieferantenfragebogen, Trust Center, QR-Labels), werden nur noch
  daraus gebaut, nie aus dem Host-Header. Ohne Shell: Admin > Überwachung > Health, Karte „Umgebung“,
  Knopf „… als öffentliche Adresse übernehmen“ (Super-Admin, schreibt `.env.local`).
- **`SYMFONY_TRUSTED_HOSTS`** (neu) mit den eigenen Hostnamen als verankerte Muster setzen, z. B.
  `'^isms\.example\.com$,^localhost$'` (localhost für Container-Healthchecks). Andere Host-Header
  bekommen 400. Der Health-Knopf kann den Wert mitsetzen.
- **Webhooks** brauchen ein HMAC-Secret: Kanäle ohne Secret senden nicht mehr (Fehler im
  Zustellprotokoll). Am Kanal unter Benachrichtigungen ein Secret hinterlegen.
- **Docker:** Der Worker im Image arbeitet jetzt auch die Queue `async` ab. Vorher blieben Mails
  liegen; nach dem Update gehen auch die aufgestauten raus.
- **Docker (`docker-compose.prod.yml`):** Was Setup-Assistent und Admin-Seiten in `.env.local`
  schreiben (öffentliche Adresse, erlaubte Hostnamen, Mailversand), liegt jetzt auf dem Volume
  (`var/.env.local`) und übersteht Updates. Bisher ging es beim Neuerstellen des Containers verloren:
  nach diesem Update unter Admin > Überwachung > Health prüfen und fehlende Werte einmal neu setzen.
  Alternativ `DEFAULT_URI` (und bei Bedarf `SYMFONY_TRUSTED_HOSTS`) in der `.env` neben der
  Compose-Datei setzen; der Start übernimmt sie, die Hostnamen werden sonst aus der Adresse abgeleitet.
  Logos und Profilbilder liegen jetzt im Volume `isms_uploads` (wer es schon per Override
  eingebunden hat, behält es). Ohne Volume gingen sie bei jedem Neuerstellen verloren; fehlt ein Bild,
  einmal neu hochladen.
- **Docker (`docker-compose.yml`):** startet jetzt MariaDB 11.4 statt PostgreSQL (auf dem die
  Migrationen ohnehin scheiterten). Variablen `DB_NAME`, `DB_USER`, `DB_PASSWORD` statt `POSTGRES_*`;
  pgAdmin ist entfallen.

### Einmal nach dem Update

- **Alle melden sich einmal neu an.** Das Sitzungscookie hat einen neuen Namen, und alle
  „Angemeldet bleiben“-Cookies sind ungültig. Wer TOTP nutzt, gibt in einer offenen Sitzung beim
  nächsten Klick den Code ein.
- **Leitung zuweisen:** Leitungsentscheidungen, Risikoakzeptanzen der Leitung und Workflow-Schritte
  mit `ROLE_TOP_MGMT` gehen nicht mehr ersatzweise an Admins. Ohne Person mit dieser Rolle zeigt das
  Entscheidungsregister „Rolle zuweisen“. `ROLE_ADMIN` erbt die Rolle nicht.
- **Risikokriterien billigen:** Die Risikoskala gilt je Organisation (Admin > Risikokriterien,
  Standard 20/12/6). Die automatische Risikoakzeptanz pausiert, bis die Leitung die Kriterien
  gebilligt hat.
- **Mapping-Hub → „Vorschläge importieren“** (bei mehreren Organisationen nur der Super-Admin): lädt
  die neuen Grundschutz++-Paare. Framework laden und Starterpaket sind bei mehreren Organisationen
  ebenfalls dem Super-Admin vorbehalten; Manager sehen einen Hinweis.
- **Cloud-Lieferanten kennzeichnen:** Beim Lieferanten „Cloud-Dienst“ ankreuzen; die Prüfung zu
  ISO 27001 A.5.23 zählt nur so markierte Lieferanten.
- **Personen prüfen:** Einen Freitext-Verantwortlichen ohne passenden Benutzer oder Person legt die
  Migration als neue Person an. Die Personenliste auf Dubletten durchsehen.

### Geänderte Schnittstellen (BREAKING)

- **REST-API und MCP:** Die alten Freitextfelder für Verantwortliche gibt es nicht mehr; Clients
  schreiben die Benutzer-/Personen-Referenz. Schreibzugriff verlangt dieselbe Rolle wie das
  Formular, Felder ausgeschalteter Module werden ignoriert. Melde-, Abschluss- und
  Freigabezeitpunkte sowie `implementationStatus`/Umsetzungsgrad von Controls sind über API und MCP
  nicht schreibbar (Formular, SoA und Massenimport bleiben).
- **API-Tokens** gelten nur auf `/api` und `/mcp`, nie als Sitzung. Schreibende Bearer-Aufrufe
  außerhalb von `/mcp` antworten 405. Ein Token schreibt nur mit „Assistent darf schreiben“, und
  der KI-Assistent schreibt nur, wenn die Organisation es erlaubt
  ([KI-Assistent](docs/user-guide/KI_ASSISTENT_MCP.md)). 20 Fehlversuche in 10 Minuten → 429.
  Tokens laufen höchstens 365 Tage; Tokens ohne Ablauf enden ein Jahr nach Erstellung. Eine
  Rollenänderung widerruft die Tokens der Person. Neue Tokens nur nach voller Anmeldung (nicht über
  „Angemeldet bleiben“).
- **REST-API und MCP, Datenminimierung (S24):** `?pagination=false` gilt nicht mehr — API-Clients
  blättern (`hydra:view` → `next`, höchstens 100 je Seite mit `itemsPerPage`). Zutrittslog
  (`/api/physical_access_logs`) und Krypto-Operationen (`/api/cryptographic_operations`) lesen nur
  Admins; der KI-Assistent bekommt sie nie. Felder ausgeschalteter Module fehlen jetzt auch in der
  Antwort, ein Filter oder eine Sortierung darauf antwortet 400. Der KI-Assistent bekommt Personen ohne
  E-Mail, Telefon, Ausweis, Notizen und Zutrittszeitraum, Lieferanten und interessierte Parteien ohne
  E-Mail und Telefon, und keine Datenpannen, Betroffenenanfragen
  oder Einwilligungen (auch nicht in der Suche) — außer mit der Rolle Datenschutzbeauftragte(r).
  Die Suche wertet `%` und `_` als normale Zeichen.
- **Vier Augen und Freigabeketten über REST/JSON (S27):** `POST /lifecycle/{type}/{id}/transition` nimmt
  `four_eyes_approver_id` nur noch als Wunsch, wen die Anfrage erreichen soll. Ein Vier-Augen-Schritt antwortet
  jetzt **202** (`countersign_requested: true`, `request_id`) und ändert den Status erst, wenn die zweite Person
  die Anfrage angemeldet im Vier-Augen-Posteingang bestätigt; die Sammelaktion meldet die Anfragen unter
  `countersign_requested`. Schritte einer Freigabekette (`workflow_instance`) lehnt der Endpunkt mit 422 ab
  (`approval_chain_step`) — sie werden in der Freigabe selbst entschieden („Mein Tag“, Workflow-Seite).
- **Ausgehende Adressen:** Geokodierer, OIDC-Discovery und Webhooks erreichen keine internen
  Adressen mehr. Ein eigener Nominatim-Dienst im LAN funktioniert deshalb nicht mehr; Web-Push nur zu
  Browser-Push-Diensten (fremde Abos werden beim nächsten Versand abgeschaltet).

### Geändertes Verhalten

- **Berichte, Exporte und Benachrichtigungen folgen den Modulen (S32):** Ist das Modul eines Berichts aus
  (Risiken, Assets, Vorfälle, Controls, Audits, Compliance, Datenschutz, BCM, Portfolio), antworten seine Download-,
  PDF-, Excel- und Export-Routen nicht mehr (Weiterleitung zum Dashboard bzw. 404), und die Kacheln dazu fehlen.
  Geplante Berichte prüfen Modul, Organisation und Ersteller bei jedem Lauf: ist das Modul aus, die Organisation
  deaktiviert oder der Ersteller nicht mehr aktiver Manager, wird der Bericht übersprungen („Übersprungen“ mit
  Grund auf der Berichtsseite). Der Portfolio-Bericht braucht jetzt das Modul `portfolio_reports`, wie die
  Portfolio-Seiten. Schlägt ein Bericht fehl, versucht der Lauf ihn nach 1, 2, 4 … Stunden (höchstens 24, nie nach
  dem regulären Termin) erneut statt stündlich, und die Berichte anderer Organisationen laufen weiter; die
  Berichtsseite zeigt einen festen Hinweis statt Fehlertext (Details im Anwendungsprotokoll). Neue Spalte
  `failed_runs`: Migration `Version20261109200000` über Quick-Fix „Ausstehende Migrationen anwenden“.
  Der Board-One-Pager darf auch an Personen mit `ROLE_TOP_MGMT` gehen; versendet wird an die Adresse des Kontos.
- **Sicherheitsbericht neu erzeugen (S32)** (Über > Sicherheit) nur noch für den Super-Admin und als
  Hintergrundjob; die Antwort enthält keine Skriptausgabe mehr.
- **Benachrichtigungszentrale (S32):** Regel-Benachrichtigungen der Organisation (Kanal „In-App“) sehen nur noch
  Manager und nur bei aktivem Modul des Ereignisses; alle anderen sehen ihre eigenen Erinnerungen. Der
  Aktivitäts-Feed zeigt Freigaben, Risiken und Dokumente nur bei aktivem Modul.
- **Berichts-Baukasten (S32):** „Geteilt“, „Vorlage“ und Verantwortliche in den Einstellungen eines Berichts setzt
  nur ein Manager (wie „Als Vorlage speichern“).
- **Kommentare (S32):** höchstens 10.000 Zeichen; der Kommentarverlauf zeigt die Kommentare der gerade gewählten
  Organisation.
- **Vorschläge: wer sieht, wer annimmt (S30):** Annehmen verlangt jetzt auch das Recht, dieselbe
  Änderung im Formular zu machen. Zuständige ohne Manager-Rolle nehmen VVT-, Register-,
  Managementbewertungs- und Zuordnungsvorschläge nicht mehr an und sehen sie nicht mehr in „Mein Tag“
  (dort stehen sie bei den Rollen der Art). Die Vorschlagsliste zeigt normalen Nutzern nur, was sie
  selbst entscheiden — auch unter „Entschieden“. Manager und Prüfer sehen die übrigen Vorschläge
  lesend, Entwürfe zu Datenpannen und Betroffenenanfragen aber nur mit der Rolle
  Datenschutzbeauftragte(r); der KI-Assistent (MCP) bekommt sie ebenfalls nur für diese Rolle.
  Ein KI-Entwurf zu einem schon gemeldeten, beantworteten oder abgeschlossenen Datensatz wird beim
  Annehmen als überholt zurückgezogen. Eine Migration (Sperrspalte an `suggestion`, Quick-Fix
  „Ausstehende Migrationen anwenden“).
- **Nachweise in einer Sprache (W10):** VVT-, Datenpannen-, DSFA-, Compliance-Export-, SoA- und Bündel-PDFs sowie
  die Excel/CSV-Exporte erscheinen vollständig in der gewählten Sprache (bisher deutsch-englisch gemischt). Exporte
  im Hintergrund (auch im Messenger-Modus) kommen in der Sprache der Person, die sie gestartet hat; der Anhang eines
  geplanten Berichts in der Sprache, die am Bericht eingestellt ist (bisher nur der Mailtext). Keine Handlung nötig.
- **Freigaben nur durch Berechtigte, selbst bestätigt (S27):**
  - Vier-Augen-Schritte (Dokument veröffentlichen, Asset entsorgen, Organisation sperren …) legen eine
    Gegenzeichnungsanfrage an; die zweite Person bestätigt sie angemeldet in „Mein Tag“. Bis dahin bleibt der
    Status. Im Nachweis steht nur noch, wer selbst bestätigt hat. Wo niemand sonst die Rolle hat, läuft der
    Schritt wie bisher sofort mit dokumentierter Offline-Gegenzeichnung in der Begründung.
  - Schritte von Freigabeketten entscheidet nur, wer den Schritt freigeben darf. Schritte der Leitung nur
    `ROLE_TOP_MGMT`, Schritte des Datenschutzes nur `ROLE_DPO` — Admins genehmigen diese beiden nicht mehr
    (und werden dafür nicht mehr erinnert). Nach dem Update in „Mein Tag“ prüfen, ob offene DSB-Schritte
    eine Person mit `ROLE_DPO` haben.
  - Audit-Arbeit (Checklisten-Urteil, Bericht, Feststellungen anlegen/bearbeiten/kopieren, Verifizierung,
    SoA-Snapshot, Wirksamkeitsprüfung, BSI-Übungsbestätigung) machen Auditoren und Manager sowie
    Leitauditor/Auditteam des Audits. Leitung, Konzern-CISO und Konzernauditor lesen weiter alles, schreiben
    dort aber nichts mehr; bisher ging das über die geerbte Auditorenrolle, Feststellungen sogar für jede
    angemeldete Person.
  - „Auditprogramm ableiten“ im Konzernbericht braucht zusätzlich `ROLE_MANAGER`.
  - Beim Sperren einer Organisation kommt die zweite Person aus derselben Organisation.

- **Audit-Log-Kette (B3):** Die Migration `Version20261109200000_AuditLogHmacLegacyCutoff` (Quick-Fix) merkt sich
  den letzten Eintrag mit altem Signaturformat. Die Signatur deckte bisher Sekundenbruchteile ab, die die Datenbank
  nie speichert — „HMAC-Kette verifizieren“ meldete deshalb jeden Eintrag als manipuliert. Neue Einträge sind voll
  prüfbar, ältere nur in ihrer Verkettung; die Prüfung nennt ihre Anzahl. Jede Organisation hat jetzt ihre eigene
  Kette, die Prüfung läuft als Hintergrundjob mit Fortschrittsseite. Nichts zu tun.

- **Kontraste und Barrierefreiheit (W11):** Gedämpfter Text, Erfolgs- und Warntext sowie Status-Pills sind
  etwas dunkler (hell) bzw. heller (dunkel), Formularfelder haben einen kräftigeren Rahmen — alles erreicht jetzt
  WCAG AA. Screenreader lesen Fehlermeldungen am Feld, Diagramm-Titel und Icon-Schaltflächen vor; Abschnitte langer
  Formulare und Prüfpunkte der Audit-Checkliste klappen auch per Tastatur (Enter/Leertaste) auf. Die Glocke nennt die Zahl
  neuer Meldungen, Navigations-Flyouts und Glocken-Panel gelten als Dialog statt Menü, Fortschrittsbalken haben einen Namen.
  Keine Aktion nötig.
  Nebenbei behoben: Details der Audit-Checkliste und der Framework-Anforderungen ließen sich gar nicht aufklappen.
- **Policy-Freigabe: letzter Schritt „published“ (T3):** Der Schritt der Kette „Policy Approval (W3)“ stand als
  `auto_action` auf `ROLE_USER` — jede angemeldete Person hätte eine Richtlinie abschließend freigeben können.
  Er ist jetzt ein Benachrichtigungsschritt (läuft von selbst durch, informiert die Manager). Bestehende Zeilen
  richtet die Migration `Version20261109170000` (Quick-Fix „Ausstehende Migrationen anwenden“). Sieben Bericht-
  und PDF-Routen (SoA-Vorschau, Konzern-One-Pager, Sicherheits- und Berichtsübersicht) tragen jetzt ein
  ausdrückliches `ROLE_USER`; für Personen ändert sich nichts.
- **Register: „Überprüft“ auch für die zuständige Person (T3):** Wen die Prüf-Erinnerung eines Registereintrags
  erreicht (Eigner, Rückfallrolle), bestätigt die Prüfung jetzt selbst; bisher brauchte die Schaltfläche
  `ROLE_MANAGER`.
- **Statusschritte prüfen die Rollen überall (S21):** Jeder Statuswechsel prüft die Rollen aus der
  Workflow-Definition (und die strengeren Overrides der Organisation) selbst, egal ob er über die
  Lebenszyklus-Schaltfläche, eine Fachseite, REST, den Assistenten (MCP), einen Job oder einen
  Vorschlag ausgelöst wird. Wer die Rolle nicht hat, bekommt „Für diesen Schritt fehlt die nötige
  Rolle“ bzw. 403. Spürbar vor allem bei: interne Audits (Bericht einreichen/überarbeiten nur
  `ROLE_AUDITOR`, Ablehnen und Abschließen nur `ROLE_CISO`; Anlegen und Kopieren nur Manager, Bearbeiten
  Manager oder Leitender Auditor), DSFA (Einreichen `ROLE_DPO`, Freigeben/Ablehnen `ROLE_CISO`),
  Transfer-Folgenabschätzung (`ROLE_DPO` oder Manager), Betroffenenanfragen und Datenpannen
  (`ROLE_DPO` oder die zuständige Person), Vorschlag „Behandlung abschließen“ (`ROLE_RISK_MANAGER`).
  Wer bisher einen solchen Schritt ohne die Rolle gemacht hat, braucht jetzt die Rolle unter
  Admin > Benutzer. Keine Migration.
- **Anmeldung:** Der zweite Faktor gilt je Anmeldung und Person, auch nach SSO und bei der
  Neuanmeldung mit Passwort; jeder TOTP-Code zählt einmal. Deaktivierte Benutzer und Mitglieder
  deaktivierter Organisationen sind überall abgewiesen, ihre Tokens widerrufen.
- **Rollen:** Prüferrollen (`ROLE_AUDITOR`, `ROLE_GROUP_CISO`, `ROLE_KONZERN_AUDITOR`) sind
  durchgehend schreibgeschützt; Auditoren laden das Audit-Paket selbst. Wer erinnert wird, darf dort
  handeln (Rolle ODER Verantwortlicher). Nicht-Manager bearbeiten nur eigene Risiken; das
  Audit-Log-Menü sehen nur Admins. VVT-/DSFA-Exporte ab Manager oder DSB, TIA „Bewerten“ nur DSB und
  Manager.
- **Rollenvergabe:** Niemand vergibt eine Rolle über der eigenen oder sich selbst `ROLE_TOP_MGMT`.
  Holding-Rollen (auch `ROLE_GROUP_BCM_OFFICER`) vergibt nur der Super-Admin; Org-Admins sehen Konten
  mit Holding-Rolle nur an. Admins richten kein TOTP mehr für andere ein, die Person macht das im
  Profil. Der CSV-Benutzerimport verlangt Passwörter mit der Mindestlänge der Organisation.
- **Freigaben und Lifecycle:** `/admin/workflows` ist nur noch eine Leseansicht („Freigabeketten“);
  wer freigibt, steuert die Rollenvergabe in der Benutzerverwaltung. Lifecycle-Overrides dürfen nur
  verschärfen: Overrides, die eine Norm-Pflicht lockerten (Freitextrollen wie `ROLE_USER`,
  Vier-Augen aus, Leitungs- oder DSB-Übergang umgehängt), wirken nicht mehr; die Seite zeigt sie als
  „Aktueller Wert“ und entfernt sie beim Speichern. Einwilligungs-Widerrufe dokumentieren nur DSB oder
  Manager, Ziele als erreicht markieren Manager oder die verantwortliche Person, Meldungszustellungen
  setzt nur das System.
- **Instanzbetrieb:** Setup-Assistent nach Abschluss und das Löschen eines Frameworks nur
  `ROLE_SUPER_ADMIN`; Super-Admins können keine Super-Admins imitieren. Ein Restore übernimmt keine
  Instanzrollen, Passwort-Hashes, SSO-Kennungen, API-Tokens oder Audit-Zeilen aus der Datei.
  Systemzustand, Schema-Update, composer, Cache/Logs löschen, Fehlerlog und Performance-Seite sind auf
  jeder Instanz nur noch dem Super-Admin offen (der erste Setup-Account ist Super-Admin). Bei mehreren
  Organisationen ändert nur der Super-Admin den geteilten Katalog (Mappings, Frameworks,
  Branchen-Presets, Mapping-Seeds); Org-Admins sehen einen Hinweis.
- **Nachweise schreibt der Schritt:** Freigabe-, Umsetzungs-, Prüf-, Abschluss- und Meldezeitpunkte
  (Änderungsantrag, Vorfall, BC-Übung/-Plan, Leitungsakzeptanz eines Lieferanten, Risikoakzeptanz und
  Behandlungsfreigabe) sind im Formular schreibgeschützt und entstehen über die Aktion auf der
  Detailseite („Freigeben“, „Als gemeldet markieren“, „Der Leitung vorlegen“, „Akzeptanz beantragen“ …).
  Eine Risikoakzeptanz braucht ein Ablaufdatum (höchstens 36 Monate); solange sie steht, ist die
  Restbewertung eingefroren. Übersicht: [Status-Lifecycle](docs/user-guide/STATUS_LIFECYCLE.md).
- **Löschen:** Assets nur nach Entsorgung oder binnen 24 h nach Anlage; die Aufbewahrungs-Löschung
  entfernt nur Datensätze im Endzustand. `app:sample-data:purge` verlangt `--tenant-id`.
- **Fristen:** Alle Erinnerungen laufen über einen Weg: „Mein Tag“, Glocke und tägliche Sammelmail
  (07:00 je Organisation, im Profil abwählbar). Neue Fristtypen u. a.
  Erfüllungs-Review („Review bestätigen“), NIS-2-Registrierungsänderung, TIA-Wiedervorlage; Übersicht
  unter Admin > Fristtypen ([Fristen](docs/DEADLINES_AND_RESPONSIBILITY.md)).
- **Erfüllung** wird aus den Daten abgeleitet und nachts um 00:45 neu berechnet; Handwerte neben
  einer Regel sind Overrides mit Begründung ([Erfüllung aus Daten](docs/user-guide/ERFUELLUNG_AUS_DATEN.md)).
- **Anzeige:** Datensatztext, der früher als HTML erschien, steht jetzt wörtlich da; Word-Exporte
  maskieren allen Text.
- **KI-Funktionen** sind standardmäßig aus (Admin > KI-Vorschläge).
- **`isms:load-annex-a-controls`** legt die 93 Annex-A-Controls je Organisation an (fehlende ergänzen,
  vorhandene bleiben unverändert); `--tenant=<code>` für genau eine Organisation. Früher entstanden Controls
  ohne Organisation, und Controls anderer Organisationen wurden zurückgesetzt.
- **`app:tisax:consolidate`** verschiebt eine TISAX-Bewertung nur noch auf eine Zeile derselben Organisation,
  nie mehr auf die gemeinsame Katalogzeile; ohne passende Zeile zieht die Altzeile selbst um.
- **Log-Warnung „saved without an organisation“:** Ein Bearbeitungsschritt hat einen Datensatz ohne
  Organisation gespeichert, den die eigene Organisation nicht sieht. Kein Handlungsbedarf beim Update; bitte
  als Fehler melden (Klasse steht in der Meldung).
- **Abmelden** leert Browser-Cache und Speicher der Seite (`Clear-Site-Data`); der Service Worker
  speichert nur noch Build-Dateien, keine Seiten, Berichte oder API-Antworten, und löscht beim ersten
  Laden die alten Caches. Offline erscheint nur noch die Offline-Seite.
- **Organisations-Restore ändert keine Nachweise mehr (S26):** Eine Wiederherstellung für eine Organisation
  (Org-Admin, oder Super-Admin mit gewählter Organisation) übernimmt aus der Datei keine Nachweis-Datensätze
  (Audit-Log, Leitungsentscheidungen, Audit-Freeze, SoA-Abbilder, Richtlinien-Kenntnisnahmen,
  Vier-Augen-Anfragen, Freigabeläufe, Dokumentversionen …) und löscht sie auch mit „Vor Wiederherstellung
  leeren“ nicht. Freigabe-, Melde- und Abschlussstempel (z. B. Risikoakzeptanz, Audit berichtet/freigegeben)
  und der Lifecycle-Status bestehender Datensätze behalten ihren Wert; Datensätze, die der Restore neu anlegt,
  beginnen ohne Nachweis im Anfangsstatus. Das Restore-Ergebnis nennt, was nicht übernommen wurde. Eine
  vollständige Rückkehr samt Nachweisen (etwa nach Datenverlust) ist ein Instanz-Restore durch den Super-Admin
  ohne gewählte Organisation — unverändert. Rund 40 weitere Schritt-Felder sind jetzt als Nachweis markiert
  und kommen damit auch nicht mehr über den Massenimport. Kein Handlungsbedarf.
- **Dateipfade aus Sicherungen (S19):** Eine Wiederherstellung übernimmt einen Logo-, Avatar-, PDF-
  oder Dokumentpfad nur in der erwarteten Form (`uploads/tenants/<datei>`, `uploads/users/<datei>`,
  `/uploads/documents/<datei>`, nur Dateiname); eine Organisationssicherung zusätzlich keinen Pfad, den
  eine andere Organisation nutzt, und kein Kopf-/Fußzeilen-HTML des Brandings. Abgewiesene Pfade stehen
  als Warnung im Restore-Ergebnis („Dateipfad … nicht übernommen“); der Datensatz behält seinen Wert.
  Logos aus ZIP-Sicherungen landen jetzt dort, wo der Datensatz sie sucht (`public/uploads/tenants/`).
  ZIP-Sicherungen enthalten jetzt auch die Profilbilder (`files/user_avatars/`, zurück nach
  `public/uploads/users/`); ältere Sicherungen bringen keine mit.
  Der Setup-Assistent gilt als abgeschlossen, sobald ein Konto existiert, auch ohne
  `var/setup_complete.lock` (die Datei wird dann neu geschrieben). Kein Handlungsbedarf.
- **Öffentliche Wege (S25):** Alle Seiten ohne Anmeldung sind je IP gedrosselt: Probes
  (`/health`, `/readyz`, `/metrics`) 120 Aufrufe pro Minute, SSO/SAML/OAuth 300, Quick-Fix 120,
  Trust Center und Lieferantenfragebogen wie bisher 60; darüber antwortet die Seite 429. Ein Monitor,
  der öfter als alle halbe Sekunde prüft, muss langsamer werden. Logo und Profilbild nehmen nur noch
  Bilder (JPG, PNG, GIF, WebP); bereits hochgeladene Dateien bleiben. PDFs lesen lokale Dateien nur noch
  aus `public/` — Logos in Richtlinien-PDFs erscheinen dadurch jetzt, vorher fehlten sie. Globale
  Tour-Texte ändert nur der Super-Admin. Kein Handlungsbedarf.
- **Exporte** und Downloads verlangen überall eine Rolle (`#[IsGranted]`), auch die
  Compliance-Exporte. Geplante Berichte bieten nur Berichtsarten aktiver Module an.
- **Zuständigkeit (S20):** Wer an einem Datensatz verantwortlich ist, darf dort handeln (Freigaben,
  Meldung, Abschluss). Deshalb verknüpft nur noch ein Manager eine Person mit einem Benutzerkonto (das
  Feld ist für andere schreibgeschützt, jede Änderung steht im Audit-Log), und wer kein Manager ist,
  trägt sich an einem bestehenden Datensatz nicht selbst als Eigner, Bearbeiter, Freigeber oder
  Vertretung ein — außer er hält ihn schon. Das gilt für Formular, REST, MCP und Import (Formular:
  Fehlermeldung, sonst 403). Beim Anlegen darf man sich weiter selbst eintragen. Bitte nach dem Update
  in Personen prüfen, ob jede Verknüpfung mit einem Konto stimmt.
- **Audit-Checkliste (S20, ISO 27001 9.2):** Urteile speichern nur Auditoren, Manager und Leitauditor
  oder Auditteam des Audits. Ein Urteil wirkt erst auf die Erfüllung, wenn das Audit berichtet ist
  (Status „berichtet“, „freigegeben“, „abgeschlossen“); Urteile laufender Audits zeigen sich danach.
- **Schreibregeln für jeden Weg (S22):** Einstufung und Zuständigkeit sind Manager-Daten. An einem
  bestehenden Datensatz ändern Schwere, Meldepflicht-Kennzeichen, Schutzbedarf (C/I/A,
  Klassifizierung), Kritikalität von Lieferanten, RTO/RPO, Sicherheitsstufe von Standorten, Fristen von
  Schwachstellen und Patches u. ä. nur Manager oder die für den Datensatz verantwortliche Person;
  andere sehen das Feld schreibgeschützt (Formular) bzw. bekommen 403 (REST, Assistent, Import).
  Erzählende Felder bleiben für alle bearbeitbar. Wer einen fremden Datensatz nicht hält, gibt ihn
  auch nicht an jemand anderen weiter (bisher konnten sich zwei Nicht-Manager gegenseitig eintragen).
  Der Entdeckungszeitpunkt von Vorfällen und Datenpannen sowie der Risikobezug eines
  Behandlungsplans werden beim Anlegen gesetzt und nur von Managern korrigiert; ein Import-Update
  überschreibt sie nicht. Schulungen bearbeiten nur Manager und die Trainerin/der Trainer. Abschluss-,
  Wirksamkeits-, Verifizierungs-, Behebungs-, Ausroll- und Antwortzeitpunkte (Maßnahmen,
  Audit-Feststellungen, Schwachstellen, Patches, Schulungen, Betroffenenanfragen) setzt der
  jeweilige Statusschritt; im Formular sind sie schreibgeschützt, Import und REST schreiben sie
  nicht mehr (REST: `completionDate` von Schulungen, `securityScore`/`lastSecurityAssessment` von
  Lieferanten, `lastReviewDate` von Controls). Der Massenimport prüft jede Zeile mit denselben
  Regeln wie das Formular schon in der Vorschau; ungültige Zeilen erscheinen dort als Fehler. Die
  Verfügbarkeit eines Assets fällt beim Import (auch GSTOOL) nicht unter den Wert aus der BIA.
  Keine Migration, kein Klick.
- **Importe (W9):** Erreicht ein Compliance-Mapping-Import die „Import 4-Augen-Pflicht ab Zeilen“
  (Admin > Compliance-Richtlinien, Standard 50, 0 = aus), lässt er sich erst nach der Freigabe genau
  dieser Datei durch eine zweite Person festschreiben („Freigabe anfordern“ in der Vorschau, Freigabe im
  4-Augen-Posteingang); bisher war das nur ein Hinweis. Der TISAX-Import legt das gemeinsame
  TISAX-Framework nur noch an, wer den Katalog schreiben darf (bei mehreren Organisationen der
  Super-Admin), und schaltet ein deaktiviertes nicht wieder ein; Vorschau und Bestätigungsseite schreiben
  nichts mehr. Der Bibliotheks-Export enthält keine TISAX-Zeilen anderer Organisationen. Der
  CSV-Benutzerimport legt auch beim Super-Admin Konten in der aktuellen Organisation an. Die alte
  JSON-Seite `/admin/data/import` (schrieb nie etwas) ist entfallen; Einstieg ist der Import-Hub.
  Upload-Dateien nie festgeschriebener Bulk-Importe löscht der Scheduler nach 30 Tagen
  (`app:import:purge-abandoned`, 03:35).
- **DORA Art. 26:** Der Compliance-Wizard wertet TLPT nur noch als erfüllt, wenn ein abgeschlossener
  TLPT mit Enddatum und Zusammenfassung in den letzten 36 Monaten vorliegt; ein geplanter Eintrag reicht
  nicht mehr. Die DORA-Erfüllung kann dadurch sinken.
- **Einstellungen ohne Wirkung entfernt** (`Version20261109093000_DropUnusedAdminSettings` löscht die
  Spalten): Policy-Optik ohne Schriftart, Deckblatt-Muster und Custom-CSS; Report-Optik ohne
  Deckblatt-Muster, Standard-Zielgruppe, Wasserzeichen-Deckkraft, Schriftart, Seitenausrichtung,
  Diagramm-Farbschema und Custom-CSS (Tabs „Allgemein“, „Typografie“, „Erweitert“ entfallen);
  E-Mail-Branding ohne Logo-URL (auch der SystemSettings-Schlüssel `email.default_logo_url` wird nicht
  mehr gelesen); Planungs-Einstellungen ohne „Standard-Wiederholung“. Keiner dieser Werte wurde je
  ausgewertet; Dokumente und Mails sehen nach dem Update gleich aus.
- **Einstellungen, die jetzt wirken** (vorher gespeichert, aber nie gelesen):
  - Ein **deaktivierter Benachrichtigungskanal** oder eine deaktivierte Regel stellt nichts mehr zu,
    auch nicht bereits eingereihte Zustellungen oder Digests (sie werden als fehlgeschlagen markiert).
    Der **Schweregrad-Filter** einer Regel greift.
  - **Incident-SLA:** Die Lösungsfrist ist die Frist des Vorfalls in „Mein Tag“ und den Erinnerungen;
    nach Ablauf der Eskalationsfrist werden ISB und CISO mit informiert.
  - **Lieferanten-Kritikalität:** Listen, Filter, Formular und Detailseite zeigen die eigenen Stufen
    (Bezeichnung, Farbe); neue Lieferanten starten mit der Standard-Stufe.
  - **Zeitzone der Organisation** (Admin > Organisationen) gilt für alle angezeigten Zeiten und hat
    Vorrang vor der Instanz-Zeitzone. Wer abweichende Organisations-Zeitzonen gepflegt hat, sieht
    Zeiten nun in dieser Zone.
  - **Policy- und Report-Optik:** Inhaltsverzeichnis, Historie, Annex-A-Bezüge, Unterschriftszeilen,
    Logo-Größe, Wasserzeichen, Seitenrand, Fußzeile, Management-Summary, Anhang, Verteiler und
    Haftungshinweis wirken in Vorschau, HTML und PDF.
  - **E-Mail-Branding:** Fußzeilentext und Support-Adresse stehen unter jeder ausgehenden Mail;
    die Support-Adresse ist Reply-To, wenn die Mail keine eigene nennt.
  - **Vererbung über Mappings** (Admin > Compliance-Richtlinien) wird bei der Framework-Aktivierung
    gelesen; der Container-Parameter entfällt.
  - **MRIS-KPIs** sind überall an, bis sie in den Organisations-Einstellungen abgeschaltet werden
    (die SoA blendete sie bisher ohne Einstellung aus).
  - **Organisationsprofil** unter Admin > Organisationen fragt nur noch die Unternehmensgröße ab;
    Branche, Land und Beschreibung pflegt der Organisationskontext (ISO 27001 4.1).
- **Lifecycle-Automatik:** Die Regel, die Risiken innerhalb des Risikoappetits automatisch
  akzeptierte, entfällt (sie lief nie: Akzeptanz braucht vier Augen). Die Incident-Regel heißt
  `investigate_when_assessed` und startet die Untersuchung, sobald die Bewertung vollständig ist.
- **Richtlinien-Bestätigungen schließen:** Kampagne, Bestätigung, Posteingang und „Mein Tag“ nutzen
  eine Version. Bisher blieb die offene Anfrage nach dem Klick stehen und erinnerte weiter. Der
  nächtliche Lauf `app:policy-wizard:send-ack-requests` (05:40, neu im Scheduler) legt Anfragen für
  später hinzugekommene Personen an und zieht unschließbare zurück (alte Version, Zielgruppe
  verlassen, deaktiviert, Dokument nicht mehr veröffentlicht); alte offene Zeilen werden so in der
  ersten Nacht bereinigt, ohne Klick. Erledigte Bestätigungen bleiben als Nachweis. Der
  Bestätigungs-Posteingang zeigt nur noch Dokumente mit „Bestätigung erforderlich“ oder aus dem
  Policy-Wizard, und die auf der Dokumentseite gewählte Zielgruppe gilt überall.
- **Audit-Freeze** ist für Auditoren lesbar; Anlegen und PDF-Erzeugung bleiben Managern vorbehalten.
- **Compliance-Exporte** brauchen das Modul `compliance`, wie die Seiten, aus denen sie kommen.
- **Modul an/aus** steht im Audit-Log (`module.activated` / `module.deactivated`).
- **Worker-Modus (Messenger):** Die Cron-Zeile verarbeitet auch `scheduler_default`
  (`messenger:consume scheduler_default scheduler async …`, Admin > Queue-Status zeigt sie); ohne
  liefen die eingebauten Zeitpläne in diesem Modus nicht. Mails, die länger als 15 Minuten in der
  Warteschlange liegen, färben den Worker-Status rot.
- **Hintergrundaufgaben je Organisation:** Der Worker setzt nach jeder Aufgabe den
  Organisationskontext zurück. Bisher konnte ein geplanter Bericht, der KPI-Snapshot oder ein
  Admin-Job die Organisation für die nächste Aufgabe festhalten: das nächtliche Instanz-Backup enthielt
  dann nur eine Organisation, Erinnerungen und SLA-Hinweise der anderen kamen zu spät. Kein Klick nötig;
  Instanz-Backups, die vor dem Update auf Mehr-Organisations-Instanzen nachts entstanden sind, nach dem
  Update durch ein neues ersetzen (Admin > Backup), bevor `app:backup:prune` die älteren vollständigen
  löscht. Die nächtliche Erfüllungsberechnung bewertet die Wizard-Prüfungen jetzt mit dem Scope der
  jeweiligen Organisation; Werte können sich einmalig ändern.
- **Audit-Log-Aufbewahrung:** Die nächtliche Bereinigung (02:45) löscht jetzt wirklich (geplant mit
  `--force`; ohne Terminal antwortete die Rückfrage bisher immer „nein“). Gelöscht wird nur, was älter
  ist als die Frist unter Admin > Audit-Log > Aufbewahrung (mindestens 365 Tage).
- **„Warteschlange jetzt abarbeiten“** (Admin > Worker-Status) nur noch für den Super-Admin: die
  Warteschlange enthält Jobs und Mails aller Organisationen. Der wöchentliche Log-Eintrag
  „Compliance report generated“ entfällt (er zählte instanzweit und ging an niemanden).

### SSO (Azure, SAML, OIDC)

- `DEFAULT_URI` muss die öffentliche Adresse tragen, sonst bricht SAML ab (Entity-ID `<DEFAULT_URI>/saml/metadata`).
  Seit S25 gilt das auch für OIDC: die `redirect_uri` ist `<DEFAULT_URI>/sso/<slug>/callback` (der
  SSO-Assistent zeigt sie so an). Steht beim Anbieter eine andere Adresse (z. B. mit internem
  Hostnamen), dort die angezeigte eintragen.
- `/de/saml/*` (Metadaten, ACS) und `/de/oauth/azure/*` sind jetzt ohne Anmeldung erreichbar; vorher
  leitete die Sicherheitsregel auf die Anmeldeseite um (die Regel nannte Pfade ohne Sprachpräfix).
- IdP-initiierte SAML-Anmeldung („Meine Apps“-Kachel) wird abgewiesen; Anmeldung über den SAML-Knopf.
- Azure (env): `AZURE_TENANT_ID` muss eine GUID sein oder `AZURE_ALLOWED_TENANT_IDS` gesetzt.
  E-Mail-Verknüpfung ist standardmäßig aus und braucht den Claim `xms_edov`.
- OIDC: Anbieter ohne Issuer und ohne Discovery lehnen Anmeldungen ab; bestehende Konten werden nur
  mit `email_verified` verknüpft, sonst verknüpft der Admin von Hand.
- SAML braucht HTTPS (Binding-Cookie `SameSite=None; Secure`); die Antwort muss im selben Browser
  ankommen.
- SSO-Rollen-Mappings auf privilegierte Rollen oder Freitext werden ignoriert; Admin > SSO zeigt sie als
  „nicht vergebbar“, dort neu aus der Liste wählen. Eine OIDC-Anmeldung ersetzt nur die vom Anbieter
  verwalteten Rollen.
- Mehrere Webserver brauchen einen gemeinsamen Cache. Ein nackter Aufruf von `/logout` meldet nicht
  mehr ab, der Menülink schon. Der Lieferanten-Fragebogen-Link gilt 90 Tage.
