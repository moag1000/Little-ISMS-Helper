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

31 neue Migrationen, alle über `/quick-fix`. Zwei davon verschieben Daten: Freitext-Verantwortliche
werden Personen (`Version20261108100000_OwnerTextToPersons`, danach entfallen die Textspalten) und
Nachweisdateien ziehen aus dem Web-Root (`Version20261108140000_ProtectUploads`).

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
- **`DEFAULT_URI`** auf die öffentliche Adresse setzen (`https://isms.example.com`). Links in
  Erinnerungsmails werden damit gebaut.

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
- **Ausgehende Adressen:** Geokodierer, OIDC-Discovery und Webhooks erreichen keine internen
  Adressen mehr. Ein eigener Nominatim-Dienst im LAN funktioniert deshalb nicht mehr; Web-Push nur zu
  Browser-Push-Diensten (fremde Abos werden beim nächsten Versand abgeschaltet).

### Geändertes Verhalten

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
- **Instanzbetrieb:** Setup-Assistent nach Abschluss und das Löschen eines Frameworks nur
  `ROLE_SUPER_ADMIN`; Super-Admins können keine Super-Admins imitieren. Ein Restore übernimmt keine
  Instanzrollen, Passwort-Hashes, SSO-Kennungen, API-Tokens oder Audit-Zeilen aus der Datei.
  Systemzustand, Schema-Update, composer, Cache/Logs löschen, Fehlerlog und Performance-Seite sind auf
  jeder Instanz nur noch dem Super-Admin offen (der erste Setup-Account ist Super-Admin). Bei mehreren
  Organisationen ändert nur der Super-Admin den geteilten Katalog (Mappings, Frameworks,
  Branchen-Presets, Mapping-Seeds); Org-Admins sehen einen Hinweis.
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

### SSO (Azure, SAML, OIDC)

- `DEFAULT_URI` muss die öffentliche Adresse tragen, sonst bricht SAML ab (Entity-ID `<DEFAULT_URI>/saml/metadata`).
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
