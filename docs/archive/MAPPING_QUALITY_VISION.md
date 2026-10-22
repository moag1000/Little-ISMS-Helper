# Cross-Framework-Mapping — Qualitätsvision

**Status:** Konzept · 2026-04-25
**Ergänzt:** `LIBRARY_FORMAT_VISION.md` (Library-Konzept)
**Fokus:** Mapping-Qualität messbar machen, Falschmappings reduzieren, Auditor-Argumentation stärken.

---

## Problem heute

Cross-Framework-Mappings sind zu oft *Vermutungen ohne Beleg*. Konkrete Schwachstellen:

1. **Keine Methodik dokumentiert** — Mapping „A.5.7 → NIS2 Art. 21(2)(f)" ohne Begründung
2. **Keine Konfidenz** — 1:1 vs. teilweise vs. lose verwandt sieht im Tool gleich aus
3. **Keine Bidirektionalität geprüft** — Mapping nur in eine Richtung gepflegt → unklare Rückrichtung
4. **Keine Versions-Bindung** — gilt das Mapping für ISO 27001:2013 ODER 2022? Beides?
5. **Keine Provenance** — wer/welche Autorität behauptet das Mapping? ENISA-Guidance? Eigene Recherche? Excel von Beratungshaus?
6. **Keine Lifecycle-States** — draft, review, approved, deprecated existieren nicht; alles gilt sofort als „Wahrheit"
7. **Keine Coverage-Checks** — Audit fragt: „Welche NIS2-Anforderungen sind durch ISO 27001 NICHT abgedeckt?" → keine zuverlässige Antwort
8. **Auditor-Bedenken** — wenn Mapping falsch, gilt erfüllter ISO-Control u.U. nicht als NIS2-Nachweis → Major-Finding-Risiko

## Vision: Mapping als reguliertes Artefakt

Jedes Mapping bekommt einen **Mapping-Quality-Score (MQS)** auf Skala 0–100, berechnet aus 6 Dimensionen:

| Dimension | Gewicht | Erfasst |
|---|---|---|
| Provenance (Quelle) | 25 % | Offizielle Quelle (ISO/IEC, ENISA, BSI) > Community > Proprietär |
| Methodology (Begründung) | 20 % | Volltext-Vergleich + Klausel-Analyse > Tag-Match > "klingt ähnlich" |
| Confidence (Sicherheit) | 15 % | high/medium/low pro Mapping-Eintrag mit Begründung |
| Coverage (Abdeckung) | 15 % | Wie viele source-items haben ≥1 Mapping? |
| Bidirectional Coherence | 15 % | Stimmt die Rückrichtung? Reziprozitäts-Check |
| Lifecycle State | 10 % | published > approved > review > draft; deprecated → Score 0 |

**Beispiel:** ENISA-NIS2-Implementation-Guidance + ISO 27001:2022 Mapping: Provenance 25 (ENISA offiziell), Methodology 18 (Volltext), Confidence 13 (high für 65 % der Einträge), Coverage 14 (78 % source coverage), Bidirectional 12 (95 % reziprok), Lifecycle 10 (published) → **MQS 92** ⭐

**Negativ-Beispiel:** „TISAX → BSI IT-Grundschutz" als ChatGPT-Output, Provenance 5, Methodology 4, Confidence 6, Coverage 8, Bidirectional 0, Lifecycle 2 (draft) → **MQS 25** ⚠

## Erweitertes Mapping-Schema

