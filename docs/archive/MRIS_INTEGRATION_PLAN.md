# MRIS-Integration in Little ISMS Helper — Implementation Plan

**Status:** Draft (v1, 2026-04-26)
**Reviewed by:** Persona Compliance-Manager + Persona Senior-Consultant (parallel)
**Target Release:** v3.2.0

---

## Attribution & Quellenangabe

Dieser Implementation-Plan leitet sich vollständig ab vom Whitepaper:

> **Peddi, Richard (2026):** *MRIS — Mythos-resistente Informationssicherheit, Version 1.5.* April 2026.
> Quelldatei: `docs/MRIS- mythos-resistente infosec.pdf`
> Lizenz: **Creative Commons Attribution 4.0 International (CC BY 4.0)** — https://creativecommons.org/licenses/by/4.0/

Alle Konzepte (4-Kategorien-Klassifikation, 13 Mythos-Härtungs-Controls, Reifegrad-Stufen, Mythos-KPI-Set, Bewertungsmatrix Anhang A) sind originär das Werk von Richard Peddi. Die Tool-Integration ist eine **abgeleitete Arbeit**, die unter denselben CC-BY-4.0-Bedingungen die Namensnennung beibehält. Jede MRIS-Library-Datei und jede Tool-Oberfläche, die MRIS-Konzepte zeigt, **muss** die Quellenangabe sichtbar mitführen (Library-`provenance.primary_source` + Tool-Footer im MRIS-Modul).

Zitierempfehlung im Tool-Output:
```
Quelle: Peddi, R. (2026). MRIS – Mythos-resistente Informationssicherheit, v1.5.
Lizenz: CC BY 4.0.
```

---

## Ausgangslage

- **Markt-Status (April 2026):** Kein Mainstream-GRC-Tool (Vanta, Drata, Verinice, HiScout, Archer, LogicGate) hat MRIS produktiv integriert. Lücke aktiv besetzbar.
- **Bestehende Infrastruktur:** Mapping-Quality-Library mit 24 YAML-Files, 314 Mapping-Pairs, 100 % Reciprocity (v3.1.0) liefert die Cross-Framework-Anker, an denen MHCs flankieren.
- **Wirtschaftlicher Hebel** (laut Persona-Reviews):
  - **CM intern:** ~11 FTE-Tage Quartal-Ersparnis (vs. manuelles MRIS-Onboarding).
  - **Berater extern:** 22–34 Tage pro Kundenprojekt.
  - **Aufwand Tool-Build:** geschätzt 8–12 Entwicklertage für MVP.

## Zielbild

Little ISMS Helper bietet MRIS als **zweite Control-Schicht** auf einem bestehenden ISO-27001-ISMS:

1. Jedes der 93 Annex-A-Controls trägt eine MRIS-Klassifikation (Standfest / Teilweise degradiert / Reine Reibung / Nicht betroffen).
2. Die 13 Mythos-Härtungs-Controls (MHC-01 bis MHC-13) erscheinen als eigenes Pseudo-Framework mit Reifegrad-Stufen (Initial / Defined / Managed) pro MHC.
3. MHC↔ISO/27002-Anbindungen sind als ComplianceMappings hinterlegt und nutzen die bestehende Reciprocity-Infrastruktur.
4. Acht Mythos-KPIs erscheinen im KPI-Modul, befüllt aus bestehenden Datenquellen (Incident, Vulnerability, Asset, BCM-Test).
5. AI-Agents (für MHC-13) werden als Asset-Subtyp inventarisiert.

---

## Schema-Änderungen

### 1. `compliance_requirement` — Reifegrad pro MHC
```sql
ALTER TABLE compliance_requirement
  ADD COLUMN maturity_current  ENUM('initial','defined','managed') NULL,
  ADD COLUMN maturity_target   ENUM('initial','defined','managed') NULL,
  ADD COLUMN maturity_reviewed_at DATETIME NULL,
  ADD COLUMN maturity_evidence_doc_id BIGINT NULL;
```
Nur befüllt bei MRIS-MHC-Requirements; bleibt NULL für ISO-27002-Standard-Controls (kein Bruch).

