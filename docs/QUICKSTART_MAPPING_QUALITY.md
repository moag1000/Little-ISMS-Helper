# Quickstart Guide: Mapping Quality Analysis

## Übersicht

Dieses System analysiert automatisch die Qualität Ihrer Compliance-Mappings zwischen verschiedenen Frameworks und identifiziert Lücken. Der Prozess dauert ca. 10-15 Minuten für die Ersteinrichtung.

## Prerequisites (Voraussetzungen prüfen)

Bevor Sie beginnen, stellen Sie sicher, dass folgende Daten vorhanden sind:

```bash
# 1. Prüfen ob Frameworks geladen sind
php bin/console doctrine:query:sql "SELECT code, name FROM compliance_framework"

# 2. Prüfen ob Requirements vorhanden sind
php bin/console doctrine:query:sql "SELECT COUNT(*) FROM compliance_requirement"

# 3. Prüfen ob Mappings existieren
php bin/console doctrine:query:sql "SELECT COUNT(*) FROM compliance_mapping"
```

**Erwartete Ergebnisse:**
- Mindestens 2 Frameworks (z.B. ISO27001, GDPR, NIS2)
- Mindestens 50 Requirements
- Mindestens 10 Mappings

**Falls Daten fehlen:**
```bash
# Frameworks importieren
php bin/console app:load-iso27001-requirements

# Cross-Framework Mappings erstellen
php bin/console app:create-cross-framework-mappings
```

---

## Installation

### Schritt 1: Migration ausführen

```bash
# Migration für neue Quality-Felder und Gap-Tabelle
php bin/console doctrine:migrations:migrate --no-interaction

# Erfolg überprüfen
php bin/console doctrine:migrations:status
```

**Was passiert:**
- Neue Tabelle `mapping_gap_item` wird erstellt
- 13 neue Felder in `compliance_mapping` hinzugefügt
- Indices für Performance angelegt

**Erwartete Ausgabe:**
```
>> migrated (0.2s)
[OK] Successfully migrated to version: Version20251114120000
```

---

### Schritt 2: Erste Test-Analyse (Dry-Run)

```bash
# Nur 10 Mappings analysieren, ohne zu speichern
php bin/console app:analyze-mapping-quality --limit=10 --dry-run
```

**Was passiert:**
- Analysiert 10 zufällige Mappings
- Zeigt Statistiken an
- **Speichert NICHTS** in der Datenbank

**Erwartete Ausgabe:**
```
🔍 Mapping Quality Analysis - DRY RUN
=====================================

Processing 10 mappings...
[▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓] 10/10 100%

📊 Analysis Statistics:
-----------------------
✅ Analyzed Mappings: 10

🎯 Confidence Distribution:
  High (≥80):   4 (40%)
  Medium (60-79): 3 (30%)
  Low (<60):    3 (30%)

🔍 Gaps Identified: 18
  Critical: 2
  High:     5
  Medium:   8
  Low:      3

💡 Recommendations:
  - 3 mappings require manual review (low confidence)
  - Run full analysis: php bin/console app:analyze-mapping-quality
```

**Troubleshooting Schritt 2:**

❌ **"No mappings found to analyze"**
→ Erstellen Sie zuerst Mappings: `php bin/console app:create-cross-framework-mappings`

❌ **"Call to a member function on null"**
→ Requirements fehlen: Prüfen Sie Prerequisites

---

### Schritt 3: Vollständige Analyse durchführen

```bash
# ALLE Mappings analysieren (kann 5-30 Minuten dauern)
php bin/console app:analyze-mapping-quality

# ODER: Framework-spezifisch analysieren
php bin/console app:analyze-mapping-quality --framework=ISO27001

# ODER: Nur erste 100 Mappings
php bin/console app:analyze-mapping-quality --limit=100
```

**Was passiert:**
- Analysiert alle unanalysierten Mappings
- Berechnet:
  - `calculatedPercentage` (basierend auf Text-Ähnlichkeit)
  - `analysisConfidence` (Zuverlässigkeit der Analyse)
  - `qualityScore` (Gesamtqualität)
  - Similarity-Metriken (textual, keyword, structural)
- Erstellt Gap-Items automatisch
- Markiert Low-Confidence Mappings für Review