```yaml
schema_version: '1.1'
library:
  type: mapping
  id: 'iso27001-2022_to_nis2-art21_v1.2'
  source_framework: 'iso27001-2022'
  target_framework: 'nis2-art21'
  version: '1.2'
  effective_from: '2024-09-01'
  effective_until: null  # null = aktuell gültig

  # NEU: Provenance + Methodology als first-class
  provenance:
    primary_source: 'ENISA Guidance on NIS2 Implementation v2024-09'
    primary_source_url: 'https://www.enisa.europa.eu/publications/...'
    secondary_sources:
      - 'BSI Mindestanforderungen NIS2-UmsuCG (Entwurf 2024-12)'
      - 'Eigene Volltext-Analyse durch Compliance-Team Q1/2025'
    publisher: 'Little ISMS Helper Maintainers'
    contact: 'compliance@little-isms-helper.example'

  methodology:
    type: 'text_comparison_with_expert_review'
    # text_comparison_with_expert_review | tag_based | published_official_mapping | community_consensus | machine_assisted_with_review
    description: |
      Jeder ISO-Annex-A-Control wurde gegen alle NIS2-Art.-21-Unterpunkte
      geprüft: 1) Volltext-Lesung beider Seiten 2) Mindest-Schnittmenge
      identifizieren 3) Confidence festlegen 4) 4-Augen-Review durch
      zweiten Compliance-Manager.
    expert_reviewers:
      - { name: 'M. Banda', role: 'Compliance Manager', date: '2024-11-15' }
      - { name: 'Dr. K. Weber', role: 'External GRC Consultant', date: '2024-11-22' }

  # NEU: Lifecycle
  lifecycle:
    state: 'published'  # draft | review | approved | published | deprecated
    state_history:
      - { state: 'draft',     date: '2024-09-15', actor: 'M. Banda' }
      - { state: 'review',    date: '2024-10-30', actor: 'M. Banda' }
      - { state: 'approved',  date: '2024-11-22', actor: 'Dr. K. Weber' }
      - { state: 'published', date: '2024-12-01', actor: 'CISO Sign-Off' }

  # NEU: Aggregated quality metrics (auto-berechnet beim Import)
  quality:
    mqs_score: 92
    coverage_source_pct: 78  # 78% der ISO-Annex-A-Items haben ≥1 NIS2-Mapping
    coverage_target_pct: 100  # 100% der NIS2-Art.-21-Items haben ≥1 ISO-Anker
    bidirectional_coherence_pct: 95
    confidence_distribution: { high: 0.65, medium: 0.27, low: 0.08 }

mappings:
  - source: 'A.5.7'
    target: 'NIS2.21.2.f'
    relationship: 'equivalent'  # equivalent | subset | superset | related | partial_overlap
    confidence: 'high'
    rationale: |
      ISO 27001:2022 A.5.7 "Threat Intelligence" verlangt Sammeln, Analysieren
      und Verteilen von Bedrohungsinformationen. NIS2 Art. 21(2)(f) verlangt
      explizit "policies and procedures regarding the use of cryptography
      AND threat intelligence sharing". Schnittmenge in Threat-Intel-Sharing
      ist vollständig; Krypto-Anteil von 21(2)(f) wird durch A.8.24 abgedeckt.
    cross_refs:
      - 'A.8.24'  # Use of Cryptography (für Krypto-Anteil von NIS2 Art. 21(2)(f))
    tags: ['threat_intelligence', 'cryptography']
    audit_evidence_hint: 'Threat-Intel-Quellen-Liste + Sharing-Protokolle der letzten 12 Monate.'

  - source: 'A.5.30'
    target: 'NIS2.21.2.c'
    relationship: 'subset'  # ISO ist Teilmenge — NIS2 fordert mehr
    confidence: 'medium'
    rationale: |
      A.5.30 "ICT Readiness for Business Continuity" deckt nur den
      ICT-Anteil ab. NIS2 Art. 21(2)(c) verlangt zusätzlich:
      Krisenmanagement-Strukturen, externe Kommunikation, Behörden-
      Meldewege. Diese sind in A.5.29 + A.5.30 + A.6.5 verteilt.
    gap_warning: |
      Wer NIS2 21(2)(c) nur über A.5.30 nachweist, fehlt der
      Krisenmanagement-Teil. Empfehlung: Bündel A.5.29 + A.5.30 + A.6.5.
    confidence_reason: 'medium statt high, weil Mapping nicht 1:1 ist und Gap explizit dokumentiert wird.'
```

## Sechs konkrete Verbesserungs-Maßnahmen

### 1. Mapping-Lifecycle in der DB einführen

`compliance_mapping` Entity bekommt:
- `lifecycle_state` (`draft|review|approved|published|deprecated`)
- `effective_from`, `effective_until` (Validitäts-Range)
- `mqs_score` (kalkulierter Quality Score 0–100)
- `provenance_source`, `methodology_type`, `methodology_description`

Migration: `ALTER TABLE compliance_mapping ADD COLUMN lifecycle_state VARCHAR(20) DEFAULT 'draft', …`

### 2. Confidence + Rationale pro Mapping-Eintrag

Aktuell hat `compliance_mapping` keine per-pair Confidence. Neue Tabelle `compliance_mapping_entry` mit:
- `mapping_id`, `source_requirement_id`, `target_requirement_id`
- `relationship` (`equivalent|subset|superset|related|partial_overlap`)
- `confidence` (`high|medium|low`)
- `rationale` (TEXT — warum genau dieses Mapping?)
- `gap_warning` (TEXT — was fehlt bei dieser Brücke?)

### 3. Quality-Dashboard pro Mapping

Neue Admin-Route `/admin/mapping-quality` zeigt für jedes Mapping:
- MQS-Score mit Aufschlüsselung der 6 Dimensionen
- Coverage-Heatmap: welche source-items haben kein Mapping?
- Coherence-Check: gibt es A→B mit Rückrichtung B→A unstimmig?
- Confidence-Verteilung (Pie-Chart)
- Lifecycle-Status mit Reviewer-Liste

### 4. Mapping-Validation-Pipeline beim Import

Bei `LibraryLoaderService::loadMapping(...)` werden vor dem Speichern geprüft:
- Schema-Validität (JSON-Schema)
- Source/Target-Frameworks existieren in DB
- Alle source/target IDs existieren in den jeweiligen Frameworks
- Coverage-Check (Warnung wenn Coverage < 50 %)
- Methodology-Pflichtfeld (kein leeres `description`)
- Provenance-Pflichtfeld (kein anonymes Mapping)

### 5. Reziprozitäts-Check (Bidirectional Coherence)

