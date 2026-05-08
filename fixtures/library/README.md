# Library Format Specification

This directory contains the compliance framework library for Little ISMS Helper:

```
fixtures/library/
├── catalogues/          # Framework requirement catalogues (one directory per framework)
│   ├── bsi-c5-2020-de/  # Per-criterion YAML files
│   ├── bsi-c5-2026-en/
│   ├── bsi-it-grundschutz-2023/
│   └── nist-csf-2-0/
└── mappings/            # Cross-framework requirement mappings (56 files, May 2026)
```

---

## Catalogue Format

Each catalogue is a directory named `{framework-id}` containing one YAML file per
domain/chapter. Individual criterion files follow this structure:

```yaml
# fixtures/library/catalogues/bsi-c5-2026-en/AM.yml  (Asset Management chapter)
- identifier: &ID_AM_01 '01'
  name: 'Asset Management Framework'
  basic:
    - identifier: &ID_AM_01B '01B'
      criterion: |
        The cloud service provider maintains an asset management framework that covers:
        1. Asset identification...
        2. Protection needs classification...
  additional_sharpen: ~
  additional_complement:
    - identifier: &ID_AM_01AC '01AC'
      criterion: 'The asset inventory is kept current through monitoring...'
  information:
    - applicable_criteria:
        - *ID_AM_01B
        - *ID_AM_01AC
      notes: 'Applicable to production environment assets only.'
```

YAML anchors (`&ID_*`) enable cross-referencing within the same catalogue.

### Framework IDs (Catalogue Directories)

| Directory | Framework | Language |
|---|---|---|
| `bsi-c5-2020-de` | BSI C5:2020 | German |
| `bsi-c5-2026-en` | BSI C5:2026 | English |
| `bsi-it-grundschutz-2023` | BSI IT-Grundschutz 2023 | German |
| `nist-csf-2-0` | NIST CSF 2.0 | English |

---

## Mapping Format

Each mapping file is a single YAML document named
`{source-framework}_to_{target-framework}_v{version}.yaml`.

```yaml
schema_version: '1.1'
library:
  type: mapping
  id: 'bsi-c5-2020_to_iso27001-2022_v1.0'
  source_framework: 'BSI-C5'
  target_framework: 'ISO27001'
  version: 1
  effective_from: '2020-02-01'
  effective_until: null

  provenance:
    primary_source: 'BSI C5:2020 Anlage A — official mapping table'
    primary_source_url: 'https://www.bsi.bund.de/...'
    secondary_sources:
      - 'Manual expert review'
    publisher: 'Little ISMS Helper Maintainers'

  methodology:
    type: 'published_official_mapping'   # or: expert_derived, community_contributed
    description: |
      Reverse-mapping from C5 criterion to ISO 27001 Annex A control.

  lifecycle:
    state: 'published'   # draft | review | approved | published | deprecated
    state_history:
      - { state: 'draft',     date: '2026-02-10', actor: 'maintainer' }
      - { state: 'published', date: '2026-04-22', actor: 'ciso-signoff' }

mappings:
  - source: 'OPS-01'           # Source framework criterion/control ID
    target: 'A.5.1'            # Target framework control ID
    relationship: 'equivalent' # equivalent | subset | superset | partial_overlap | no_mapping
    confidence: 'high'         # high | medium | low
    rationale: |
      BSI C5 OPS-01 requires a documented InfoSec policy signed by top management.
      ISO 27001 A.5.1 requires the same. BSI C5 Annex A confirms the direct mapping.
    audit_evidence_hint: 'Approved policy document with management sign-off date, annual review records.'
```

### Relationship Values

| Value | Meaning |
|---|---|
| `equivalent` | Source and target require essentially the same thing |
| `subset` | Source is a subset of target (source is narrower) |
| `superset` | Source is a superset of target (source is broader) |
| `partial_overlap` | Source and target partially overlap |
| `no_mapping` | No meaningful correspondence |

### Confidence Values

| Value | Meaning |
|---|---|
| `high` | Confirmed by official mapping table or authoritative document |
| `medium` | Derived from expert analysis of full criterion texts |
| `low` | Approximate / community-derived, needs review |

### Lifecycle States

| State | Meaning |
|---|---|
| `draft` | Work in progress — not imported into production |
| `review` | Ready for expert review |
| `approved` | Reviewed and approved — may be imported |
| `published` | Live in production library |
| `deprecated` | Superseded by a newer version |

---

## Currently Available Mappings (56 total, May 2026)

