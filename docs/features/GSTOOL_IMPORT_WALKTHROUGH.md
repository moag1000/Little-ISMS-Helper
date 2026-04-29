# GSTOOL Import — Step-by-Step Walkthrough

End-to-end Anleitung für Tenants, die einen historischen GSTOOL-XML-Export ins aktuelle BSI-IT-Grundschutz-Modul migrieren wollen.

## Voraussetzungen

- GSTOOL XML-Export (üblicherweise via "Datei → Exportieren → XML" oder GSTOOL-Exportwerkzeug)
- ROLE_ADMIN-Account im Tool, ein aktiver Tenant
- PHP-Extension `ext-xsl` aktiv (für die Transformation; auf den meisten LAMP-Stacks Standard)
- Optional `xsltproc` als CLI-Alternative

## Schritt-für-Schritt

### 1. Original-Export sichten

Real GSTOOL-XMLs nutzen ISO-8859-1 Encoding und SQL-Server-Tabellen-shape:

```xml
<?xml version="1.0" encoding="ISO-8859-1"?>
<gstool>
  <NZIELOBJEKT>
    <N_ID>1</N_ID>
    <N_NAME>Webserver-Production</N_NAME>
    <N_NTYP_ID>2</N_NTYP_ID>
    <NZB_VERTRAULICHKEIT>2</NZB_VERTRAULICHKEIT>
    <NZB_INTEGRITAET>3</NZB_INTEGRITAET>
    <NZB_VERFUEGBARKEIT>2</NZB_VERFUEGBARKEIT>
    ...
  </NZIELOBJEKT>
  <NTYP>
    <N_ID>2</N_ID>
    <N_BEZ>IT-System</N_BEZ>
  </NTYP>
  <MOD_BAUSTEIN>
    <MO_NID>1</MO_NID>
    <MO_BSTID>105</MO_BSTID>
  </MOD_BAUSTEIN>
  <MB_BAUSTEIN>
    <BST_ID>105</BST_ID>
    <BST_BEZ>B 3.101 Allgemeiner Server</BST_BEZ>
  </MB_BAUSTEIN>
  ...
</gstool>
```

Schutzbedarf-Werte sind numerisch (1=normal, 2=hoch, 3=sehr hoch). Umsetzungsstatus ebenso.

### 2. Encoding zu UTF-8 konvertieren

```bash
iconv -f ISO-8859-1 -t UTF-8 export.xml > export-utf8.xml
sed -i 's/encoding="ISO-8859-1"/encoding="UTF-8"/' export-utf8.xml
```

### 3. Transform in v1-Schema

#### Variante A — Tool-Command (empfohlen)

```bash
php bin/console app:transform-gstool-xml \
    --in=export-utf8.xml \
    --out=export-v1.xml
```

#### Variante B — `xsltproc` direkt

```bash
xsltproc tools/gstool-export-to-v1.xslt export-utf8.xml > export-v1.xml
```

### 4. XSLT-Anpassungen prüfen

Das mitgelieferte XSLT (`tools/gstool-export-to-v1.xslt`) deckt die häufigste GSTOOL-Export-Tool-Variante ab. Wenn dein Export andere Feld-Namen nutzt, passe die `match`-/`select`-Ausdrücke an. Häufige Variationen:

| GSTOOL-Tool-Version | Element-Namen |
|---|---|
| 4.x (GSTOOL-Export-Tool 1.6) | `NZIELOBJEKT`, `NTYP`, `MOD_BAUSTEIN`, `MB_BAUSTEIN` |
| 5.x | teils ohne `_`-Prefix; Felder wie `NID` statt `N_ID` |
| Custom-Reports | proprietäre Tabellennamen — manuelle XSLT-Adaption nötig |

XSLT enthält `match="X | Y | Z"`-Alternationen für die häufigsten Varianten. Wenn bei dir `out.xml` leer ist, nutzt dein Export andere Tags.

### 5. Validierung der v1-Datei

```bash
php bin/console app:import-gstool-xml \
    --tenant=<tenant-id-oder-slug> \
    --file=export-v1.xml \
    --dry-run
```

Output: Tabellarisches Preview pro Phase mit Zeilen-Status (new/update/error).

### 6. Live-Import

#### Variante A — Über das UI

1. Login als ROLE_ADMIN, Tenant wählen
2. `/admin/import/gstool` öffnen
3. `export-v1.xml` hochladen
4. **Vorschau (kein Schreiben)** ankreuzen, `Importieren` klicken
5. Tabs `Assets` / `Bausteine` / `Maßnahmen` / `Risiken` durchgehen, Migration-Status pro Baustein verifizieren
6. Wenn alles passt: erneut hochladen, **Vorschau** abhaken, commit

#### Variante B — CLI

```bash
php bin/console app:import-gstool-xml \
    --tenant=<tenant-id-oder-slug> \
    --file=export-v1.xml
```

### 7. Post-Import Review (Compliance Manager)

Nach dem Commit sind diese Daten geschrieben:
- **Assets** (Phase 1) — direkt in `asset`-Tabelle
- **Asset.dependsOn** (Phase 2) — Modellierungs-Hierarchie