**Erwartete Ausgabe bei 500 Mappings:**
```
🔍 Mapping Quality Analysis
============================

Processing 500 mappings...
[▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓] 500/500 100%

📊 Analysis Statistics:
-----------------------
✅ Analyzed Mappings: 500
⏱️  Processing Time: 4m 23s

🎯 Confidence Distribution:
  High (≥80):   287 (57.4%)
  Medium (60-79): 156 (31.2%)
  Low (<60):     57 (11.4%)

🔍 Gaps Identified: 1,247
  Critical: 23
  High:     89
  Medium:   412
  Low:      723

📈 Percentage Changes:
  Improved:  234 mappings (+avg 12%)
  Degraded:  189 mappings (-avg 8%)
  Unchanged: 77 mappings

⚠️  Requires Manual Review: 57 mappings

✅ Analysis complete!

💡 Next Steps:
  1. Open Dashboard: /compliance/mapping-quality/
  2. Review Queue:  /compliance/mapping-quality/review-queue
  3. Gap Overview:  /compliance/mapping-quality/gaps
```

**Optionen:**
```bash
--limit=N          # Nur N Mappings analysieren
--framework=CODE   # Nur bestimmtes Framework (ISO27001, GDPR, etc.)
--reanalyze        # Alle Mappings neu analysieren (auch bereits analysierte)
--low-quality      # Nur Mappings mit Quality Score < 50
--dry-run          # Keine Änderungen speichern
```

---

## Web-Interface öffnen

### Dashboard

**URL:** `http://your-domain/compliance/mapping-quality/`

**Was Sie sehen:**
- 📊 Statistik-Karten (Total, Analyzed, Requires Review, With Gaps)
- ⭐ Durchschnittliche Qualität und Confidence (Progress Bars)
- 📊 Confidence-Verteilung (Donut Chart)
- 🔍 Gap-Statistiken nach Priorität (Bar Chart)
- 🌍 Framework-Qualitätsvergleich (Top 10 Tabelle)
- ℹ️ Hilfe-Box mit Erklärungen

**Navigation:**
- **Review Queue** → Mappings die manuelle Überprüfung benötigen
- **Gap-Übersicht** → Alle identifizierten Lücken
- **Zurück zu Compliance** → Haupt-Compliance-Index

### Review Queue

**URL:** `http://your-domain/compliance/mapping-quality/review-queue`

**Was Sie sehen:**
1. **Benötigt dringend Review** (gelb):
   - Mappings mit `requiresReview = true`
   - Zeigt Alt % vs. Berechnet %
   - Confidence und Quality Scores

2. **Niedriges Confidence** (<70):
   - Top 20 Mappings mit niedrigstem Confidence
   - Similarity-Scores angezeigt
   - Review-Status

3. **Große Diskrepanzen** (≥20% Unterschied):
   - Mappings wo alter vs. neuer Prozentsatz stark abweicht
   - Top 15 angezeigt

**Aktion:** Klicken Sie auf "Review" Button → Detailansicht

### Mapping Review (Detailansicht)

**URL:** `http://your-domain/compliance/mapping-quality/review/123`

**Was Sie sehen:**
- **Mapping-Übersicht:** Source → Target Requirements mit Texten
- **Prozentsätze:** Original (Heuristik), Berechnet (Auto), Manuell, Final
- **Quality & Confidence:** Progress Bars mit farblicher Codierung
- **Similarity Scores:** Textual, Keyword, Structural
- **Identifizierte Gaps:** Liste mit Priority, Impact, Effort
- **Review-Formular:**
  - Manueller Prozentsatz (Override)
  - Review Status (approved/rejected)
  - Review Notes (Freitext)

**Workflow:**
1. Prüfen Sie berechneten Prozentsatz vs. Original
2. Lesen Sie Gap-Beschreibungen und Empfehlungen
3. Entscheiden Sie:
   - **Approve:** Automatische Berechnung akzeptieren
   - **Override:** Manuellen Wert setzen (z.B. 85%)
   - **Reject:** Mapping ist falsch/irrelevant
4. Notizen hinzufügen (optional)
5. **Review speichern** klicken

**Ergebnis:** AJAX-Update, Seite lädt neu mit grünem Success-Banner

### Gap-Übersicht

**URL:** `http://your-domain/compliance/mapping-quality/gaps`