| Pair | Direction | File |
|---|---|---|
| BSI C5:2020 ↔ ISO 27001:2022 | both | `bsi-c5-2020_to_iso27001-2022_v1.0.yaml` + reverse |
| BSI C5:2020 ↔ BSI C5:2026 | both | `bsi-c5-2020_to_bsi-c5-2026_v1.0.yaml` + reverse |
| BSI C5:2020 ↔ BSI IT-Grundschutz | both | `bsi-c5-2020_to_bsi-it-grundschutz_v1.0.yaml` + reverse |
| BSI C5:2020 ↔ EUCS | both | `bsi-c5-2020_to_eucs_v1.0.yaml` + reverse |
| BSI C5:2026 ↔ ISO 27001:2022 | both | `bsi-c5-2026_to_iso27001-2022_v1.0.yaml` + reverse |
| BSI C5:2026 → ISO 27017 | one-way | `bsi-c5-2026_to_iso27017_v1.0.yaml` |
| BSI C5:2026 ↔ NIS2 Art. 21 | both | `bsi-c5-2026_to_nis2-art21_v1.0.yaml` + reverse |
| BSI IT-Grundschutz ↔ ISO 27001:2022 | both | ... |
| BSI IT-Grundschutz → NIS2 Art. 21 | one-way | ... |
| DORA ↔ NIS2 Art. 21 | both | ... |
| DORA ↔ ISO 27001:2022 | both | ... |
| DORA ↔ BaFin BAIT (legacy) | both | ... |
| NIS2 ↔ EU AI Act | one-way | ... |
| NIS2-UmsuCG → DORA / KRITIS-DachG | one-way | ... |
| EU AI Act ↔ GDPR / ISO 42001 | both | ... |
| EUCS ↔ ISO 27001:2022 | both | ... |
| ISO 27001:2022 ↔ EUCS | both | ... |
| CRA ↔ NIS2 Art. 21 | both | `cra_to_nis2-art21_v1.0.yaml` + reverse |
| NIST CSF 2.0 → ISO 27001:2022 | one-way | ... |
| TISAX VDA ISA 6 → ISO 27001:2022 | one-way | ... |

---

## Contributing a New Mapping

### Process

1. **Fork** the repository and create a `docs/mappings` branch.
2. **Create** a new file following the naming convention:
   ```
   fixtures/library/mappings/{source}_to_{target}_v{N}.{M}.yaml
   ```
3. **Set lifecycle state to `draft`** initially.
4. **Fill provenance** — cite the primary source URL. Do not submit without a citable source.
5. **Add mapping pairs** — one entry per source/target pair. Provide rationale for all
   `partial_overlap` and `no_mapping` entries.
6. **Open a PR** with label `library-mapping`. Include the primary source document or URL.
7. Maintainer sets `review` → `approved` after expert check.
8. On merge to `main`, state moves to `published` automatically via CI.

### Naming Conventions for Framework IDs

Use lowercase, hyphenated identifiers matching the format of existing files:

| Framework | ID used |
|---|---|
| ISO 27001:2022 | `iso27001-2022` |
| BSI C5:2020 | `bsi-c5-2020` |
| BSI C5:2026 | `bsi-c5-2026` |
| BSI IT-Grundschutz | `bsi-it-grundschutz` |
| DORA | `dora` |
| NIS2 Art. 21 | `nis2-art21` |
| EU AI Act | `eu-ai-act` |
| NIST CSF 2.0 | `nist-csf-2-0` |
| TISAX VDA ISA 6 | `tisax-vda-isa-6` |

### Quality Bar for Community PRs

- Primary source must be publicly accessible (URL or DOI).
- Methodology type must be accurate (`published_official_mapping` only if an
  official mapping table exists; use `expert_derived` otherwise).
- All `high`-confidence entries must cite the specific section of the primary source.
- At least one reviewer with relevant framework knowledge must approve.

---

## JSON Schema Validation

A JSON Schema for validating mapping files is available at `docs/library-schema.json`
(if present). To validate locally:

```bash
# Using ajv-cli (npm install -g ajv-cli)
ajv validate -s docs/library-schema.json -d fixtures/library/mappings/your-new-mapping.yaml

# Or use yq + Python
python3 -c "
import yaml, json, jsonschema
schema = json.load(open('docs/library-schema.json'))
data = yaml.safe_load(open('fixtures/library/mappings/your-new-mapping.yaml'))
jsonschema.validate(data, schema)
print('Valid')
"
```

CI validates all mapping files automatically on every PR.