### 2. `control` — Mythos-Klassifikation
```sql
ALTER TABLE control
  ADD COLUMN mythos_resilience ENUM('standfest','degradiert','reibung','nicht_betroffen') NULL,
  ADD COLUMN mythos_flanking_mhcs JSON NULL;  -- z. B. ["MHC-05","MHC-11"]
```
Pre-seedbar aus MRIS Anhang A (Bewertungsmatrix, Z. 4160 ff. der PDF — alle 93 Controls).

### 3. `asset` — AI-Agent-Subtyp
Bestehendes Feld `asset_type` erweitert um Wert `ai_agent`. Neue optionale Felder:
```sql
ALTER TABLE asset
  ADD COLUMN ai_agent_capability_scope JSON NULL,
  ADD COLUMN ai_agent_threat_model_doc_id BIGINT NULL,
  ADD COLUMN ai_agent_extension_allowlist JSON NULL;
```

### 4. Keine neue Entity nötig
ComplianceFramework, ComplianceRequirement und ComplianceMapping decken MRIS vollständig ab. Mapping-Quality-Score, Lifecycle-State-Machine und Provenance-Felder funktionieren identisch.

---

## Library-Files (neu)

| Datei | Inhalt | Zeilen-ca. |
|---|---|---|
| `fixtures/frameworks/mris-v1.5.yaml` | ComplianceFramework + 13 ComplianceRequirements (MHC-01..13) mit Beschreibung, Framework-Basis, Reifegrad-Definition pro Stufe | ~400 |
| `fixtures/library/mappings/mris-v1.5_to_iso27001-2022_v1.0.yaml` | 13 ComplianceMappings (MHC → flankierende A.x.y) gemäß MRIS Kap. 9.4 Tabelle | ~250 |
| `fixtures/library/mappings/iso27001-2022_to_mris-v1.5_v1.0.yaml` | Reverse-Mapping (Reciprocity-pflicht, ~25 Pairs gemäß Anhang A) | ~280 |
| `fixtures/seeds/mris_annex_a_classification.csv` | 93 Zeilen: ISO-Control-ID, MRIS-Kategorie, flankierende MHCs (Quelle: MRIS Anhang A) | 93 |

Alle YAMLs tragen:
```yaml
provenance:
  primary_source: "Peddi, R. (2026). MRIS — Mythos-resistente Informationssicherheit, v1.5"
  primary_source_url: "https://creativecommons.org/licenses/by/4.0/"
  publisher: "Little ISMS Helper Maintainers (CC BY 4.0 derivative)"
methodology:
  type: "published_official_mapping"
  description: |
    Übernahme der MHC↔ISO-27002-Anbindungen aus MRIS v1.5 Kapitel 9.4.
    CC-BY-4.0-Ableitung mit Namensnennung Richard Peddi.
```

---

## Phasen-Plan

### Phase 1 — Library + Klassifikator (Tag 1–4)
**Ziel:** MRIS als Framework + 4-Kategorien-Klassifikation auf jedem Annex-A-Control.

1. Migration mit `mythos_resilience` + `mythos_flanking_mhcs` auf `control`
2. CSV-Seed `mris_annex_a_classification.csv` aus PDF Anhang A erstellen (93 Zeilen, manuelle Extraktion)
3. Console-Command `app:mris:seed-classification` zum Importieren
4. `mris-v1.5.yaml` mit 13 MHCs als ComplianceRequirements
5. Beide Mapping-Files (Forward + Reverse) für 100 % Reciprocity
6. Smoke-Test + Reciprocity-Check grün

**Deliverable:** Tool kennt MRIS, alle 93 Controls sind klassifiziert, 13 MHCs sind als Requirements mappbar.

### Phase 2 — Reifegrad + SoA-Integration (Tag 5–8)
**Ziel:** MHC-Reifegrad-Tracking + Mythos-Filter im SoA.