Wenn Mapping `A→B` und `B→A` beide existieren: prüfe, ob die Eintrags-Listen aufeinander zeigen. Beispiel:
- Im A→B-Mapping: ISO A.5.7 → NIS2 21.2.f
- Im B→A-Mapping: NIS2 21.2.f → ISO A.5.7 (oder A.5.7 + A.8.24)

Mismatch → Warnung im Mapping-Quality-Dashboard. Skript `bin/console app:mapping:check-reciprocity` für CI.

### 6. Standard-Mapping-Templates publizieren

Im Repo unter `fixtures/library/mappings/` 10 hochwertige Mappings für DE/EU-Märkte:

| Mapping | Quelle | MQS-Ziel |
|---|---|---|
| iso27001-2022 → nis2-art21 | ENISA + eigenes Review | 90+ |
| iso27001-2022 → dora | EBA RTS-Mapping + eigenes Review | 85+ |
| iso27001-2022 → bsi-c5-2020 | BSI-Crosswalk-Tabelle | 95+ (offiziell) |
| iso27001-2022 → bsi-it-grundschutz-kompendium | BSI Kreuztabelle | 95+ (offiziell) |
| iso27001-2022 → tisax-vda-isa-6 | VDA Mapping-Anhang | 85+ |
| gdpr → iso27701-2025 | ISO 27701 Annex A | 95+ (offiziell) |
| nis2-art21 → bsi-it-grundschutz | BSI-Veröffentlichung 2024 | 90+ |
| dora → bafin-bait | BaFin-Crosswalk Q4/2024 | 85+ |
| eu-ai-act → iso42001 | ISO 42001 Annex A | 80+ (jung) |
| kritis-dachgesetz → nis2-umsucg | BMI-Begründungstext | 85+ |

## Nutzen pro Stakeholder

**ISB (Compliance-Nachweis im Audit):**
- *„Ich kann auditfest belegen, woher das Mapping stammt und wer es reviewed hat."*
- Methodologie + Reviewer-Liste = Standard-Antwort auf Auditor-Fragen
- Gap-Warnings verhindern Fehlnachweise

**Compliance-Manager (Framework-Portfolio):**
- *„Ich sehe sofort, welche Mappings nur 'medium confidence' haben — dort lege ich Doppel-Maßnahmen statt Einzel-Brücke."*
- Coverage-Dashboard zeigt Lücken im Portfolio-Onboarding
- MQS-Score erlaubt Priorisierung („zuerst Mappings unter 60 fixen")

**Externer Auditor (Sicht):**
- *„Wenn das Tool Provenance + Methodology + Reviewer-Sign-Off zeigt, akzeptiere ich das Mapping als Nachweis. Ohne das ist es Mutmaßung."*
- Lifecycle-State `published` mit Reviewer-Sign-Off = Nachvollziehbarkeit
- Audit-Evidence-Hints pro Mapping erleichtern Stichproben

**CISO (Board-Reporting):**
- *„NIS2-Coverage durch ISO 27001 = 78 % (high confidence) + 14 % (medium, mit Gap-Warning) + 8 % unmapped → Ich brauche €120k Budget für die unmapped 8 %."*
- Quantifizierbare Lücken statt H/M/L-Heatmap

## Migrationspfad

### Sprint 1 (1 Woche): Schema-Erweiterung
- DB-Migration für `lifecycle_state`, `mqs_score`, `provenance_*` auf `compliance_mapping`
- Neue Tabelle `compliance_mapping_entry` für Per-Pair-Daten
- 5 wichtigste Mappings (ISO27001→NIS2, ISO27001→DORA, ISO27001→BSI-C5, GDPR→ISO27701, ISO27001→TISAX) mit MQS-Score initialisieren

### Sprint 2 (1 Woche): Quality-Dashboard
- `/admin/mapping-quality` Route + Template
- MQS-Score-Berechnungs-Service
- Coverage-Heatmap pro Mapping
- Reciprocity-Check als Console-Command

### Sprint 3 (2 Wochen): Library-Loader + Validation-Pipeline
- `LibraryLoaderService` mit vollständiger Validation
- `MappingValidatorService` für Schema + Coverage + Reciprocity
- Standard-Mapping-Templates (10 Stück) als git-tracked YAML

### Sprint 4 (1 Woche): Lifecycle-Workflow + Reviewer-Sign-Off
- 4-Augen-Approval für `review → approved`
- CISO-Sign-Off für `approved → published`
- Audit-Log-Einträge bei jedem Lifecycle-Transition

## Erfolgsmetriken

Nach 4 Sprints:
- **Durchschnittlicher MQS** unserer 10 Standard-Mappings ≥ 85
- **0 Mappings im Status `published`** mit MQS < 70
- **Audit-Findings zu Mapping-Qualität** → 0
- **„Welche Anforderung ist nicht abgedeckt?"** in < 5 Sek. beantwortbar pro Framework
- **Mapping-Updates bei Norm-Wechsel** (z.B. NIS2-UmsuCG → 1.0 finalized) durch Library-File-Update statt Code-Refactoring