Diese Daten sind nur als Audit-Trail-Vorschlag in `import_session_event` gelandet (kein Auto-Entity):
- **Bausteine-Mapping** (Phase 3) — Compliance-Manager prüft, welche Bausteine `direct`/`split`/`merged`/`removed` sind und legt manuell `ComplianceRequirement`-Verknüpfungen an
- **Maßnahmen-Status** (Phase 4) — pro Maßnahme entscheiden, ob daraus ein `Control` mit dem geerbten `implementationStatus` angelegt werden soll
- **Risikoanalyse** (Phase 5) — pro Risiko entscheiden, ob daraus ein `Risk` mit `inherent_risk_score` angelegt wird; RiskAppetite-Vergleich als Folgeschritt

**Hintergrund:** Phase 3-5-Daten sind nicht 1:1 maschinell entscheidbar, weil Bausteine gesplittet/gemerged wurden und die Behandlung von Risiken Tenant-spezifisch ist.

## Häufige Probleme

### Encoding-Fehler beim Transform

> "XML parse error: input not proper UTF-8"

Lösung: Schritt 2 (iconv) erneut ausführen, sicher gehen dass die XML-Header-Deklaration auf `encoding="UTF-8"` umgeschrieben wurde.

### Leerer `<zielobjekte>`-Block in v1-Output

XSLT findet `NZIELOBJEKT` nicht. Inspect `export-utf8.xml`:

```bash
xmllint --xpath "name(/*)" export-utf8.xml
xmllint --xpath "//*[contains(name(), 'ZIELOBJEKT')]" export-utf8.xml | head -5
```

Falls dein Export `<ZIELOBJEKT>` (ohne `N_`-Prefix) verwendet, im XSLT die `match`-Klauseln entsprechend anpassen.

### Unbekannte Bausteine

Phase-3-Tab zeigt rote "kein Mapping"-Marker. Lösungswege:
1. `fixtures/migrations/gstool-to-kompendium-2023.yaml` um deinen Baustein erweitern
2. Tenant-spezifisch über manuelle ComplianceRequirement-Anlage abdecken
3. Wenn der Baustein im neuen Kompendium entfallen ist (z.B. `B 3.402 Anrufbeantworter`): Migration als `removed` markieren

### Schutzbedarf 4-stufig vs. Tool 5-stufig

Tool nutzt 1-5, Mapping springt absichtlich von 2 auf 4 (Wert 3 wird nicht vergeben um keine Information zu erfinden). Tenants können nach dem Import auf Wert 3 umstufen.

## Mitgelieferte Werkzeuge — Übersicht

| Datei | Zweck |
|---|---|
| `tools/gstool-export-to-v1.xslt` | XSLT 1.0 Transform für reale GSTOOL-Exports |
| `src/Command/TransformGstoolXmlCommand.php` | `app:transform-gstool-xml` CLI |
| `src/Command/ImportGstoolXmlCommand.php` | `app:import-gstool-xml` CLI |
| `src/Service/Import/GstoolXmlImporter.php` | Service mit `analyse()` + `apply()` |
| `src/Service/Import/GstoolMigrationTable.php` | Lädt das Migrations-YAML |
| `src/Controller/Admin/GstoolImportController.php` | Admin-UI |
| `fixtures/migrations/gstool-to-kompendium-2023.yaml` | 90+ kuratierte Baustein-Mappings |
| `tests/Fixtures/gstool/sample-zielobjekte-v1.xml` | Sample im v1-Schema |
| `docs/features/GSTOOL_IMPORT.md` | Architektur-Spec |

## Sicherheits-Aspekte

- **XXE**: Importer und Transformer nutzen `LIBXML_NONET` — externe Entities werden nicht geladen
- **Tenant-Scoping**: Import IMMER an den aktiven Tenant gebunden, cross-tenant nicht möglich
- **Audit-Trail**: jede Zeile als `ImportRowEvent` mit before/after-Snapshot

## Quellen

- BSI IT-Grundschutz-Kataloge 15. EL (April 2016) — https://download.gsb.bund.de/BSI/ITGSK/IT-Grundschutz-Kataloge_2016_EL15_DE.pdf
- BSI IT-Grundschutz-Kompendium Edition 2023 — https://www.bsi.bund.de/DE/Themen/Unternehmen-und-Organisationen/Standards-und-Zertifizierung/IT-Grundschutz/IT-Grundschutz-Kompendium/it-grundschutz-kompendium_node.html
- BSI 200-2 IT-Grundschutz-Methodik — https://www.bsi.bund.de/SharedDocs/Downloads/DE/BSI/Grundschutz/BSI_Standards/standard_200_2.pdf
- BSI 200-3 Risikoanalyse — https://www.bsi.bund.de/SharedDocs/Downloads/DE/BSI/Grundschutz/BSI_Standards/standard_200_3.pdf
- BSI Kreuzreferenztabellen 2023 — https://www.bsi.bund.de/SharedDocs/Downloads/DE/BSI/Grundschutz/IT-GS-Kompendium/krt2023_Excel.html