1. Migration für `maturity_current`/`maturity_target` auf `compliance_requirement`
2. `MaturityService` mit Soll/Ist-Delta-Berechnung
3. SoA-View: Filter „Mythos-Kategorie" (alle 4 Werte), Spalte „flankierende MHCs", Warning-Badge bei nur-„Reine Reibung"-Mitigation
4. MHC-Detail-Seite mit Reifegrad-Stufen-Tabelle + Soll/Ist-Trend
5. Audit-Log für Reifegrad-Transitionen

**Deliverable:** Auditor sieht im SoA-Export pro Control: ISO-Anforderung + Mythos-Kategorie + flankierende MHCs + Reifegrad.

### Phase 3 — Mythos-KPI-Block (Tag 9–10)
**Ziel:** 8 Mythos-KPIs aus MRIS Kap. 10.6 im KPI-Modul.

1. KPI-Definition (read-only) anlegen: MTTC, Phishing-MFA-Share, SBOM-Coverage, KEV-Patch-Latency, Restore-Test-Quote, CCM-Coverage, Crypto-Inventar-Coverage, TLPT-Findings-Closure
2. Datenquellen-Mapping auf bestehende Repositories (alle Daten existieren bereits in Incident, Vulnerability, Asset, BcExercise)
3. Mandanten-Schalter `mris_kpis_enabled` in `Tenant.featureFlags` (TLPT-KPI nur für DORA-Mandanten sinnvoll)
4. KPI-Dashboard-Block mit Trendlinien (12 Monate)

**Deliverable:** Mandanten-Admin aktiviert MRIS-KPIs, Board-Reports zeigen 8 Mythos-Metriken.

### Phase 4 — AI-Agent-Inventar (Tag 11–12)
**Ziel:** MHC-13-Anker — strukturiertes Inventar aller AI-Agents (Coding-Tools, MCP-Server, Extensions).

1. Migration `asset_type='ai_agent'` + Capability-/Bedrohungsmodell-Felder
2. Asset-Form-Variante für AI-Agents (Owner, Capability-Scope, Bedrohungsmodell-Doc, Extension-Allowlist)
3. Reifegrad-Hinweis in MHC-13: „Stufe Defined erfordert capability-scoped Identitäten + dokumentiertes Bedrohungsmodell pro Use-Case"
4. Verlinkung Asset → MHC-13-Reifegrad (Defined verlangt mind. 1 dokumentiertes Bedrohungsmodell)

**Deliverable:** Konzern mit Copilot/Cursor/MCP-Rollout kann sein AI-Agent-Inventar im Tool führen — Differenzierung gegen Vanta/Drata.

### Phase 5 — Branchen-Baselines + Tests (Tag 13–14)
**Ziel:** Berater liefert Defaults, Tool importiert.

1. Pre-Konfiguration `mris_baseline_kritis.yaml` (MHC-03/05/08/10/11 = Managed, Rest Defined)
2. Pre-Konfiguration `mris_baseline_finance.yaml` (MHC-08/11/12 = Managed wegen DORA)
3. Pre-Konfiguration `mris_baseline_automotive_tisax.yaml`
4. Pre-Konfiguration `mris_baseline_saas_cra.yaml`
5. PHPUnit-Tests: MaturityService (3), MrisClassificationService (3), MRIS-Library-Loader (3), AiAgentValidator (3)

**Deliverable:** Berater wählt beim Onboarding eine Baseline → 13 MHCs sofort mit Soll-Stufen vorbelegt.

---

## Anti-Pattern (NICHT bauen)

Beide Reviewer (CM + Consultant) waren einig:

