# GSTOOL XML Import

Migration-Path für Tenants mit historischen GSTOOL-XML-Exports aus älteren BSI-IT-Grundschutz-Audits. GSTOOL wurde 2018 abgekündigt; XML-Exports liegen aber in vielen Organisationen noch vor und enthalten Strukturanalyse, Schutzbedarfsfeststellung, Modellierung und Basis-Sicherheitscheck — Daten, die nicht verloren gehen sollen.

## Zielsetzung

GSTOOL-Inhalte so in das Tool-Datenmodell überführen, dass die Daten **direkt im aktuellen BSI-IT-Grundschutz-Kompendium (2023+)** weiterverwendet werden können — ohne dass Anwender:innen die Strukturanalyse manuell neu erfassen müssen.

**Der Import ist explizit kein 1:1-Spiegel des Alt-Formats**, sondern eine **Übersetzung in die aktuelle Datenstruktur**:
- Alt-Bausteine (B 1.x, B 2.x, B 3.x …) → neue Kompendium-IDs (ISMS.x, ORP.x, CON.x, OPS.x, DER.x, APP.x, SYS.x, IND.x, NET.x, INF.x)
- Schutzbedarfskategorien GSTOOL → BSI 200-2 Standard-Skala
- Modellierungsbeziehungen → `Asset.dependsOn` (Schutzbedarfsvererbung)
- Maßnahmen-Umsetzungsstatus → `Control.implementationStatus` (mit angenommener Norm-Update-Pflicht)

## Phasen-Plan

| Phase | Inhalt | Format-Übersetzung | Status |
|---|---|---|---|
| **Phase 1** (MVP) | Zielobjekte → `Asset` mit Schutzbedarf C/I/A | Skala-Normalisierung (4-stufig BSI → 5-stufig Tool) | ✅ implementiert |
| **Phase 2** | Modellierungs-Hierarchie | `Zielobjekt.modelledIn` → `Asset.dependsOn` (BSI 3.6 Maximumprinzip greift) | offen |
| **Phase 3** | Bausteine-Zuordnung | Alt-Baustein-IDs → Kompendium-2023-IDs via Migration-Tabelle | offen |
| **Phase 4** | Maßnahmen + Umsetzungsstatus | Alt-M-IDs → Kompendium-Anforderungen; Status erhalten + mit `migrationNote`-Flag | offen |
| **Phase 5** | Risikoanalyse-Einträge | → `Risk` mit `inherentRisk` aus GSTOOL-Schwellenwerten | offen |

## Eingabe-Format (v1)

Pragmatisches XML-Schema (`gstool_xml_v1`) für die Implementierung. Echte GSTOOL-XML-Exports werden via XSLT/Begleit-Skript in dieses Schema vorab transformiert. Vorteil dieses Zwischenformats: Tests werden lesbar, Sample-Files sind klein, Schema-Änderungen am Tool brechen nicht das Import-Verhalten.

```xml
<?xml version="1.0" encoding="UTF-8"?>
<gstool-export version="1.0">
  <metadata>
    <created>2024-01-15</created>
    <bsi-version>2023</bsi-version>
    <tenant-hint>example-gmbh</tenant-hint>
  </metadata>
  <zielobjekte>
    <zielobjekt id="ZO-001" type="IT-System">
      <name>Webserver-Production</name>
      <kurzbeschreibung>Hauptwebserver für Kundenportal</kurzbeschreibung>
      <verantwortlich>IT-Abteilung</verantwortlich>
      <standort>RZ-Nord</standort>
      <schutzbedarf>
        <vertraulichkeit>hoch</vertraulichkeit>
        <integritaet>sehr hoch</integritaet>
        <verfuegbarkeit>hoch</verfuegbarkeit>
      </schutzbedarf>
    </zielobjekt>
  </zielobjekte>
</gstool-export>
```

### Schutzbedarf-Mapping (BSI 200-2 4-stufig → Tool 5-stufige Skala)

GSTOOL nutzt 3-stufig (oft 4-stufig erweitert), BSI 200-2 4-stufig. Tool intern 1–5. Mapping ist verlustfrei in eine Richtung:

| GSTOOL-Wert | BSI 200-2 | Tool-CIA-Score |
|---|---|---|
| `niedrig` / `gering` | normal | 1 |
| `normal` | normal | 2 |
| `hoch` | hoch | 4 |
| `sehr hoch` / `sehrhoch` | sehr hoch | 5 |

