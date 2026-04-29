# Public Cross-Framework Mappings

> **Lizenz:** CC-BY 4.0 — frei nutzbar, Namensnennung bei Weitergabe.
> **Quelle:** kuratiert aus öffentlichen Referenzkatalogen (siehe pro Datei).
> **Pflege:** Compliance-Manager intern (Initial-Kuration durch Senior-GRC-Consultant).
> **Beschluss-Grundlage:** `docs/DATA_REUSE_IMPROVEMENT_PLAN.md` Anhang C, Entscheidung ENT-3 (Hybrid-Modell).

## Zweck
Seed-Daten für `ComplianceMapping`. Werden vom Deployment-Wizard beim Tenant-Setup angeboten und über `DataImportService` importiert. Tenant-spezifische oder Branchen-Kuratierungen gehören **nicht** hierher — die importiert der Tenant selbst (siehe `_templates/`).

## Verzeichnisstruktur

```
fixtures/mappings/
├── README.md                               ← dieses File
├── public/
│   └── nis2_iso27001_v1.csv               ← ENISA-basiert, ~40 Zeilen, auditfest
└── _templates/
    └── import_template_v1.csv             ← leere Vorlage für eigene Kataloge
```

## Struktur (12 Pflichtspalten)

| Spalte | Typ | Pflicht | Beschreibung |
|---|---|---|---|
| `source_framework` | string | ja | Framework-Code (z.B. `NIS2`, `ISO27001`, `DORA`). |
| `source_requirement_id` | string | ja | Requirement-ID im Framework (z.B. `Art.21.2.a`, `A.5.1`). |
| `target_framework` | string | ja | analog, Ziel-Framework. |
| `target_requirement_id` | string | ja | analog, Ziel-Requirement. |
| `mapping_percentage` | int (0–150) | ja | Überdeckungsgrad. 100 = vollständig, >100 = "exceeds". |
| `mapping_type` | enum | ja | `weak` / `partial` / `full` / `exceeds`. |
| `confidence` | enum | ja | `low` / `medium` / `high`. |
| `bidirectional` | bool | ja | `true` nur wenn Mapping in beide Richtungen ≥75 % (siehe MINOR-F5 im Consultant-Audit). |
| `rationale` | text | ja | 3-Teile-Schema: *Was gemappt / Warum / Quelle*. Mindestens 40 Zeichen. |
| `source_catalog` | string | ja | z.B. `enisa_nis2_annex_c_2024`, `bsi_kreuzreferenz_v1.2`. Steuert `ComplianceMapping.source`. |
| `validated_at` | YYYY-MM-DD | ja | Datum der Validierung. |
| `validated_by` | string | ja | Rolle/Person. |

## Pflege-Workflow

1. **Update der Referenzquelle** (z.B. ENISA veröffentlicht Annex C v2) → CSV-Delta erstellen.
2. **Version erhöhen** im Dateinamen (`nis2_iso27001_v1.csv` → `v2.csv`).
3. Alte Datei **nicht löschen** — bleibt für Stichtag-Reproduzierbarkeit (Plan MAJOR-2).
4. `ComplianceMapping.source` Werte müssen zur neuen Version passen.
5. Command `app:compliance:preview-inheritance --source-catalog=X` simuliert Auswirkung.
6. Rollout via Import-Wizard (WS-2), nicht automatisch.

## Re-Analyse-Intervall
**Halbjährlich.** Re-Run von `app:analyze-mapping-quality --reanalyze --framework=NIS2` gegen aktuelle Kataloge. Abweichungen > 15 % im `calculatedPercentage` triggern Review.

## Nicht hier: Branchen-Baselines, Kundenspezifika
Diese bleiben beim Consultant bzw. beim jeweiligen Tenant. Markierung über `ComplianceMapping.source` mit Werten wie `consultant_{firma}_branche_{xy}` oder `manual_tenant_specific`.