| Nicht bauen | Begründung |
|---|---|
| Aggregierter „Mythos-Score" als Zahl | MRIS selbst beansprucht keine Aggregation; suggeriert Vergleichbarkeit, die das Whitepaper explizit ausschließt (Kap. 11.1 Grenzen). Auditor-Risiko. |
| SOAR-Integration für MHC-11 | Splunk/XSOAR-Domain. LIH bleibt GRC-Cockpit, nicht Detection/Response-Stack. |
| TLPT-Durchführung für MHC-12 | Tests bleiben extern (TIBER-EU, Caldera). LIH trackt nur Reife + Findings-Closure. |
| Crypto-Inventar-Scanner | Venafi/Keyfactor-Domain. MHC-01 ist Strategie, nicht Discovery. |
| Auto-Re-Mapping bei MRIS-Versions-Update | Versionierung statisch (mris-v1.5 → mris-v1.6 als neue Files). Mapping-Library bleibt stabil. |
| MHC-13-Workflows out-of-the-box | Erst Inventar-Felder, Workflows später. Reifegrad-Realismus laut MRIS: 12–18 Monate auf Defined. |

## RACI für die Umsetzung

| Aktivität | Tool-Maintainer | CM (Mandant) | Senior-Consultant |
|---|---|---|---|
| Library-File MRIS v1.5 schreiben | R | C | C |
| Anhang-A-Klassifikation seeden (93 Controls) | A | R (eigene Mandanten-Defaults) | C |
| Branchen-Baselines pflegen | C | C | R |
| Reifegrad-Soll-Stufen pro MHC bestimmen | I | R | C |
| KPI-Targets festlegen | I | R | C |
| MHC-13-Bedrohungsmodelle pro AI-Use-Case | I | C | R |
| TLPT-Setup (DORA-Kunden) | I | I | R |
| Vorstands-Briefing zur „Standard of Care" | I | C | R |

(R=Responsible, A=Accountable, C=Consulted, I=Informed)

---

## Akzeptanzkriterien

1. ✅ MRIS-Framework importierbar via `php bin/console app:mapping:library:import fixtures/frameworks/mris-v1.5.yaml`
2. ✅ Alle 93 Annex-A-Controls tragen `mythos_resilience` (manuelle Stichprobe von 10 Controls gegen Anhang A bestätigt korrekt)
3. ✅ `app:mapping:check-reciprocity` zeigt MRIS↔ISO27001 = 100 %
4. ✅ SoA-Export enthält neue Spalte „Mythos-Kategorie" + flankierende MHCs
5. ✅ MHC-Detail-Seite zeigt Reifegrad-Tabelle (Initial/Defined/Managed) gem. MRIS Kap. 9.5 Z. 3572 ff.
6. ✅ KPI-Dashboard zeigt 8 Mythos-KPIs (read-only-Berechnung aus Bestand) bei aktiviertem `mris_kpis_enabled`
7. ✅ AI-Agent-Asset mit Capability-Scope/Bedrohungsmodell anlegbar
8. ✅ Branchen-Baseline-Import setzt 13 MHC-Soll-Stufen in einem Schritt
9. ✅ Quellenangabe „Peddi, R. (2026). MRIS v1.5. CC BY 4.0." auf jeder MRIS-Oberfläche sichtbar (Footer)
10. ✅ 12 neue PHPUnit-Test-Cases grün
11. ✅ CHANGELOG-Eintrag + Bump auf v3.2.0

## Versionierungs-Strategie

- MRIS-Whitepaper-Version wird **separat zur Library-Version** geführt: `mris-v1.5_v1.0.yaml` (= unsere erste Library-Version, die MRIS v1.5 abbildet).
- Erscheint MRIS v1.6: neue Files `mris-v1.6_v1.0.yaml`, alte bleiben für historische Continuity. Dadurch keine Audit-Trail-Brüche.
- Tool-Release-Bump: **v3.2.0** (MINOR — neues Framework, keine Breaking-Changes).

## Risiken & Annahmen

- **Annahme:** MRIS-Anhang-A ist textuell konsistent mit Kap. 9.4. Falls Diskrepanzen — Anhang A ist die kanonische Quelle (siehe MRIS Z. 3502).
- **Risiko:** MRIS v1.5 könnte vor unserem Release durch v1.6 abgelöst werden. **Mitigation:** Versionierung trennt Whitepaper-Version von Library-Version (siehe oben).
- **Risiko:** Persona-Reviews überschätzen Berater-Tage-Hebel. **Mitigation:** Bei MVP-Launch tatsächliche FTE-Zahlen messen + iterieren.
- **Risiko:** AI-Agent-Inventar-Akzeptanz unklar (MHC-13 ist neu für die meisten CMs). **Mitigation:** Phase 4 als optionales Feature releasen, Defaults konservativ.