**Was Sie sehen:**
- 📊 Total Gaps
- ⏱️ Geschätzter Aufwand (Stunden und Arbeitstage)
- 📈 Gaps nach Priorität (Critical/High/Medium/Low)
- 📊 Gaps nach Typ (Missing Control, Partial Coverage, etc.)
- 🔥 Critical & High Priority Gaps (Top 20 Tabelle)
- ⚠️ Low Confidence Gaps (Top 15)
- 🛠️ Aktionen: Priorisierung, Zeitplanung, Tracking

---

## Typischer Workflow

### Phase 1: Initiale Analyse (einmalig)
```bash
# 1. Migration
php bin/console doctrine:migrations:migrate

# 2. Test (optional)
php bin/console app:analyze-mapping-quality --limit=10 --dry-run

# 3. Vollständige Analyse
php bin/console app:analyze-mapping-quality
```

### Phase 2: Review-Prozess (wöchentlich)
1. Dashboard öffnen → Review Queue ansehen
2. Low-Confidence Mappings (rot markiert) prüfen
3. Für jedes Mapping:
   - Details öffnen
   - Gaps durchlesen
   - Bei Bedarf manuellen % setzen
   - Status auf "approved" setzen
   - Speichern

### Phase 3: Gap-Remediation (kontinuierlich)
1. Gap-Übersicht öffnen
2. Critical Gaps zuerst adressieren
3. Gap-Status aktualisieren:
   - `identified` → `planned` (eingeplant)
   - `planned` → `in_progress` (wird bearbeitet)
   - `in_progress` → `resolved` (behoben)
4. Effort tracken

### Phase 4: Periodische Re-Analyse (monatlich)
```bash
# Nach Framework-Updates oder neuen Mappings
php bin/console app:analyze-mapping-quality

# Oder alles neu analysieren
php bin/console app:analyze-mapping-quality --reanalyze
```

---

## Häufige Probleme und Lösungen

### Problem 1: "No mappings found to analyze"

**Ursache:** Keine Mappings in der Datenbank

**Lösung:**
```bash
# Prüfen
php bin/console doctrine:query:sql "SELECT COUNT(*) FROM compliance_mapping"

# Falls 0, erstellen:
php bin/console app:create-cross-framework-mappings
```

---

### Problem 2: Niedrige Confidence-Scores überall

**Ursache:** Requirements haben zu wenig Text (< 20 Wörter)

**Erklärung:**
- Confidence steigt mit längeren Beschreibungen
- Kurze Requirements → unsichere Analyse

**Lösung:**
- Erweitern Sie Requirement-Beschreibungen
- Oder: Akzeptieren Sie niedrige Scores bei kurzen Requirements
- Re-Analyse nach Text-Erweiterungen: `--reanalyze`

---

### Problem 3: Viele Gaps (>1000) identifiziert

**Ursache:** Normal bei ersten Analysen

**Lösung:**
- Priorisieren Sie: Critical/High zuerst
- Viele Low-Priority Gaps können ignoriert werden
- Einige Gaps sind akzeptierte Risiken → Status `wont_fix`

**Workflow:**
1. Gap-Übersicht öffnen
2. Critical Gaps filtern
3. Top 10-20 adressieren
4. Dann High-Priority Gaps

---

### Problem 4: Performance-Probleme bei großen Datenmengen

**Symptome:** Command läuft >30 Minuten bei >2000 Mappings

**Lösung 1:** Batch-Processing
```bash
# ISO27001 zuerst
php bin/console app:analyze-mapping-quality --framework=ISO27001

# Dann GDPR
php bin/console app:analyze-mapping-quality --framework=GDPR

# Dann NIS2
php bin/console app:analyze-mapping-quality --framework=NIS2
```

**Lösung 2:** Limit verwenden
```bash
# Jeweils 100 Mappings
php bin/console app:analyze-mapping-quality --limit=100
# Wiederholen bis alle analysiert sind
```

**Lösung 3:** BATCH_SIZE im Command anpassen
```php
// src/Command/AnalyzeMappingQualityCommand.php
private const BATCH_SIZE = 25; // Reduzieren von 50 auf 25
```

---

### Problem 5: Dashboard zeigt keine Daten

**Mögliche Ursachen:**

