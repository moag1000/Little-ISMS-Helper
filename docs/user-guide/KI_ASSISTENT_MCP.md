# KI-Assistent anbinden (MCP)

Little ISMS Helper ist ein **MCP-Server** (Model Context Protocol). Damit fragt dein eigener KI-Assistent
(Claude Desktop, Claude Code oder ein anderer MCP-Client) direkt im ISMS nach: „Was ist heute fällig?“,
„Warum steht A.5.15 bei 40 %?“, „Schreib mir einen Bericht für die Leitung“, „Leg die fünf Risiken aus meinen
Workshop-Notizen an“.

Der Assistent **liest und schreibt mit deinen Rechten**: Datensätze anlegen und ändern geht direkt (mit Token, das
schreiben darf), mit denselben Pflichtfeld-Prüfungen wie im Formular. **Was eine Entscheidung braucht, bleibt bei
Menschen**: Status, Freigaben, Risikoakzeptanz, Leitungsentscheidungen und Übersteuerungen der Erfüllung legt der
Assistent nur als Entwurf in die [Vorschlags-Queue](VORSCHLAEGE.md); eine verantwortliche Person übernimmt oder lehnt ab.

## In drei Schritten verbinden

1. **Admin schaltet frei** (einmal je Organisation): *Admin > Einstellungen > KI-Einstellungen*, Abschnitt
   „KI-Assistent anbinden (MCP)“, Schalter an, Speichern. Ausgeschaltet antwortet der Server jedem Token mit 403.
   Der zweite Schalter „Assistent darf Datensätze anlegen und ändern“ ist dabei vorausgewählt; aus = nur lesen und
   Entwürfe.
2. **Token anlegen**: *Profil > API-Tokens* (Suche: „MCP“), Bezeichnung z. B. „Laptop“, bei Bedarf **„Assistent darf
   schreiben“** anhaken, Erstellen. Ohne Haken bleibt das Token lesend. Das Token steht nur jetzt im Klartext da; die
   Konfigurationsbeispiele darunter enthalten es bereits. Die Spalte „Zugriff“ zeigt je Token „Lesen“ oder
   „Lesen + Schreiben (Assistent)“.
3. **Beispiel kopieren und einfügen** (Knopf „Kopieren“):
   - **Claude Code**: Befehl im Terminal ausführen —
     `claude mcp add --transport http isms https://<dein-host>/mcp --header "Authorization: Bearer <TOKEN>"`
   - **Claude Desktop**: in *Einstellungen > Entwickler > Konfiguration bearbeiten* (`claude_desktop_config.json`)
     den Block `mcpServers.isms` einfügen und Claude Desktop neu starten. Er nutzt `npx mcp-remote` als Brücke und
     braucht Node.js.
   - **Team-Projekt**: `.mcp.json` ohne Token einchecken; jede Person setzt `ISMS_TOKEN` in ihrer Umgebung.

Danach im Assistenten z. B. „Nutze isms: was ist bei mir überfällig?“ oder einen der fertigen Prompts wählen.

## Was der Assistent kann

| Werkzeug | Antwort | Für wen typisch |
|---|---|---|
| `get_context` | Person, Organisation, Personas, aktive Module, Datensatzarten mit Filtern und ob sie angelegt/geändert werden dürfen, ob direkt geschrieben werden darf | alle (zuerst) |
| `my_day` | offene Fristen und Entscheidungen wie in *Mein Tag*, mit Normstelle und Link | alle |
| `compliance_fulfillment` | Erfüllung je Norm, je Anforderung, und „Warum dieser Wert?“ (Controls, Datensatzprüfungen, Mappings, Reifegrad, Audit-Urteil, Übersteuerung mit Begründung, Nachweise) | ISB, Compliance, Auditor |
| `management_kpis` | Kennzahlen der Dashboards mit Ampel nach euren Schwellen | Leitung, CISO |
| `duty_calendar` | wiederkehrende Normpflichten mit nächster Fälligkeit | ISB, BCM, DSB |
| `search` | globale Suche (wie ⌘K) | alle |
| `list_records` / `get_record` | jede Ressource der REST API (Risiken, Controls inkl. SoA, Vorfälle, Assets, Lieferanten, Register, …) mit denselben Rechten, Filtern und Feldern wie `/api` | alle |
| `save_record` | Datensatz anlegen (ohne ID) oder Felder ändern (mit ID) über die Schreib-Operation der REST-Ressource: Risiken, Assets, Controls/SoA-Begründung, Vorfälle, Lieferanten, Schulungen, Standorte, Ziele, … Verknüpfungen als Link (`/api/assets/5`). Fehler kommen als „Feld: Meldung“. Mit leerem `data` liefert es die schreibbaren Felder. Kein Löschen | alle mit Schreibrechten |
| `pending_suggestions` | Vorschläge, über die du entscheidest | alle mit Entscheidungen |
| `draft_options` / `propose_draft` | Entwürfe zu einem Datensatz: Risiko aus Vorfall, Korrekturmaßnahme, SoA-Begründung, Meldetexte, Zusammenfassung für die Leitung, … — landen in der Vorschlags-Queue | Risk-Manager, ISB, DSB |

