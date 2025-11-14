# Compliance Mapping Quality Analysis System

## Übersicht

Dieses Feature implementiert ein automatisiertes System zur Analyse und Validierung der Qualität von Compliance-Mappings zwischen verschiedenen Frameworks. Statt auf einfachen Heuristiken (Priorität, Anzahl ISO-Controls) zu basieren, verwendet das System intelligente Textanalyse und Similarity-Algorithmen.

## Hauptkomponenten

### 1. Datenmodell

#### Neue Entity: `MappingGapItem`
**Pfad:** `src/Entity/MappingGapItem.php`

Repräsentiert spezifische Gaps in einem Compliance Mapping:
- **Gap Types:** missing_control, partial_coverage, scope_difference, additional_requirement, evidence_gap
- **Felder:** description, missingKeywords, recommendedAction, priority, estimatedEffort, percentageImpact
- **Status-Tracking:** identified, planned, in_progress, resolved, wont_fix

#### Erweiterte Entity: `ComplianceMapping`
**Pfad:** `src/Entity/ComplianceMapping.php`

Neue Felder:
- `calculatedPercentage` - Automatisch berechneter Prozentsatz
- `manualPercentage` - Manuell überschriebener Wert
- `analysisConfidence` - Confidence Score (0-100)
- `qualityScore` - Gesamtqualitätsscore (0-100)
- `textualSimilarity` - Textuelle Ähnlichkeit (0-1)
- `keywordOverlap` - Keyword-Überlappung (0-1)
- `structuralSimilarity` - Strukturelle Ähnlichkeit (0-1)
- `requiresReview` - Flag für manuelle Review-Notwendigkeit
- `reviewStatus` - Status: unreviewed, in_review, approved, rejected
- `gapItems` - Collection von Gap-Items

### 2. Analyse-Services

#### MappingQualityAnalysisService
**Pfad:** `src/Service/MappingQualityAnalysisService.php`

**Funktionalität:**
- **Textuelle Ähnlichkeitsanalyse:** Kombiniert Jaccard- und Cosine-Similarity
- **Keyword-Extraktion:** Identifiziert sicherheitsrelevante Schlüsselbegriffe aus 15 Kategorien
- **Strukturelle Analyse:** Vergleicht Kategorie, Priorität und Scope
- **Gewichtete Berechnung:**
  - Keyword Overlap: 40%
  - Textual Similarity: 35%
  - Structural Similarity: 25%
- **Confidence Scoring:** Basiert auf Metriken-Varianz und Textlänge

**Keyword-Kategorien:**
- Access Control, Encryption, Audit, Data Protection
- Network Security, Incident Response, Vulnerability Management
- Backup & Recovery, Physical Security, Policy & Governance
- Risk Management, Compliance, Training, Supplier Management, Change Management

#### AutomatedGapAnalysisService
**Pfad:** `src/Service/AutomatedGapAnalysisService.php`

**Gap-Identifikation:**
1. **Missing Keywords:** Konzepte im Target, die im Source fehlen
2. **Partial Coverage:** Mittlere Textähnlichkeit (30-70%)
3. **Scope Differences:** Unterschiedliche Kategorien/Schwerpunkte
4. **Additional Requirements:** Target hat deutlich mehr Inhalt
5. **Evidence Gaps:** Hoher Mapping-%, aber mittlere Textähnlichkeit

**Gap-Priorisierung:**
- Critical: Fehlende kritische Sicherheitskonzepte (encryption, authentication)
- High: Fehlende wichtige Kontrollen (access control, monitoring)
- Medium: Partielle Abdeckung, Scope-Unterschiede
- Low: Kleinere dokumentarische Lücken

### 3. Command-Line Tool

#### AnalyzeMappingQualityCommand
**Pfad:** `src/Command/AnalyzeMappingQualityCommand.php`

```bash
# Alle unanalysierten Mappings analysieren
php bin/console app:analyze-mapping-quality

# Alle Mappings neu analysieren
php bin/console app:analyze-mapping-quality --reanalyze

# Nur bestimmtes Framework
php bin/console app:analyze-mapping-quality --framework=ISO27001

# Nur Low-Quality Mappings
php bin/console app:analyze-mapping-quality --low-quality

# Limit setzen
php bin/console app:analyze-mapping-quality --limit=100

# Dry-Run (keine Änderungen speichern)
php bin/console app:analyze-mapping-quality --dry-run
```

**Output:**
- Progress Bar mit Fortschritt
- Detaillierte Statistiken:
  - Analyzed Mappings
  - Confidence Distribution (High/Medium/Low)
  - Gaps Identified
  - Improved/Degraded Percentages
  - Requires Manual Review Count
- Empfehlungen für nächste Schritte

### 4. Review-Interface (Controller)

#### MappingQualityController
**Pfad:** `src/Controller/MappingQualityController.php`

**Routes:**