1. **Noch keine Analyse durchgeführt**
   ```bash
   php bin/console app:analyze-mapping-quality
   ```

2. **Browser-Cache**
   - Strg+F5 (Hard Refresh)
   - Private Browsing testen

3. **Route nicht registriert**
   ```bash
   php bin/console debug:router | grep mapping_quality
   # Sollte 6 Routen zeigen
   ```

---

### Problem 6: "Chart.js is not defined"

**Ursache:** CDN-Verbindung fehlgeschlagen

**Lösung 1:** Prüfen Sie Internetverbindung

**Lösung 2:** Lokale Chart.js Installation
```bash
# Chart.js lokal installieren
npm install chart.js

# Template anpassen: CDN → lokal
<script src="{{ asset('node_modules/chart.js/dist/chart.umd.min.js') }}"></script>
```

---

## Performance-Optimierung

### Für große Installationen (>5000 Mappings)

1. **Datenbank-Indices prüfen:**
```sql
SHOW INDEX FROM compliance_mapping WHERE Key_name LIKE '%quality%';
SHOW INDEX FROM mapping_gap_item;
```

2. **Batch-Size optimieren:**
```php
// In AnalyzeMappingQualityCommand.php
private const BATCH_SIZE = 25; // Für langsamere Server
```

3. **Cron-Job für nächtliche Analyse:**
```bash
# /etc/cron.d/mapping-quality-analysis
0 2 * * * www-data php /var/www/html/bin/console app:analyze-mapping-quality --limit=500 >> /var/log/mapping-analysis.log 2>&1
```

4. **Database Query Optimization:**
```sql
-- Composite Index für häufige Queries
CREATE INDEX idx_mapping_quality_review ON compliance_mapping(requires_review, review_status, analysis_confidence);
```

---

## Nächste Schritte nach Quickstart

### Sofort (heute):
1. ✅ Migration ausführen
2. ✅ Erste Analyse durchführen
3. ✅ Dashboard ansehen

### Diese Woche:
1. 📋 Review Queue abarbeiten (Top 20 Low-Confidence Mappings)
2. 🔥 Critical Gaps adressieren (Top 10)
3. 📊 Framework-Qualitätsvergleich prüfen

### Diesen Monat:
1. 📈 Alle High-Priority Gaps planen
2. 🎯 Quality-Score-Ziel setzen (z.B. Ø 75)
3. 🔄 Re-Analyse nach Verbesserungen

### Langfristig:
1. 🤖 Periodische Re-Analyse (monatlich)
2. 📊 Metriken tracken (Quality Score Trend)
3. 🚀 Erweiterte Features (ML, NER, Predictive Analytics)

---

## Support und Dokumentation

**Ausführliche Dokumentation:**
- `docs/MAPPING_QUALITY_ANALYSIS.md` - Technische Details, Algorithmen, API

**Bei Problemen:**
1. Prüfen Sie diese Troubleshooting-Sektion
2. Prüfen Sie Logs: `var/log/dev.log`
3. Debug-Modus aktivieren: `APP_ENV=dev`

**Hilfreiche Commands:**
```bash
# Alle mapping-quality Commands anzeigen
php bin/console list app | grep mapping

# Routing prüfen
php bin/console debug:router mapping_quality

# Doctrine Schema validieren
php bin/console doctrine:schema:validate

# Cache löschen
php bin/console cache:clear
```

---

## Checkliste: Erfolgreiches Setup

- [ ] Migration durchgeführt (`doctrine:migrations:migrate`)
- [ ] Test-Analyse erfolgreich (`--limit=10 --dry-run`)
- [ ] Vollständige Analyse abgeschlossen
- [ ] Dashboard öffnet sich ohne Fehler
- [ ] Charts werden angezeigt (Confidence, Gaps)
- [ ] Review Queue zeigt Mappings
- [ ] Mindestens 1 Mapping reviewed
- [ ] Gap-Übersicht zeigt Statistiken
- [ ] Navigation zu Compliance-Index funktioniert

**Gratulation! 🎉** Ihr Mapping Quality Analysis System ist einsatzbereit.

---

**Version:** 1.0.0
**Datum:** 2025-11-14
**Geschätzte Setup-Zeit:** 10-15 Minuten
**Geschätzte erste Review-Session:** 30-60 Minuten
