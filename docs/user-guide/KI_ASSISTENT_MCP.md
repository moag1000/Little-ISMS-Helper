# KI-Assistent anbinden (MCP)

Little ISMS Helper ist ein **MCP-Server** (Model Context Protocol). Damit fragt dein eigener KI-Assistent
(Claude Desktop, Claude Code oder ein anderer MCP-Client) direkt im ISMS nach: „Was ist heute fällig?“,
„Warum steht A.5.15 bei 40 %?“, „Schreib mir einen Bericht für die Leitung“, „Leite aus Vorfall 17 ein Risiko ab“.

Der Assistent **liest mit deinen Rechten** und **schreibt nichts selbst**: Entwürfe landen in der
[Vorschlags-Queue](VORSCHLAEGE.md), eine verantwortliche Person übernimmt oder lehnt dort ab.

## In drei Schritten verbinden

1. **Admin schaltet frei** (einmal je Organisation): *Admin > Einstellungen > KI-Einstellungen*, Abschnitt
   „KI-Assistent anbinden (MCP)“, Schalter an, Speichern. Ausgeschaltet antwortet der Server jedem Token mit 403.
2. **Token anlegen**: *Profil > API-Tokens* (Suche: „MCP“), Bezeichnung z. B. „Laptop“, Erstellen. Das Token steht
   nur jetzt im Klartext da; die Konfigurationsbeispiele darunter enthalten es bereits.
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
| `get_context` | Person, Organisation, Personas, aktive Module, lesbare Datensatzarten mit Filtern | alle (zuerst) |
| `my_day` | offene Fristen und Entscheidungen wie in *Mein Tag*, mit Normstelle und Link | alle |
| `compliance_fulfillment` | Erfüllung je Norm, je Anforderung, und „Warum dieser Wert?“ (Controls, Datensatzprüfungen, Mappings, Reifegrad, Audit-Urteil, Übersteuerung mit Begründung, Nachweise) | ISB, Compliance, Auditor |
| `management_kpis` | Kennzahlen der Dashboards mit Ampel nach euren Schwellen | Leitung, CISO |
| `duty_calendar` | wiederkehrende Normpflichten mit nächster Fälligkeit | ISB, BCM, DSB |
| `search` | globale Suche (wie ⌘K) | alle |
| `list_records` / `get_record` | jede Ressource der REST API (Risiken, Controls inkl. SoA, Vorfälle, Assets, Lieferanten, Register, …) mit denselben Rechten, Filtern und Feldern wie `/api` | alle |
| `pending_suggestions` | Vorschläge, über die du entscheidest | alle mit Entscheidungen |
| `draft_options` / `propose_draft` | Entwürfe zu einem Datensatz: Risiko aus Vorfall, Korrekturmaßnahme, SoA-Begründung, Meldetexte, Zusammenfassung für die Leitung, … — landen in der Vorschlags-Queue | Risk-Manager, ISB, DSB |

Fertige Prompts: **Mein Tag**, **Audit-Bereitschaft** (je Norm), **Bericht für die Leitung**, **Entwurf vorschlagen**.

Entwürfe des Assistenten erscheinen in der Queue mit Herkunft „KI“ und Modell „MCP: <Token-Bezeichnung>“ —
dieselbe Entscheidung, dasselbe Audit-Log wie bei den Entwürfen des eigenen KI-Modells. Der Assistent braucht dafür
**kein** eingeschaltetes KI-Modell der Organisation.

## Rechte, Datenschutz, Nachvollziehbarkeit

- **Rechte**: Der Assistent sieht genau, was die Person des Tokens in der App sieht (Rollen, Voter, Mandant,
  aktive Module). Prüfer- und Leitungsrollen bleiben lesend; Entwürfe darf anlegen, wer über sie entscheiden darf.
- **Kein Schreiben ohne Mensch**: Es gibt kein Werkzeug, das Datensätze ändert. Werte der Erfüllung, Status und
  Freigaben bleiben in der App (Übersteuerung mit Begründung, Lifecycle, Entscheidungsdialog).
- **Datenfluss**: Was der Assistent liest, geht an ihn und seinen Anbieter (bei Claude: Anthropic). Deshalb ist der
  Zugang je Organisation standardmäßig aus; der Admin entscheidet. Personenbezogene Daten werden — anders als beim
  eigenen KI-Modell — nicht ersetzt, denn die Person liest ihre eigenen Daten.
- **Audit-Log**: jeder Werkzeugaufruf als `mcp.read` / `mcp.propose` mit Werkzeug, Datensatzart, ID, Anzahl und Token —
  nie mit Suchtext, Argumenten oder Antworten.
- **Widerruf**: *Profil > API-Tokens > Widerrufen* wirkt sofort; „Zuletzt benutzt“ zeigt, ob ein Token noch im Einsatz ist.

## Betreiber-Hinweis

- Nichts zu installieren, kein Worker, keine Kommandozeile: `/mcp` ist eine normale Route der App (Streamable HTTP
  ohne Sitzung, jede Anfrage einzeln) und läuft auch auf Shared Hosting.
- Hinter Proxy/CDN muss `Authorization` durchgereicht werden (bei Apache ggf. `CGIPassAuth On` bzw.
  `SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1`, wie für die REST API). `SYMFONY_TRUSTED_PROXIES` setzen, damit
  die Links in den Antworten den öffentlichen Host tragen.
- claude.ai-„Connectors“ im Browser verlangen OAuth und werden (noch) nicht unterstützt; Claude Desktop und Claude Code
  funktionieren mit dem Token.

## Technik (für Entwickler)

- Endpoint `App\Controller\McpController` (`POST /mcp`, GET/DELETE → 405), Protokoll `App\Mcp\McpServer`
  (JSON-RPC 2.0; Methoden `initialize`, `ping`, `tools/*`, `prompts/*`; Versionen 2025-11-25 … 2024-11-05).
  Nur Bearer-Tokens (`ApiTokenAuthenticator` setzt `_api_token`), Session-Cookies werden abgewiesen, `Origin` wird geprüft.
- Werkzeuge: `src/Mcp/Tool/*` (Tag `app.mcp_tool`). Ein neues Werkzeug nur, wenn kein generisches passt —
  neue REST-Ressourcen, Fristenquellen und Vorschlagsarten erscheinen ohne Code hier.
- `App\Mcp\ApiResourceGateway` liest die Ressourcen aus den API-Platform-Metadaten und führt deren Get/GetCollection als
  Sub-Request aus (security, Groups, Filter, Mandantenfilter); das Modul-Gate der Ressourcenklasse prüft das Gateway selbst.
- Entwürfe: `RecordDrafts::kindsFor($record, byAssistant: true)` + `AbstractAiKind::assistantBrief()` /
  `draftFromAssistant()` — dieselbe Instruktion und dieselbe Abbildung wie beim eigenen Modell.
- Opt-in: `App\Mcp\McpSettings` (`Tenant.settings['mcp']`). Texte: Domain `mcp`.