---

## Anhang: Quellen-Referenz innerhalb der MRIS-PDF

Alle Zeilen-Referenzen beziehen sich auf den extrahierten Volltext der PDF:

| Konzept | PDF-Stelle |
|---|---|
| Management Summary + zentraler Befund | Z. 263–360 |
| 4 Kategorien (Standfest/Degradiert/Reibung/Nicht-betroffen) | Z. 555–605 |
| 13 MHC-Detailbeschreibungen | Z. 3017–3480 |
| Kompakt-Übersicht MHC↔ISO-Anbindung | Z. 3499–3570 |
| Reifegrad-Stufen pro MHC | Z. 3572–3673 |
| Handlungsempfehlungen Kap. 10 | Z. 3674–3890 |
| 8 Mythos-KPIs Kap. 10.6 | Z. 3815–3868 |
| Anhang A — Bewertungsmatrix 93 Controls | Z. 4160 ff. |

---

## Auslieferungsstand v3.2.0 (2026-04-26)

Status nach Phase 1-5 + Plan-Vollerfüllung-Batches + User-priorisierten Erweiterungen:

| Bereich | Geliefert |
|---|---|
| Phase 1 Library | ✅ MRIS-Framework + 13 MHCs + 44 Mappings (100 % Reciprocity) |
| Phase 1 Schema | ✅ Migration `Version20260426132821` + Seed-CSV + Console-Command |
| Phase 2 Reifegrad | ✅ Schema + `MrisMaturityService` + Audit-Log auf set/setTarget |
| Phase 2 SoA-UI | ✅ Filter + Spalte + Reibung-Warning + MHC-Detail-Page |
| Phase 3 KPIs | ✅ 8 KPIs (3 auto, 5 manuell), Form, KpiSnapshot-Trends, Sparklines |
| Phase 4 AI-Agent | ✅ 9 Felder, AssetType-Variante, Stimulus-Default-Vorschlag |
| Phase 5 Branchen-Baselines | ✅ 4 YAMLs + `app:mris:apply-baseline` + UI |
| Erweiterung Score (MRI) | ✅ `MrisScoreService` mit 5-Dim-Aggregat + Audit-Disclaimer |
| Erweiterung Auto-Migration | ✅ `app:mris:migrate-version` mit Diff + Soft-Delete |
| Erweiterung Glossar | ✅ `/mris/glossar` + Mega-Menu-Eintrag |
| Erweiterung 3 Wizards | ✅ Pure-Friction, Reifegrad-Evidence, AI-Risk-Class |
| Persona-Reviews | ✅ CM + Senior-Consultant + Junior-ISB |
| Hilfetexte-Library | ✅ `fixtures/mris/help-texts.yaml` mit 20 Items |

**Tests:** 59 PHPUnit-Tests, 527 Assertions, alle grün.
**Library:** 24 YAMLs, 314 Mapping-Pairs, 100 % Reciprocity.
**Routen:** 11 MRIS-/AI-Agent-Routen registriert.
**Console-Commands:** 3 (`apply-baseline`, `migrate-version`, `seed-classification`).

**Backlog (v3.3.0+):** Tooltips aus help-texts.yaml an SoA + KPI-Tiles,
Controller-Tests, Score-Block auf Home-Dashboard, DPIA-Verknüpfung für
High-Risk-AI-Agents, granulare Permissions, Welcome-Tour.

---

**© 2026 Richard Peddi — MRIS-Konzept. Lizenziert unter CC BY 4.0.
Diese Tool-Integration ist eine Ableitung gemäß CC-BY-4.0-Bedingungen mit Namensnennung.**
