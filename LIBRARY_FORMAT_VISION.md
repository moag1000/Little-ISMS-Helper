# Library-Format-Architektur (Vision)

**Status:** Konzept · 2026-04-25
**Fokus:** DE/EU — BSI · NIS2 · DORA · DSGVO · BDSG · KRITIS · TISAX · BSI C5

---

## Problem heute

- **Frameworks** leben halb in `config/modules.yaml` (Metadaten), halb in der DB (Requirements/Controls), halb in Excel-Importen.
- **Cross-Framework-Mappings** entstehen on-the-fly per Service-Code statt als versioniertes Artefakt.
- **Branchen-Profile** (`IndustryBaseline`) sind DB-Records → kein Diff bei Versions-Updates möglich.
- **Norm-Updates** (z.B. ISO 27001:2013 → 2022) erfordern Daten-Migration statt einer Library-Datei zu ersetzen.
- **Auditierbarkeit:** Welche Framework-Version war zum Stichtag 2026-Q1 gültig? Heute mühsam zu rekonstruieren.

## Vision

Drei Library-Typen, alle als git-versioniertes YAML in `fixtures/library/`:

```
fixtures/library/
├── frameworks/
│   ├── iso27001-2022.yaml
│   ├── bsi-it-grundschutz-kompendium-2024.yaml
│   ├── bsi-c5-2020.yaml
│   ├── bsi-c5-2026.yaml
│   ├── bsi-mindeststandard-cloud-v2-1.yaml
│   ├── nis2-directive.yaml
│   ├── nis2-umsucg.yaml          # DE-Umsetzung
│   ├── dora.yaml
│   ├── dora-rts-ict-risk-management.yaml
│   ├── gdpr.yaml
│   ├── bdsg.yaml
│   ├── tisax-vda-isa-6.yaml
│   ├── kritis-dachgesetz.yaml
│   └── eu-ai-act.yaml
├── mappings/
│   ├── iso27001-2022_to_nis2-art21.yaml
│   ├── iso27001-2022_to_dora.yaml
│   ├── iso27001-2022_to_bsi-c5-2020.yaml
│   ├── gdpr_to_iso27701-2025.yaml
│   ├── tisax-vda-isa-6_to_iso27001-2022.yaml
│   └── kritis-dachgesetz_to_nis2-umsucg.yaml
└── presets/
    ├── de-mittelstand-nis2.yaml          # 50–250 MA, NIS2-pflichtig
    ├── de-bafin-financial.yaml           # Finanzdienstleister BaFin + DORA
    ├── kritis-energie.yaml               # KRITIS Sektor Energie
    ├── kritis-health.yaml                # Krankenhaus, KHZG/§75c SGB V
    ├── automotive-tier1.yaml             # OEM-Zulieferer, TISAX-pflichtig
    └── pharma-life-sciences.yaml         # GxP + DSGVO + ISO 27001
```

## Schema-Skizze

### Framework-Library (`fixtures/library/frameworks/<id>.yaml`)

```yaml
schema_version: '1.0'
library:
  type: framework
  id: 'iso27001-2022'
  name: 'ISO/IEC 27001:2022'
  short_name: 'ISO 27001'
  description: '…'
  publisher:
    name: 'ISO/IEC'
    url: 'https://www.iso.org/standard/27001'
  version: '2022'
  effective_from: '2022-10-25'
  language: 'en'
  locale_overrides:
    de: 'fixtures/library/frameworks/iso27001-2022.de.yaml'  # optional DE-Übersetzung
  license: 'commercial'  # frameworks/iso/...; bei BSI: 'public-domain'
  external_id_format: 'A.{section}.{control}'  # z.B. A.5.23

requirements:
  - id: 'CL.4.1'
    type: 'clause'         # clause | annex_a_control
    title: 'Understanding the organization and its context'
    text: '…'
    parent_id: null
    references:
      - { type: 'iso', value: '27001:2022 Clause 4.1' }
  - id: 'A.5.23'
    type: 'annex_a_control'
    title: 'Information security for use of cloud services'
    text: '…'
    category: 'organizational'
    attributes:           # ISO 27001:2022 Anhang A Attribute-Taxonomie
      control_type: ['preventive']
      info_security_properties: ['confidentiality', 'integrity', 'availability']
      cybersecurity_concept: ['protect']
      operational_capabilities: ['supplier_relationships_security']
      security_domain: ['protection']
```

### Mapping-Library (`fixtures/library/mappings/<a>_to_<b>.yaml`)

```yaml
schema_version: '1.0'
library:
  type: mapping
  source_framework: 'iso27001-2022'
  target_framework: 'nis2-art21'
  version: '1.2'
  effective_from: '2024-09-01'
  methodology: 'ENISA Guidance 2024-09 + Intuitem CISO-Assistant Mappings + Eigene Reviews durch Compliance-Team'
  publisher: 'Little ISMS Helper Maintainers'

mappings:
  - source: 'A.5.7'      # ISO 27001 Annex A.5.7 Threat Intelligence
    target: 'NIS2.21.2.f'  # Threat Intelligence Sharing
    relationship: 'equivalent'   # equivalent | subset | superset | related
    confidence: 'high'           # high | medium | low
    notes: 'NIS2 Art. 21(2)(f) verlangt explizit threat intelligence sharing — A.5.7 deckt Aufbau ab.'
  - source: 'A.5.30'     # ICT Readiness for BC
    target: 'NIS2.21.2.c'
    relationship: 'subset'
    confidence: 'medium'
    notes: 'NIS2 verlangt umfassendere BC-Maßnahmen inkl. Krisenmanagement; A.5.30 ist nur ICT-Readiness.'
```