Fertige Prompts: **Mein Tag**, **Audit-Bereitschaft** (je Norm), **Bericht für die Leitung**, **Entwurf vorschlagen**.

Entwürfe des Assistenten erscheinen in der Queue mit Herkunft „KI“ und Modell „MCP: <Token-Bezeichnung>“ —
dieselbe Entscheidung, dasselbe Audit-Log wie bei den Entwürfen des eigenen KI-Modells. Der Assistent braucht dafür
**kein** eingeschaltetes KI-Modell der Organisation.

## Rechte, Datenschutz, Nachvollziehbarkeit

- **Rechte**: Der Assistent sieht und ändert genau, was die Person des Tokens in der App sieht und ändern darf
  (Rollen, Voter, Mandant, aktive Module). Prüfer- und Leitungsrollen ohne Manager-Rechte bleiben lesend, auch mit
  schreibendem Token; Entwürfe darf anlegen, wer über sie entscheiden darf.
- **Direkt schreiben nur, wo keine Entscheidung nötig ist**: `save_record` braucht drei Dinge — Schalter der
  Organisation, Token mit „Assistent darf schreiben“, keine reine Prüferrolle. Es nutzt die Schreib-Operation der
  REST-Ressource (Rechteprüfung, Pflichtfelder, Mandant beim Anlegen erzwungen) und nur deren Schreibfelder; Status,
  Mandant, IDs und Freigabe-Nachweise (Risikoakzeptanz, Billigung, Genehmigung von Änderungen) sind dort nicht
  schreibbar und werden abgewiesen. Globale Kataloge (Normen, Rollen, Vorlagen) schreibt kein Organisations-Token.
  Gelöscht wird nie. Die REST-API selbst bleibt für jedes Token lesend.
- **Was eine Entscheidung braucht**: Werte der Erfüllung, Status und Freigaben bleiben in der App (Übersteuerung mit
  Begründung, Lifecycle, Entscheidungsdialog) bzw. kommen als Entwurf über `propose_draft`.
- **Datenfluss**: Was der Assistent liest, geht an ihn und seinen Anbieter (bei Claude: Anthropic). Deshalb ist der
  Zugang je Organisation standardmäßig aus; der Admin entscheidet. Personenbezogene Daten werden — anders als beim
  eigenen KI-Modell — nicht ersetzt, denn die Person liest ihre eigenen Daten.
- **Audit-Log**: jeder Werkzeugaufruf als `mcp.read` / `mcp.propose` / `mcp.write` mit Werkzeug, Datensatzart, ID,
  Anzahl, bei Schreibvorgängen den Namen der geänderten Felder, und Token — nie mit Suchtext oder Inhalten. Die
  Änderung selbst (alt → neu) steht wie bei jeder Formular-Änderung im Änderungsprotokoll des Datensatzes.
- **Widerruf**: *Profil > API-Tokens > Widerrufen* wirkt sofort; „Zuletzt benutzt“ zeigt, ob ein Token noch im Einsatz ist.
- **Ein Token ist nur ein Token**: Es gilt nur für `/api` und `/mcp`. Jede Seite der Weboberfläche antwortet einem
  Token mit 401, und es entsteht nie eine Browser-Sitzung (kein Cookie, das später ohne Token weiterarbeitet). Schreiben
  geht mit einem Token nur über `save_record` in `/mcp`; die REST-API und alle anderen Pfade bleiben lesend (405).
- **Deaktivierte Personen**: Wird ein Konto deaktiviert, sind alle seine Tokens sofort widerrufen; auch nach dem
  Reaktivieren bleiben sie tot, die Person legt neue an. Eine offene Browser-Sitzung endet beim nächsten Klick, jeder
  neue Login (Formular, SSO, „Angemeldet bleiben“) wird abgewiesen.
- **Grenzen**: 100 Anfragen pro Minute je Token (eine JSON-RPC-Batch zählt jede Nachricht, höchstens 20 je Batch).
  Prüferrollen bekommen kein schreibendes Token, der Haken „Assistent darf schreiben“ fehlt bei ihnen.