1. **Dashboard:** `/compliance/mapping-quality/`
   - Quality-Statistiken
   - Confidence-Verteilung
   - Framework-Vergleich
   - Gap-Übersicht

2. **Review Queue:** `/compliance/mapping-quality/review-queue`
   - Mappings die Review benötigen
   - Low-Confidence Mappings
   - Diskrepanzen zwischen alt/neu

3. **Mapping Review:** `/compliance/mapping-quality/review/{id}`
   - Detailansicht eines Mappings
   - Gap-Items anzeigen
   - Review-Formular

4. **Gap-Übersicht:** `/compliance/mapping-quality/gaps`
   - Alle High-Priority Gaps
   - Gap-Statistiken
   - Remediation Effort

**API-Endpoints:**

- `POST /compliance/mapping-quality/review/{id}/update` - Review speichern
- `POST /compliance/mapping-quality/analyze/{id}` - Mapping neu analysieren
- `POST /compliance/mapping-quality/gap/{id}/update` - Gap-Status aktualisieren

### 5. Repository-Erweiterungen

#### ComplianceMappingRepository
**Pfad:** `src/Repository/ComplianceMappingRepository.php`

**Neue Methoden:**
- `findMappingsRequiringReview()` - Mappings die Review benötigen
- `findLowConfidenceMappings($threshold)` - Mappings mit niedrigem Confidence
- `findLowQualityMappings($threshold)` - Mappings mit niedrigem Quality Score
- `getQualityStatistics()` - Umfassende Quality-Statistiken
- `getQualityDistribution()` - Verteilung nach Confidence-Level
- `findMappingsWithDiscrepancies($threshold)` - Große Unterschiede alt/neu
- `getFrameworkQualityComparison()` - Quality-Vergleich zwischen Frameworks
- `getSimilarityDistribution()` - Verteilung der Ähnlichkeits-Scores

#### MappingGapItemRepository
**Pfad:** `src/Repository/MappingGapItemRepository.php`

**Methoden:**
- `findByMapping($mapping)` - Gaps für ein Mapping
- `findHighPriorityGaps()` - Critical/High Priority Gaps
- `getGapStatisticsByType()` - Statistiken nach Gap-Type
- `getGapStatisticsByPriority()` - Statistiken nach Priorität
- `findLowConfidenceGaps($threshold)` - Unsichere Gap-Identifikationen
- `calculateTotalRemediationEffort()` - Gesamtaufwand zur Gap-Behebung

## Datenbank-Migration

**Pfad:** `migrations/Version20251114120000_mapping_quality_analysis.php`

**Neue Tabelle:** `mapping_gap_item`
- Speichert Gap-Items mit allen Details
- Foreign Key zu `compliance_mapping`
- Indices auf priority, status, mapping_id

**Neue Felder in `compliance_mapping`:**
- calculated_percentage, manual_percentage
- analysis_confidence, quality_score
- textual_similarity, keyword_overlap, structural_similarity
- requires_review, review_status
- review_notes, reviewed_by, reviewed_at
- analysis_algorithm_version

**Indices:**
- review_status, requires_review
- quality_score, analysis_confidence

## Workflow

### 1. Initiale Analyse

```bash
# Migration ausführen
php bin/console doctrine:migrations:migrate

# Alle Mappings analysieren
php bin/console app:analyze-mapping-quality
```

**Ergebnis:**
- Alle Mappings erhalten `calculatedPercentage`, `analysisConfidence`, `qualityScore`
- Gap-Items werden automatisch erstellt
- Mappings mit niedrigem Confidence werden für Review markiert

### 2. Review-Prozess

1. **Dashboard öffnen:** Review Queue ansehen
2. **Low-Confidence Mappings prüfen:** Sortiert nach niedrigstem Confidence zuerst
3. **Mapping reviewen:**
   - Berechneten Prozentsatz prüfen
   - Gap-Items durchgehen
   - Bei Bedarf manuellen Prozentsatz setzen
   - Review-Notes hinzufügen
   - Status auf "approved" oder "rejected" setzen
4. **Gaps adressieren:**
   - High-Priority Gaps zuerst
   - Status aktualisieren (planned, in_progress, resolved)
   - Effort tracken

### 3. Kontinuierliche Verbesserung

```bash
# Periodisch neu analysieren (nach Framework-Updates)
php bin/console app:analyze-mapping-quality --reanalyze

# Nur bestimmte Frameworks neu analysieren
php bin/console app:analyze-mapping-quality --framework=GDPR --reanalyze
```

## Algorithmus-Details

### Percentage-Berechnung

```
baseScore = (keywordOverlap * 0.40) + (textualSimilarity * 0.35) + (structuralSimilarity * 0.25)
```

**Modifikatoren:**
- +5% wenn beide Frameworks aus ISO-Familie
- +5% wenn beide Frameworks EU-Regulierungen
- -10% wenn >10 ISO-Controls gemappt (zu breit/vage)

