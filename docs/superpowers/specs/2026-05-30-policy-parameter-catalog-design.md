# Policy-Parameter-Katalog + Industry-Baselines — Design

**Datum:** 2026-05-30
**Status:** Design (Review)
**Kernmarkt:** DACH-Mittelstand-ISMS

## Problem

Der Policy-Wizard erfasst heute Standards (Frameworks), aber viele Policy-Inhalte
sind **Organisationsentscheidungen**, die Normen bewusst offen lassen ("die
Organisation legt fest…"): MFA-Geltungsbereich, Passwort-Mindestlänge,
Log-Aufbewahrung, Patch-SLA, Klassifizierungsschema, RTO/RPO, Freigabemodell …

Generische GRC-Tools bleiben auf **Reifegrad 2** (Framework-Checkliste). Niemand
verbindet **konkrete Parameter-Werte + Sektor-Presets + Cross-Framework-Constraint-
Propagation** zu einer audit-festen, widerspruchsfreien Policy-Suite. Genau das
ist der Differenzierer (Reifegrad 3–4) und der Nutzen für den ressourcenarmen
Mittelstand: **Sektor wählen → konsistente, verteidigbare Policy-Suite in Minuten.**

## Leitprinzip: das Abstimmungs-Dreieck

```
              Parameter-Katalog  (Schema = eine Wahrheit)
              /         |          \
       Frameworks    Org-Profil   Industry-Baseline
       (Constraints) (Tenant-Wert)(Sektor-Preset)
```

Alle drei Achsen referenzieren **dieselben Katalog-Keys** → strukturell
abgestimmt, kein Drift. Frameworks aus dem Wizard verschärfen Parameter
automatisch; Baselines sind benannte Presets desselben Katalogs.

## Architektur (Ansatz A: Library-YAML + DB-Tenant-Daten)

Passt zum bestehenden Muster (Workflows-YAML, Lifecycle-YAML + DB-Override).

**Geshippte, versionierte YAML (Schema + Presets, git-diffbar, community-fähig):**
- `config/policy_parameters/*.yaml` — Parameter-Katalog (Nabe, ~27 Params)
- `config/industry_baselines/*.yaml` — 4 Sektor-Presets

**DB (Tenant-Daten):**
- `OrganizationSecurityProfile` (1 pro Tenant) — gewählte Werte, **Single Source
  of Truth**, reusable in Risk/Audit/SoA/Control. `#[ORM\Version]` für Optimistic-Lock.
- Lauf-Override — JSON-Feld im bestehenden `WizardRun` (Delta gegen Profil).

**Katalog-Eintrag (Schema):**
```yaml
mfa_scope:
  category: access_control          # A–H Gruppe
  type: enum
  allowed: [all, privileged_external, privileged_only, none]
  default: privileged_external      # Markt-Benchmark Mittelstand
  iso_clauses: [A.8.5, A.5.17]
  framework_constraints:
    dora: { min: all, authority: regulatory, source: "DORA Art. 9(3)" }
    nis2: { min: privileged_external, authority: regulatory, source: "NIS2 Art. 21(2)(i)" }
  template_slot:
    interpolate: policy.access.mfa_value
    section_if: { not: none }
  wizard_step: governance_controls
  labels: { de: "MFA-Geltungsbereich", en: "MFA scope" }
```

**Auflösung (wie `LifecycleConfigResolver`):**
```
effektiv = Lauf-Override ?? Tenant-Profil ?? Baseline-Preset ?? Katalog-Default
         + Framework-Constraint-Check (erzwingt Min bei authority: regulatory)
```

## Parameter-Katalog v1 (~27)

Jeder Param trägt: `type` · `allowed`/range · `default` (Markt-Benchmark) ·
`iso_clauses` · `framework_constraints` (mit `authority`+`source`) ·
`template_slot` · `wizard_step` · `labels{de,en}`.

| Gruppe | Params |
|---|---|
| **A Governance** | `approval_model` · `review_cadence` · `exception_process`+`max_duration_days` |
| **B Risiko** | `risk_appetite` · `risk_matrix_size` · `rto_hours` · `rpo_hours` |
| **C Controls** | `mfa_scope` · `password_min_length` · `session_timeout_min` · `crypto_standard` · `backup_retention_days` · `backup_test_cadence` · `log_retention_days` · `patch_sla`(crit/high/med) · `access_review_cadence` |
| **D Daten/Scope** | `classification_scheme` · `data_retention_months` · `byod_allowed`(+Betriebsrat-Flag) · `remote_work_allowed` · `cloud_policy` |
| **E/F Menschen+Dritte** | `awareness_cadence` · `supplier_min_security` · `sub_processor_approval` |
| **G Recht** | `applicable_regulator` · `breach_internal_escalation_h` |

`risk_appetite` (B) steuert zugleich den Policy-Ton (konservativ/pragmatisch) —
kein separater Ton-Parameter (Reuse).

### Org-Kontext-Flags (pro Tenant, schalten Hinweise/Sektionen)

Neben den Werte-Params trägt das Profil ein paar **Tenant-Fakten**, die Hinweise
und Conditional-Sektionen gaten — nicht jeder Mittelständler hat dieselbe
Org-Struktur:

| Flag | Typ | Wirkung |
|---|---|---|
| `has_works_council` | bool | Betriebsrat-Mitbestimmungs-Hinweis bei `byod_allowed`/Monitoring-Params **nur wenn true** (viele KMU haben keinen BR) |
| `has_dpo` | bool | DSB-bezogene Klauseln/Hinweise (Bestellpflicht §38 BDSG) |
| `employee_band` | enum (`<10`,`10-49`,`50-249`,`250+`) | skaliert Defaults + BR-Wahrscheinlichkeit, KMU-Schwellen |
| `processes_special_categories` | bool | DSGVO Art. 9 / DSFA-Hinweise |

Flags werden vom Sektor-Baseline vorbelegt (z. B. KRITIS meist `250+`,
`has_works_council: true`) und sind pro Tenant überschreibbar.

## Industry-Baselines v1 (4 Sektoren)

| Param | 🏭 Mittelstand | 🚗 Automotive | 🏦 Finanz/BaFin | ⚡ KRITIS |
|---|---|---|---|---|
| Frameworks | ISO27001·BSI-Basis·GDPR | +TISAX·CRA* | +DORA·NIS2 | +NIS2·BSI·§8a BSIG |
| `approval_model` | single_ciso | dual | dual_signoff | dual_signoff |
| `mfa_scope` | priv+ext | all | all | all |
| `password_min` | 12 | 12 | 14 | 14 |
| `log_retention_days` | 180 | 365 | 730† | 365 |
| `patch_sla` crit/high | 7/30 | 3/14 | 1/7 | 1/7 |
| `classification` | 3tier | tisax_4tier‡ | 4tier | 4tier |
| `rto_hours` | 48 | 24 | 4† | 8 |
| `supplier_min` | iso | tisax | iso+DORA-3rd | iso |
| `cloud_policy` | approved_list | eu_region_only | eu_region_only | eu_region_only (OT: prohibited) |
| `regulator` | none | none | bafin | bnetza/bsi |
| Pflicht-Themen | — | Prototypenschutz·Lieferanten | 3rd-Party-Register·Resilience-Testing | BSI-Meldepflicht·OT-Security |

**Korrekturen (Tool-Versprechen ehrlich halten):**
- Jeder Preset-Wert trägt **`authority: regulatory | benchmark | recommended` + `source`** (Pflichtfeld).
- `*` CRA: nur wo Produkte mit digitalen Elementen → zuschaltbar, nicht hart.
- `†` `log_retention 730` + `rto 4` sind **benchmark/recommended**, NICHT
  regulatorisch vorgeschrieben (DORA verlangt institutsabhängige RTO-Festlegung,
  keine pauschale Zahl).
- `‡` TISAX: nur Schema-**Label** + Control-Nummern, **keine lizenzierte VDA-ISA-Prosa**.
- NIS2-Meldefrist als **Struktur** (24h Frühwarnung / 72h Meldung / 1 Monat Abschluss),
  nicht pauschal "24h".
- **Disclaimer** im UI + Doc: "Startpunkt — gegen eure konkrete Pflicht prüfen."

**EU-Reg-Verwebung (DACH):** GDPR überall · DORA Finanz · NIS2 Finanz+KRITIS ·
CRA Produkte · EUCS → `cloud_policy` · EU-AI-Act optional zuschaltbar.

## Wizard-Integration

**Zwei Einstiege:**
- **Express:** Sektor wählen → "Baseline übernehmen" → generieren. 0 Tweaks,
  Draft-Suite + Register in 3 Klicks (für die 90 %).
- **Geführt:** 2 neue Param-Steps mit Progressive-Disclosure pro Gruppe
  ("Baseline ✓ / anpassen ▾") für die 10 %, die feilen.

**Step-Flow (an bestehende Steps andocken):**
```
welcome (ex)            frameworks[] + Sektor-Picker → Baseline-Apply
organisation_scope (ex) + Scope-Params (D)
[NEU] governance_controls  A+B+C  (▾ je Gruppe)
[NEU] data_people_legal    D-Daten+E/F+G (▾ je Gruppe)
review (ex)             Cross-Framework-Ampel + Konflikte
generate (ex)           Policy-Suite + Register
```

**Framework-Constraint-Validierung** (`CrossStepConsistencyValidator` →
parameter-aware): User-Wert < Framework-Min → Warnung mit Auflösung
("DORA erzwingt `mfa_scope: all` — übernehmen / Ausnahme dokumentieren").
Nur `authority: regulatory`-Verstöße blockieren; benchmark/recommended = Hinweis.

**Live-Abdeckungs-Ampel:** während Framework-Wahl → "DORA 18/22 erfüllt · 4 Gaps · ~6 FTE".

## Policy-Generierung + Register

**`DocumentGenerator` erweitert — EIN Profil → konsistente Policy-SUITE:**
Das Profil speist **alle** generierten Dokumente (Leitlinie · Zugriffskontrolle ·
Kryptografie · BCM · Lieferanten · Klassifizierung · …). `log_retention: 365`
erscheint in Backup-, Logging- UND Lieferanten-Policy mit **derselben Zahl** →
garantierte Widerspruchsfreiheit über die Suite.
- **Wert-Interpolation** via `template_slot.interpolate`.
- **Conditional-Sektionen** via `section_if` (z. B. BYOD-Abschnitt nur wenn erlaubt;
  OT-Klausel nur KRITIS).
- **Bilingual:** DE + EN aus demselben Profil (Labels haben `de`/`en`).

**Parameter-Register-Export** (via `PdfExportService`): Cross-Framework-Matrix
Excel/PDF — **Param · Wert · authority · source · ISO-Klausel · Frameworks ·
Nachweis(-Spalte)** — filterbar auf `regulatory` = Pflicht-Liste fürs Audit.

**Freigabe-Loop:** generierte Suite läuft in bestehenden `ApprovalKickoff`
(CISO-Review → Management-Freigabe) + **Version-Pin** (Profil-vN + Baseline-vM
am Dokument) → bestehendes `policy-wizard-diff` promptet "Baseline v4 — neu
generieren?".

## Reuse (kein neues Rad)

- Param-Gaps → bestehende **Gap-Report-Engine** → Maturity-Score.
- Profil-Werte referenziert in **Control-Parameter + Audit-Checkliste + SoA** (eine Quelle).
- **Profil-Import (CSV/Excel)** Ist-Stand + **Profil-Export als Cross-Client-Template**
  (Berater tunt Kunde A → Startpunkt Kunde B).

## Invarianten

- KMU sieht **nie** Katalog-YAML — nur Sektor + (optional) 3 Steps.
- Jeder Wert ist über `authority`+`source` verteidigbar.
- Ein Wert → eine Quelle → mehrfach referenziert.

## Scope / YAGNI

**In v1:** Katalog (~27) · 4 Baselines end-to-end (Sektor → Profil → **Suite** →
Register → Freigabe) · Express+Geführt · Import/Export · authority-Tagging ·
Gap/SoA/Control-Reuse · bilingual.

**NICHT v1 (→ v2):** Auto-Evidence-Sammlung · KI-Ingestion bestehender Word-Policies ·
Params 28+.

## Betroffene/erweiterte Komponenten

- NEU: `config/policy_parameters/`, `config/industry_baselines/`,
  `OrganizationSecurityProfile`-Entity + Repo, `PolicyParameterCatalog`-Loader,
  `ParameterResolver` (Override→Profil→Baseline→Default), Register-Export.
- ERWEITERT: `PolicyWizardController`/-Steps, `DocumentGenerator`,
  `CrossStepConsistencyValidator`, `WizardRun` (Override-JSON), Gap-Report-Engine,
  `IndustryBaseline`/`IndustryPresetBundle` (Preset-Layer), `ApprovalKickoff` (Suite).

## Offene Punkte

- Genaue Markt-Benchmark-Defaults je Param (Zahlen-Tabelle im Implementierungs-Plan zu füllen).
- Mapping Param → konkrete Policy-Template-Slots je Dokument der Suite (Implementierungs-Detail).
- Betriebsrat-Mitbestimmung: v1 = **Hinweis** im Wizard bei `byod_allowed`/Monitoring-Params,
  **gegated durch Tenant-Flag `has_works_council`** (KMU ohne BR sieht ihn nicht);
  Workflow-Gate = v2.