## Betreiber-Hinweis

- Nichts zu installieren, kein Worker, keine Kommandozeile: `/mcp` ist eine normale Route der App (Streamable HTTP
  ohne Sitzung, jede Anfrage einzeln) und läuft auch auf Shared Hosting.
- Hinter Proxy/CDN muss `Authorization` durchgereicht werden (bei Apache ggf. `CGIPassAuth On` bzw.
  `SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1`, wie für die REST API). `SYMFONY_TRUSTED_PROXIES` setzen, damit
  die Links in den Antworten den öffentlichen Host tragen.
- Seit dem Token-Hotfix (Welle 5, S1) heißt das Sitzungs-Cookie `isms_session`: Alle offenen Sitzungen enden beim
  Update einmal, jede Person meldet sich neu an. Keine Migration; bestehende Tokens laufen weiter, Tokens bereits
  deaktivierter Personen sind ab jetzt abgewiesen. Ein Skript, das mit Token Seiten der Weboberfläche aufrief, bekommt
  jetzt 401 und muss die REST-API nutzen.
- Migration `Version20261108120000_ApiTokenCanWrite` (Spalte `can_write` an `api_tokens`) über `/quick-fix` anwenden;
  bestehende Tokens bleiben lesend.
- claude.ai-„Connectors“ im Browser verlangen OAuth und werden (noch) nicht unterstützt; Claude Desktop und Claude Code
  funktionieren mit dem Token.

## Technik (für Entwickler)

- Endpoint `App\Controller\McpController` (`POST /mcp`, GET/DELETE → 405), Protokoll `App\Mcp\McpServer`
  (JSON-RPC 2.0; Methoden `initialize`, `ping`, `tools/*`, `prompts/*`; Versionen 2025-11-25 … 2024-11-05).
  Nur Bearer-Tokens (`ApiTokenAuthenticator` setzt `_api_token`), Session-Cookies werden abgewiesen, `Origin` wird geprüft.
- Tokens laufen in der eigenen zustandslosen Firewall `api_token` (security.yaml, Matcher
  `App\Security\Token\ApiTokenRequest`: jede Anfrage mit Bearer-Header). Der Authenticator nimmt Tokens nur auf
  `/api` und `/mcp` an und prüft, dass Token- und Personen-Organisation gleich sind; `App\Security\UserChecker` weist
  deaktivierte Personen in beiden Firewalls ab. `ApiTokenSessionSubscriber` gibt Token-Anfragen eine Sitzung nur im
  Speicher (`RequestScopedSession`), `SecurityEventSubscriber` schreibt für Tokens keinen Login-Eintrag.
  `DeactivatedUserSubscriber` beendet offene Sitzungen deaktivierter Personen, `RevokeApiTokensOnDeactivationListener`
  widerruft ihre Tokens. Tests: `tests/Security/ApiTokenBoundaryTest.php`.
- Werkzeuge: `src/Mcp/Tool/*` (Tag `app.mcp_tool`). Ein neues Werkzeug nur, wenn kein generisches passt —
  neue REST-Ressourcen, Fristenquellen und Vorschlagsarten erscheinen ohne Code hier.
- `App\Mcp\ApiResourceGateway` liest die Ressourcen aus den API-Platform-Metadaten und führt deren Get/GetCollection als
  Sub-Request aus (security, Groups, Filter, Mandantenfilter); das Modul-Gate der Ressourcenklasse prüft das Gateway selbst.
- Schreiben: `ApiResourceGateway::write()` führt Post (anlegen) bzw. Patch oder Put mit `standard_put: false` (ändern)
  als Sub-Request aus; nur Klassen mit `getTenant()/setTenant()`, nur Felder der Schreib-Gruppe, nie `status`/`tenant`/
  `id`. Ob geschrieben werden darf, entscheidet `App\Mcp\McpWriteAccess` (Schalter, `ApiToken::canWrite()`,
  `ReadOnlyRoleSubscriber::isReadOnlyUser()`); `ReadOnlyRoleSubscriber` prüft `_api_`-Routen auch in Sub-Requests.
  Eine neue Schreib-Operation einer REST-Ressource ist ohne Code hier über `save_record` schreibbar.
- Entwürfe: `RecordDrafts::kindsFor($record, byAssistant: true)` + `AbstractAiKind::assistantBrief()` /
  `draftFromAssistant()` — dieselbe Instruktion und dieselbe Abbildung wie beim eigenen Modell.
- Opt-in: `App\Mcp\McpSettings` (`Tenant.settings['mcp']`, Schlüssel `enabled`, `write`). Texte: Domain `mcp`.