Wert 3 wird im Import nicht vergeben (Sprung 2 → 4 entspricht BSI-Vier-Stufigkeit). Tenants können nach Import auf Wert 3 umstufen, wenn die internere 5-Stufen-Logik genutzt werden soll.

### Asset-Type-Mapping (GSTOOL Schichten → Asset.assetType)

| GSTOOL `type` (häufige Werte) | BSI-Schicht 2023 | `Asset.assetType` |
|---|---|---|
| `IT-System` / `Server` / `Client` | SYS | `it_system` |
| `Anwendung` / `Software` / `Geschäftsanwendung` | APP | `application` |
| `Raum` / `Gebäude` | INF | `physical_facility` |
| `Netz` / `Netzwerk` / `Kommunikationsnetz` | NET | `network` |
| `Mitarbeiter` / `Personal` / `Rolle` | ORP | `personnel` |
| `Information` / `Datenobjekt` | CON | `information` |
| `Geschäftsprozess` | n/a | `business_process` |
| sonst | — | `other` |

Schicht-Information wird zusätzlich in `Asset.bsiLayer` (kommt in Phase 3) abgelegt für spätere Baustein-Zuordnung.

## Migration-Tabelle Alt-Bausteine → Kompendium 2023 (Phase 3)

Die Migration ist nicht 1:1 — viele Alt-Bausteine sind in 2 oder mehr Kompendium-Bausteine aufgeteilt worden. Daten-Strategie:

- **Eindeutige Migration**: direkter Map (z.B. `B 3.101` → `SYS.1.1`)
- **Aufgespaltene Migration**: Wahl + Hinweis (`B 1.6` → `OPS.1.1.4` ODER `OPS.1.1.7`, abhängig vom Inhalt) — Default OPS.1.1.4 + `Control.migrationNote`
- **Zusammengeführte Migration**: ein Alt-Baustein deckt jetzt mehrere Kompendium-Anforderungen ab → alle erzeugen, mit Cross-Link
- **Entfallene Bausteine**: in Audit-Note bei der Migration vermerken, kein Control erzeugen

Mapping-Tabelle wird als YAML in `fixtures/migrations/gstool-to-kompendium-2023.yaml` abgelegt — versionierbar, von Compliance-Manager wartbar.

## API

### CLI

```bash
php bin/console app:import-gstool-xml --tenant=<id> --file=path/to/export.xml [--dry-run]
```

### Service

```php
$importer = $container->get(App\Service\Import\GstoolXmlImporter::class);

$preview = $importer->analyse('/path/to/export.xml', $tenant);
// $preview['rows'] = preview rows mit "wäre-neu" / "wäre-update"
// $preview['summary'] = ['new' => 12, 'update' => 3, 'error' => 0]
// $preview['header_error'] = null oder Schema-Validierungsmeldung

$result = $importer->apply('/path/to/export.xml', $tenant);
// schreibt in DB innerhalb einer Transaction; legt ImportSession + ImportRowEvents an
```

## Sicherheits-Aspekte

- **XML External Entity (XXE)**: externe Entities deaktiviert, `LIBXML_NONET | LIBXML_NOENT` beim Parsen
- **Tenant-Scoping**: Import IMMER an Tenant gebunden; cross-tenant Übernahme unmöglich
- **Idempotenz**: Asset-Match per `(tenant_id, name)` Constraint — wiederholter Import aktualisiert statt dupliziert
- **Audit-Trail**: Import-Session in `ImportSession` festgehalten, jede Zeile als `ImportRowEvent` mit `before/after`-Snapshot
- **Aufwands-Pfad**: Phase-Migration ist explizit dokumentiert; kein "Black-Box-Import"

## Was später noch dazukommt

- **Maßnahmen-Status-Übernahme** (Phase 4): GSTOOL-`Umsetzungsstatus` (`vollstaendig`, `weitestgehend`, `teilweise`, `entbehrlich`, `nein`) → `Control.implementationStatus` (`implemented`, `partially_implemented`, `not_applicable`, `not_implemented`). Stati 1:1 nicht abbildbar (`weitestgehend` ↔ `partially_implemented` mit Anteil-Hinweis).
- **Risikoanalyse** (Phase 5): GSTOOL-Risikoanalyse-Tabellen → `Risk`-Entities mit Asset- und Maßnahmen-Verknüpfung. Schwellenwerte aus GSTOOL übernommen, Initial-Inherent-Risk vorbelegt.
- **XSLT für echte GSTOOL-Exports**: separates `tools/gstool-export-to-v1.xsl` für die Vorab-Transformation der Original-XML-Exports in das v1-Schema. Damit User keine manuelle Konvertierung machen müssen.