### Preset-Library (`fixtures/library/presets/<id>.yaml`)

```yaml
schema_version: '1.0'
library:
  type: preset
  id: 'de-mittelstand-nis2'
  name: 'DE-Mittelstand mit NIS2-Pflicht'
  description: '50–250 MA, NIS2-pflichtig, ISO 27001 als Kern, NIS2-UmsuCG umzusetzen.'
  applicability:
    countries: ['DE']
    employee_count_range: [50, 250]
    industries: ['manufacturing', 'energy', 'healthcare', 'transport']

# Frameworks die geladen werden
frameworks:
  - { id: 'iso27001-2022', mode: 'mandatory' }
  - { id: 'nis2-directive', mode: 'mandatory' }
  - { id: 'nis2-umsucg', mode: 'mandatory' }
  - { id: 'gdpr', mode: 'mandatory' }
  - { id: 'bdsg', mode: 'mandatory' }
  - { id: 'iso22301', mode: 'recommended' }

# Mappings die aktiviert werden
mappings:
  - 'iso27001-2022_to_nis2-art21'
  - 'iso27001-2022_to_dora'  # für Wachstumsfall

# Initial-Defaults
defaults:
  risk_appetite:
    - { category: 'compliance', threshold: 'low' }
    - { category: 'operational', threshold: 'medium' }
  modules: ['core', 'risks', 'incidents', 'compliance', 'audit', 'bcm']
```

## Migration-Pfad (in 4 Phasen)

### Phase A — Schema fixieren (1 Tag)
- JSON-Schema unter `docs/library-schema.json` erstellen
- `LibraryValidatorCommand` (`bin/console app:library:validate`)
- Test: bestehende `config/modules.yaml`-Daten ins neue Schema migrieren — nur als Validation, ohne Daten-Migration

### Phase B — Erste Frameworks als Library (2-3 Tage)
- `iso27001-2022.yaml`, `nis2-directive.yaml`, `gdpr.yaml`, `bsi-it-grundschutz-kompendium-2024.yaml`, `dora.yaml`, `bdsg.yaml`
- `LibraryLoaderService::load(Framework $library, Tenant $tenant)` — analog zu DataImportService
- Doppelschiene: Library-Loader **zusätzlich** zu existierendem ComplianceFrameworkLoaderService — Coexistenz, kein Big-Bang

### Phase C — Mappings als Library (1-2 Tage)
- 5 wichtigste Mappings: ISO27001→NIS2, ISO27001→DORA, GDPR→ISO27701, ISO27001→BSI-C5, TISAX→ISO27001
- `MappingLibraryLoader` — bestehende `compliance_mapping`-Tabelle als Target

### Phase D — Presets + UI (2-3 Tage)
- 4 Presets: de-mittelstand-nis2, de-bafin-financial, kritis-energie, automotive-tier1
- Setup-Wizard step8: Branchen-Profil wählen → automatisches Preset-Loading
- Admin-UI „Library-Browser": welche Frameworks/Mappings/Presets verfügbar, Versionen, Diff zwischen Versionen

## Was NICHT in der Library landet

- **Tenant-spezifische Daten** (eigene Risiken, Assets, Incidents) — die bleiben in DB
- **User-eigene Custom-Frameworks** — die landen als `tenant_<id>/custom-frameworks/...` (außerhalb Git, ggf. exportierbar)
- **Audit-Befunde / Findings** — Tenant-Daten, nie Library
- **Workflow-Definitionen** — bleiben in `config/`

## Lizenz-Strategie

- **Library-Files unter Apache 2.0 / CC-BY 4.0** im Hauptrepo veröffentlichen
- **Inhalte aus AGPL-Drittquellen** NICHT übernehmen — selbst ableiten oder aus öffentlichen Standards rekonstruieren
- **Kommerzielle Frameworks** (ISO 27001, ISO 27701, ISO 22301) — nur Identifier + Struktur, kein Volltext (urheberrechtlich problematisch); Volltext aus offiziellen Excel/Word vom Kunden importiert
- **Public-Domain-Frameworks** (BSI IT-Grundschutz, NIS2, DORA, GDPR) — Volltext zulässig

## Was wir NICHT übernehmen (bewusste Abweichung)

- **Python-Backend** — wir bleiben Symfony/Doctrine
- **Svelte-Frontend** — wir bleiben Twig + Stimulus
- **API-First-Ansatz** — wir haben begrenzte API; ausbauen ist eigene Sub-Initiative
- **Multi-Paradigm-Risk** — bleibt vorerst 5×5 default (siehe `risk-management-specialist`-Skill für CRQ/EBIOS-Optionen)

## Erfolgsmetrik

Nach voller Umsetzung:
- **Time-to-onboard new framework** < 1 Stunde (statt heute halber Tag mit Code-Review)
- **Mapping-Audit-Trail** vollständig durch Git-History
- **Preset-Aktivierung** ein-Klick im Wizard
- **Community-Contributions** möglich (PR mit YAML-File, kein Code-Review)
