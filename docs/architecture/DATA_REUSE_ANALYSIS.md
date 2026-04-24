# Data Reuse Analysis - Entity Relationship Audit

## Ziel
Maximale Wiederverwendung eingegebener Daten über alle ISMS-Module hinweg.

## Aktueller Status

### ✅ Bereits implementierte Beziehungen

#### Asset
- ✓ Risk (one-to-many) - Assets haben Risiken
- ✓ BusinessProcess (many-to-many) - Assets unterstützen Prozesse

#### Risk
- ✓ Asset (many-to-one) - Risiko betrifft Asset
- ✓ Control (many-to-many) - Controls mitigieren Risiken

#### Control
- ✓ Risk (many-to-many) - Controls addressieren Risiken
- ✓ Incident (many-to-many) - Incidents zeigen Control-Versagen
- ✓ ComplianceRequirement (many-to-many) - Controls erfüllen Requirements

#### Incident
- ✓ Control (many-to-many) - Betroffene/empfohlene Controls

#### ComplianceRequirement
- ✓ Control (many-to-many) - ISO 27001 Control Mapping
- ✓ ComplianceRequirement (parent-child) - Hierarchische Anforderungen
- ✓ ComplianceMapping (cross-framework) - Norm-übergreifende Mappings

#### InternalAudit
- ✓ Asset (many-to-many via scopedAssets) - Asset-spezifische Audits
- ✓ ComplianceFramework (many-to-one) - Framework-spezifische Audits
- ✓ AuditChecklist - Requirement Verification

#### BusinessProcess
- ✓ Asset (many-to-many) - Supporting Assets

## ❌ Fehlende kritische Beziehungen

### ~~1. **Asset ↔ Incident** (KRITISCH)~~ ✅ IMPLEMENTED
**Implementierung**: `Incident.affectedAssets` ManyToMany-Beziehung
- Asset-spezifische Incident-Historie verfügbar
- Risikobewertung basierend auf Asset-Vorfällen möglich
- Identifikation von "High-Risk Assets" über Incident-Daten

### ~~2. **Risk ↔ Incident** (KRITISCH)~~ ✅ IMPLEMENTED
**Implementierung**: `Risk.incidents` ManyToMany mit Data-Reuse-Methoden
- Risk Validation: realisierte Risiken mit tatsächlichen Incidents verknüpft
- Probability Adjustment basierend auf eingetretenen Risiken
- Impact Validation: tatsächlicher vs. geschätzter Impact vergleichbar

### 3. **Control ↔ Asset** (WICHTIG)
**Problem**: Keine direkte Zuordnung welche Controls welche Assets schützen
**Impact**:
- Keine Asset-Coverage-Analyse
- Schwierig zu sehen: "Welche Assets sind unzureichend geschützt?"
- Keine Control-Effektivität pro Asset-Typ

**Lösung**: Many-to-Many Beziehung

**Data Reuse Potential**:
- Asset Protection Matrix: "Asset X ist durch Controls A, B, C geschützt"
- Coverage Gaps: "Assets ohne zugeordnete Controls"
- Control Priority: "Controls die kritische Assets schützen = höhere Priorität"

### 4. **Training ↔ Control** (WICHTIG)
**Problem**: Keine Verknüpfung zwischen Schulungen und Controls
**Impact**:
- Nicht nachvollziehbar, welche Controls Awareness erfordern
- Keine Training-Gap-Analyse
- ISO 27001 Annex A 6.3 nicht vollständig nachweisbar

**Lösung**: Many-to-Many Beziehung

**Data Reuse Potential**:
- Training Coverage: "Control X erfordert Training Y"
- People Controls: "Mitarbeiter geschult für Controls A, B, C"
- Compliance Evidence: "Schulungsnachweise für People Controls"

### 5. **BusinessProcess ↔ Risk** (WICHTIG)
**Problem**: Keine direkte Verknüpfung zwischen Prozessen und Risiken
**Impact**:
- Business Impact nicht mit Risiken verknüpft
- Keine Priorisierung von Risiken nach Business-Kritikalität
- BCM-Daten fließen nicht in Risikobewertung ein

**Lösung**: Many-to-Many Beziehung

**Data Reuse Potential**:
- Risk Priority: "Risiken für kritische Prozesse = höhere Priorität"
- BIA Integration: "Prozess mit RTO 1h + Risiko = sehr hohe Dringlichkeit"
- Business-aligned Risk Treatment: Investitionen nach Geschäftswert

### 6. **Training ↔ ComplianceRequirement** (NÜTZLICH)
**Problem**: Awareness-Requirements nicht mit Trainings verknüpft
**Impact**:
- DORA Art. 13.6, TISAX People Controls nicht nachweisbar
- Keine systematische Training-Planung für Compliance

**Lösung**: Many-to-Many Beziehung

**Data Reuse Potential**:
- Compliance Training Matrix
- Automatic Fulfillment: "Training durchgeführt → Requirement erfüllt"

## 📊 Quantifizierung des Data Reuse Potentials

### Ohne neue Beziehungen:
- Asset Risk Assessment: Manuelle Analyse erforderlich (~2h pro Asset)
- Incident Pattern Analysis: Separate Reports (~4h)
- Control Coverage: Manuelle Matrix (~3h)
- Training Compliance: Separate Tracking (~2h)

**Total**: ~11 Stunden manuelle Arbeit pro Audit-Zyklus

### Mit neuen Beziehungen:
- Automatische Asset Risk Profiles
- Automatische Incident Pattern Detection
- Automatische Control Coverage Matrix
- Automatische Training Compliance Reports

**Total**: ~0.5 Stunden (nur Review der automatischen Berichte)

**Zeitersparnis**: 10.5 Stunden (95%) pro Audit-Zyklus

## 🎯 Implementierungspriorität

1. **KRITISCH** - Asset ↔ Incident
2. **KRITISCH** - Risk ↔ Incident
3. **WICHTIG** - Control ↔ Asset
4. **WICHTIG** - Training ↔ Control
5. **WICHTIG** - BusinessProcess ↔ Risk
6. **NÜTZLICH** - Training ↔ ComplianceRequirement

## 🔄 Neue Data Reuse Patterns nach Implementierung

1. **Incident → Risk → Control → Asset** (Full Lifecycle)
2. **BusinessProcess → Risk → Control → Training** (Business-aligned)
3. **ComplianceRequirement → Control → Asset → Incident** (Compliance Evidence)
4. **Training → Control → Risk → BusinessProcess** (Awareness Impact)

## 📈 KPIs die möglich werden

- **Asset Risk Score**: Berechnet aus Risiken + Incidents + Control Coverage
- **Control Effectiveness**: Incidents vor/nach Control-Implementierung
- **Training ROI**: Incidents/Risiken vor/nach Training
- **Business Process Risk**: Kombiniert BIA + Risiken + Incidents
- **Compliance Coverage**: Requirements → Controls → Assets → Evidence