**Ergebnis:** 0-150% (clamped)

### Confidence-Berechnung

**Basis:** 50

**Bonusse:**
- +30 wenn alle Metriken ähnlich (niedrige Varianz <0.05)
- +20 wenn mittlere Varianz (0.05-0.10)
- +10 wenn höhere Varianz (0.10-0.15)
- +15 wenn >100 Wörter in Requirements
- +10 wenn >50 Wörter
- +5 wenn >20 Wörter
- +10 wenn Keyword-Overlap >0.7

**Penalties:**
- -10 wenn <20 Wörter (zu kurze Texte)

**Ergebnis:** 0-100 (clamped)

### Quality-Score-Berechnung

```
qualityScore = (confidence * 0.4) + (min(100, calculatedPercentage) * 0.3) + verificationScore
```

**Verification Score:**
- 30 wenn verifiziert (verifiedBy gesetzt)
- 20 wenn reviewed (reviewedBy gesetzt)
- 0 sonst

**Ergebnis:** 0-100

## Vorteile des Systems

### Automatisierung
- ✅ Intelligente Berechnung statt fester Heuristiken
- ✅ Automatische Gap-Identifikation
- ✅ Priorisierung nach Impact und Confidence
- ✅ Batch-Processing aller Mappings

### Transparenz
- ✅ Nachvollziehbare Metriken (Textual, Keyword, Structural)
- ✅ Confidence-Scores zeigen Zuverlässigkeit
- ✅ Detaillierte Gap-Beschreibungen mit Empfehlungen
- ✅ Audit-Trail (reviewedBy, reviewedAt)

### Qualitätssicherung
- ✅ Review-Queue für unsichere Mappings
- ✅ Manuelle Override-Möglichkeit
- ✅ Gap-Tracking mit Status und Effort
- ✅ Framework-übergreifende Qualitätsvergleiche

### Skalierbarkeit
- ✅ Batch-Processing mit Progress Bar
- ✅ Inkrementelle Analyse (nur neue Mappings)
- ✅ Framework-spezifische Analyse
- ✅ Algorithmus-Versionierung für Updates

## Nächste Schritte

1. **Templates erstellen:**
   - `templates/compliance/mapping_quality/dashboard.html.twig`
   - `templates/compliance/mapping_quality/review_queue.html.twig`
   - `templates/compliance/mapping_quality/review.html.twig`
   - `templates/compliance/mapping_quality/gaps.html.twig`

2. **Frontend-Features:**
   - Interactive Charts (Chart.js) für Statistiken
   - Ajax-Forms für Review-Updates
   - Bulk-Actions für Gap-Items
   - Export zu PDF/Excel

3. **Erweiterte Analysen:**
   - ML-basierte Ähnlichkeitsberechnung (z.B. Sentence Transformers)
   - Named Entity Recognition für Sicherheitskonzepte
   - Automatische Empfehlungen für Gap-Remediation
   - Predictive Analytics für Compliance-Risiken

4. **Integration:**
   - Notifications bei Low-Quality Mappings
   - Dashboard-Widgets im Haupt-Compliance-Dashboard
   - Automated Reports (wöchentlich/monatlich)
   - API für externe Tools

## API-Beispiele

### Mapping analysieren (via API)

```bash
curl -X POST https://your-domain/compliance/mapping-quality/analyze/123 \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json"
```

### Review speichern

```bash
curl -X POST https://your-domain/compliance/mapping-quality/review/123/update \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "review_status": "approved",
    "manual_percentage": 85,
    "review_notes": "Nach manueller Prüfung: Mapping korrekt, Gap-Items adressiert."
  }'
```

### Gap-Status aktualisieren

```bash
curl -X POST https://your-domain/compliance/mapping-quality/gap/456/update \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "status": "in_progress",
    "estimated_effort": 8
  }'
```

## Troubleshooting

### Command schlägt fehl
```bash
# Überprüfen ob Migration gelaufen ist
php bin/console doctrine:migrations:status

# Migration manuell ausführen
php bin/console doctrine:migrations:migrate
```

### Niedrige Confidence-Scores
- Kurze Requirement-Texte führen zu niedrigeren Scores
- Lösung: Requirements mit mehr Details erweitern

### Viele Gaps identifiziert
- Normal bei ersten Analysen
- Priorisieren: Critical/High zuerst
- Einige Gaps können "wont_fix" sein (akzeptiertes Risiko)

### Performance-Probleme
- Batch-Size reduzieren in Command
- Limit verwenden: `--limit=50`
- Framework-spezifisch analysieren: `--framework=ISO27001`

## Autoren

Entwickelt mit Claude 3.5 Sonnet für das Little-ISMS-Helper Projekt.

**Version:** 1.0.0
**Datum:** 2025-11-14
