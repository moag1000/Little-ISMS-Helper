# ISO 27701:2019 Referenz (Vorgängerversion)

**Privacy Information Management System (PIMS) - Predecessor Edition**

## Überblick

**ISO/IEC 27701:2019** war die erste internationale Norm für Privacy Information Management Systems (PIMS) und erweiterte ISO 27001:2013 und ISO 27002:2013 um spezifische Datenschutz-Controls.

**Status**: **Predecessor** - ersetzt durch ISO 27701:2025 im Oktober 2025

**Wichtig für DPO Agent**:
- Viele Organisationen nutzen noch ISO 27701:2019
- Mapping zu 2025 wichtig für Migration
- Kernprinzipien bleiben gleich, aber Controls erweitert

---

## Struktur ISO 27701:2019

### Aufbau (wie 2025 Edition)

1. **Clause 5**: PIMS-specific requirements relating to ISO/IEC 27001
2. **Clause 6**: Guidance for PII controllers (34 controls in 2019 → **34 in 2025, aber erweitert**)
3. **Clause 7**: Guidance for PII processors (12 controls in 2019 → **12 in 2025**)
4. **Annexes**: Mapping zu verschiedenen Datenschutzrahmen

**Unterschied zu 2025**:
- **KEINE** AI Controls (Clause 6.4 fehlt in 2019!)
- **KEINE** Children's Data Controls (Clause 6.7 fehlt in 2019!)
- **KEINE** Privacy Metrics (Clause 8 fehlt in 2019!)
- Cross-Border Transfers weniger detailliert (Clause 6.5 kürzer)

---

## Clause 5: PIMS-specific requirements (2019)

### 5.1 General (identisch zu 2025)

**PIMS = ISO 27001 + Privacy Controls**

### 5.2 PIMS specific guidance related to ISO/IEC 27001

#### 5.2.1 Context of the organization

**5.2.1.1 Understanding the organization and its context**

**2019**: Berücksichtigung von:
- PII-Verarbeitungszwecke
- PII-Kategorien
- Betroffenengruppen

**2025**: **ERWEITERT** um:
- AI/ML-Systeme
- Kinder als besondere Gruppe

#### 5.2.1.2 Understanding the needs and expectations of interested parties

**Identisch** in 2019 und 2025:
- Data subjects
- Customers
- Regulatoren (Aufsichtsbehörden)
- DPO/Privacy Officer

#### 5.2.1.3 Determining the scope of the PIMS

**2019**: Scope muss PII-Verarbeitung definieren

**2025**: **ERWEITERT** - Scope muss auch AI/ML-Verarbeitung und Kinddaten umfassen

### 5.2.2 Leadership

**5.2.2.1 Roles, responsibilities and authorities**

**2019**: PIMS-Manager + DPO (wo erforderlich)

**2025**: **IDENTISCH** - aber in Praxis oft erweitert um AI Governance Role

### 5.3 Planning

**5.3.1 Actions to address risks and opportunities**

**2019**: Privacy Risk Assessment nach ISO 29134 (DPIA)

**2025**: **ERWEITERT** - auch AI-spezifische Risk Assessments (AI Act)

### 5.4 Support

**5.4.1 Resources**

**Identisch** in 2019 und 2025

**5.4.2 Competence**

**2019**: Datenschutz-Kompetenz erforderlich

**2025**: **ERWEITERT** - auch AI/ML Privacy Kompetenz

### 5.5 Operation

**5.5.1 Operational planning and control**

**Identisch** in 2019 und 2025

**5.5.2 Privacy by design and privacy by default**

**Identisch** in 2019 und 2025 (Art. 25 DSGVO)

**5.5.3 Data protection impact assessment (DPIA)**

**2019**: Nach ISO 29134

**2025**: **ERWEITERT** - DPIA auch für AI-Systeme (AI Act Art. 27)

### 5.6 Performance evaluation

**Identisch** in 2019 und 2025

### 5.7 Improvement

**Identisch** in 2019 und 2025

---

## Clause 6: Guidance for PII Controllers (2019 vs. 2025)

### Übersicht Controller Controls

| Clause | Control | 2019 Status | 2025 Status | Änderungen |
|--------|---------|-------------|-------------|------------|
| 6.2.1 | Conditions for collection and processing | ✅ Vorhanden | ✅ Erweitert | + AI explainability |
| 6.2.2 | Lawfulness of processing | ✅ Vorhanden | ✅ Identisch | - |
| 6.2.3 | Consent | ✅ Vorhanden | ✅ Erweitert | + Granularity |
| 6.2.4 | Purpose legitimacy and specification | ✅ Vorhanden | ✅ Identisch | - |
| 6.2.5 | PII minimization | ✅ Vorhanden | ✅ Erweitert | + Privacy-preserving tech |
| 6.3.1 | Obligations to data subjects | ✅ Vorhanden | ✅ Erweitert | + Automated refusal handling |
| 6.3.2 | Information to data subjects | ✅ Vorhanden | ✅ Erweitert | + AI disclosures |
| 6.3.3 | Providing mechanism to modify or withdraw consent | ✅ Vorhanden | ✅ Identisch | - |
| 6.3.4 | Providing mechanism to object to PII processing | ✅ Vorhanden | ✅ Identisch | - |
| 6.3.5 | Access, correction and/or erasure | ✅ Vorhanden | ✅ Erweitert | + Machine-readable format |
| 6.3.6 | PII disclosure to data subject | ✅ Vorhanden | ✅ Identisch | - |
| 6.3.7 | Correction or erasure of PII | ✅ Vorhanden | ✅ Identisch | - |
| 6.3.8 | Automated decision-making | ✅ Vorhanden | ✅ Erweitert | + Human-in-the-loop mandatory |
| **6.4** | **AI and Automated Decision-Making** | ❌ **NICHT VORHANDEN** | ✅ **NEU** | **8 neue AI Controls!** |
| 6.5.1 | Transfer of PII to third countries | ✅ Vorhanden | ✅ Erweitert | + TIA mandatory |
| 6.5.2 | Country and sector-specific requirements | ✅ Vorhanden | ✅ Identisch | - |
| 6.5.3 | Records related to PII disclosure to third parties | ✅ Vorhanden | ✅ Identisch | - |
| 6.6.1 | Protection of PII in records | ✅ Vorhanden | ✅ Identisch | - |
| 6.6.2 | Retention and disposal | ✅ Vorhanden | ✅ Identisch | - |
| **6.7** | **Children's PII** | ❌ **NICHT VORHANDEN** | ✅ **NEU** | **5 neue Children Controls!** |
| 6.8.1 | Identifying and communicating purpose | ✅ Vorhanden | ✅ Identisch | - |
| 6.8.2 | Privacy and data protection impact assessment | ✅ Vorhanden | ✅ Erweitert | + AI DPIA |
| 6.9.1 | Temporary files | ✅ Vorhanden | ✅ Identisch | - |

**Zusammenfassung Änderungen**:
- **2019**: 27 Controller Controls (6.2 bis 6.9)
- **2025**: **34 Controller Controls** (+7 neue)
  - **NEU Clause 6.4**: 8 AI Controls
  - **NEU Clause 6.7**: 5 Children Controls
  - **Erweitert**: 6.2.1, 6.2.3, 6.2.5, 6.3.1, 6.3.2, 6.3.5, 6.3.8, 6.5.1, 6.8.2

---

## Clause 7: Guidance for PII Processors (2019 vs. 2025)

### Übersicht Processor Controls

| Clause | Control | 2019 Status | 2025 Status | Änderungen |
|--------|---------|-------------|-------------|------------|
| 7.2.1 | PII processing conditions | ✅ Vorhanden | ✅ Identisch | - |
| 7.2.2 | Obligations to controller | ✅ Vorhanden | ✅ Erweitert | + AI processing notification |
| 7.2.3 | Accuracy and quality of PII | ✅ Vorhanden | ✅ Identisch | - |
| 7.2.4 | Return, transfer or disposal of PII | ✅ Vorhanden | ✅ Identisch | - |
| 7.2.5 | PII de-identification and deletion | ✅ Vorhanden | ✅ Erweitert | + Pseudonymization tech |
| 7.3.1 | Customer PII | ✅ Vorhanden | ✅ Identisch | - |
| 7.4.1 | Limits of processing | ✅ Vorhanden | ✅ Identisch | - |
| 7.4.2 | Sub-contracting | ✅ Vorhanden | ✅ Identisch | - |
| 7.4.3 | Change of sub-contractor | ✅ Vorhanden | ✅ Identisch | - |
| 7.4.4 | Processing in a specific jurisdiction | ✅ Vorhanden | ✅ Erweitert | + Cloud region controls |
| 7.4.5 | Sub-contractor records | ✅ Vorhanden | ✅ Identisch | - |
| 7.4.6 | Agreements with sub-contractors | ✅ Vorhanden | ✅ Identisch | - |

**Zusammenfassung Änderungen**:
- **2019**: 12 Processor Controls
- **2025**: **12 Processor Controls** (gleiche Anzahl)
  - **Erweitert**: 7.2.2, 7.2.5, 7.4.4
  - **Identisch**: 7.2.1, 7.2.3, 7.2.4, 7.3.1, 7.4.1, 7.4.2, 7.4.3, 7.4.5, 7.4.6

---

## Fehlende Clauses in ISO 27701:2019

### 1. Clause 6.4: AI and Automated Decision-Making (NEU in 2025)

**IN 2019 NICHT VORHANDEN!**

**Was fehlt in 2019**:
- 6.4.1 - Explainability of AI decisions
- 6.4.2 - Bias detection and mitigation
- 6.4.3 - Human-in-the-loop for high-risk decisions
- 6.4.4 - AI training data governance
- 6.4.5 - AI model documentation (Model Cards)
- 6.4.6 - Algorithmic impact assessment (AIA)
- 6.4.7 - AI incident response
- 6.4.8 - Third-party AI audits

**Migration 2019 → 2025**:
Wenn Sie ISO 27701:2019 nutzen und AI/ML-Systeme einsetzen:
- ✅ **Nutzen Sie 6.3.8 (2019)** - "Automated decision-making" (begrenzt!)
- ✅ **Ergänzen Sie DPIA** nach Art. 35 DSGVO + AI Act Art. 27
- ✅ **Upgrade auf 2025** empfohlen für vollständige AI-Governance

### 2. Clause 6.7: Children's PII (NEU in 2025)

**IN 2019 NICHT VORHANDEN!**

**Was fehlt in 2019**:
- 6.7.1 - Age verification mechanisms
- 6.7.2 - Parental/guardian consent
- 6.7.3 - Age-appropriate privacy notices
- 6.7.4 - Enhanced protection for children's data
- 6.7.5 - Restrictions on profiling children

**Migration 2019 → 2025**:
Wenn Sie Daten von Kindern (<16 Jahre) verarbeiten:
- ✅ **Nutzen Sie Art. 8 DSGVO** (Einwilligung Kinder)
- ✅ **DPIA mandatory** nach Art. 35(3)(a) DSGVO
- ✅ **Upgrade auf 2025** dringend empfohlen!

### 3. Clause 8: Privacy Metrics and KPIs (NEU in 2025)

**IN 2019 NICHT VORHANDEN!**

**Was fehlt in 2019**:
- 8.1 - Privacy KPIs (Key Performance Indicators)
- 8.2 - Privacy KRIs (Key Risk Indicators)
- 8.3 - Breach notification time tracking
- 8.4 - Data subject request SLA monitoring
- 8.5 - Consent withdrawal rate
- 8.6 - DPIA completion rate

**Migration 2019 → 2025**:
- ✅ **Manuelles Tracking** möglich (z.B. in unserer App!)
- ✅ **Upgrade auf 2025** für standardisierte Metriken

---

## Detaillierte Control-Vergleiche (2019 vs. 2025)

### 6.2.1: Conditions for collection and processing

**2019**:
```
Zweck muss spezifiziert sein BEVOR Datenerhebung
```

**2025**:
```
Zweck muss spezifiziert sein BEVOR Datenerhebung
+ AI/ML-Zwecke: Explainability erforderlich
+ Training data governance
```

**Migration**: Wenn AI/ML → zusätzliche Dokumentation erforderlich

### 6.2.3: Consent

**2019**:
```
Freely given, specific, informed, unambiguous
```

**2025**:
```
Freely given, specific, informed, unambiguous
+ Granular consent (per purpose, per category)
+ Easy withdrawal mechanism (same effort as giving consent)
+ Consent dashboard recommended
```

**Migration**: Bestehende Consent-Mechanismen prüfen → Granularität erhöhen

### 6.2.5: PII minimization

**2019**:
```
Collect only necessary PII
```

**2025**:
```
Collect only necessary PII
+ Use privacy-preserving technologies (differential privacy, federated learning)
+ Justify retention periods
```

**Migration**: Privacy-enhancing technologies (PETs) evaluieren

### 6.3.1: Obligations to data subjects

**2019**:
```
Respond to data subject requests (DSR)
```

**2025**:
```
Respond to data subject requests (DSR)
+ Automated refusal handling (with justification)
+ SLA tracking (Art. 12(3) DSGVO - 1 month)
+ Appeal mechanism
```

**Migration**: DSR-Workflow erweitern um automatisierte Ablehnungsbegründungen

**Best Practice in App**:
```php
// 2019: Einfacher DSR-Workflow
$request = new DataSubjectRequest();
$request->setType('access');
$request->setStatus('pending');

// 2025: Erweiterter Workflow
$request = new DataSubjectRequest();
$request->setType('access');
$request->setStatus('pending');
$request->setDeadline((new \DateTime())->modify('+1 month')); // Art. 12(3)
$request->setAutoRefusalReason(null); // Begründung bei Ablehnung
$request->setSlaCompliant(true); // Tracking
```

### 6.3.2: Information to data subjects

**2019**:
```
Privacy notice mit:
- Controller identity
- Purposes
- Legal basis
- Recipients
- Retention
- Rights
```

**2025**:
```
Privacy notice mit:
- Controller identity
- Purposes
- Legal basis
- Recipients
- Retention
- Rights
+ AI-specific disclosures (if automated decision-making)
+ Children-specific language (if processing children's data)
```

**Migration**: Privacy Notices erweitern für AI/Children

### 6.3.5: Access, correction and/or erasure

**2019**:
```
Provide copy of PII (Art. 15 DSGVO)
```

**2025**:
```
Provide copy of PII (Art. 15 DSGVO)
+ Machine-readable format (CSV, JSON)
+ Data portability (Art. 20 DSGVO) standardized
```

**Migration**: Export-Funktionen um JSON/CSV ergänzen

**Best Practice in App**:
```php
// 2019: PDF export only
$pdf = $processingActivityService->generateVVTExport($tenant);

// 2025: Multi-format export
$format = $request->getFormat(); // 'pdf', 'csv', 'json'
$export = match($format) {
    'json' => $processingActivityService->generateJsonExport($tenant),
    'csv' => $processingActivityService->generateCsvExport($tenant),
    default => $processingActivityService->generateVVTExport($tenant),
};
```

### 6.3.8: Automated decision-making

**2019**:
```
- Right not to be subject to automated decision-making (Art. 22 DSGVO)
- Human intervention möglich
```

**2025**:
```
- Right not to be subject to automated decision-making (Art. 22 DSGVO)
- Human-in-the-loop MANDATORY for high-risk decisions
- Explainability erforderlich (AI Act)
- Challenge mechanism
```

**Migration**:
- High-risk AI? → Human-in-the-loop implementieren
- Explainability-Features hinzufügen

### 6.5.1: Transfer of PII to third countries

**2019**:
```
- Adequacy decision (Art. 45 DSGVO)
- Appropriate safeguards (Art. 46 DSGVO)
  - SCCs (Standard Contractual Clauses)
  - BCRs (Binding Corporate Rules)
```

**2025**:
```
- Adequacy decision (Art. 45 DSGVO)
- Appropriate safeguards (Art. 46 DSGVO)
  - SCCs (Standard Contractual Clauses)
  - BCRs (Binding Corporate Rules)
+ Transfer Impact Assessment (TIA) MANDATORY (Post-Schrems II)
+ Documentation of supplementary measures
+ Suspension mechanism if inadequate protection
```

**Migration**:
- **KRITISCH**: Alle Drittlandtransfers → TIA durchführen!
- Supplementary measures dokumentieren (Verschlüsselung, Pseudonymisierung)

**Best Practice in App**:
```php
// 2019: ProcessingActivity mit Drittlandtransfer
$activity = new ProcessingActivity();
$activity->setThirdCountryTransfer(true);
$activity->setThirdCountries(['USA']);
$activity->setSafeguards('Standard Contractual Clauses (SCCs)');

// 2025: + Transfer Impact Assessment (TIA)
$activity = new ProcessingActivity();
$activity->setThirdCountryTransfer(true);
$activity->setThirdCountries(['USA']);
$activity->setSafeguards('Standard Contractual Clauses (SCCs) + Encryption');
$activity->setTiaCompleted(true); // NEU!
$activity->setTiaDate(new \DateTime('2025-01-15'));
$activity->setSupplementaryMeasures([
    'End-to-end encryption',
    'Pseudonymization',
    'Access controls'
]);
```

### 6.8.2: Privacy and data protection impact assessment (DPIA)

**2019**:
```
DPIA nach ISO 29134 für:
- Art. 35(3) DSGVO criteria:
  (a) Systematic evaluation / profiling
  (b) Large-scale special categories
  (c) Systematic monitoring of public areas
```

**2025**:
```
DPIA nach ISO 29134 für:
- Art. 35(3) DSGVO criteria
+ AI-specific DPIA (AI Act Art. 27)
+ Children's data → DPIA mandatory
+ Transfer Impact Assessment (TIA) integration
```

**Migration**:
- AI-Systeme → erweiterte DPIA
- Kinddaten → automatisch DPIA-pflichtig

---

## Processor Controls Vergleich

### 7.2.2: Obligations to controller

**2019**:
```
Processor muss Controller informieren über:
- Data breaches (Art. 33 DSGVO)
- Sub-processor changes
```

**2025**:
```
Processor muss Controller informieren über:
- Data breaches (Art. 33 DSGVO)
- Sub-processor changes
+ AI/ML processing activities (NEW!)
+ Third-country data centers
```

**Migration**: Prozessoren müssen AI-Nutzung offenlegen

### 7.2.5: PII de-identification and deletion

**2019**:
```
- Anonymisierung
- Pseudonymisierung
- Sichere Löschung
```

**2025**:
```
- Anonymisierung
- Pseudonymisierung (with modern techniques: differential privacy, k-anonymity)
- Sichere Löschung
+ Privacy-preserving computation (homomorphic encryption, secure multi-party computation)
```

**Migration**: Moderne Pseudonymisierungstechniken evaluieren

### 7.4.4: Processing in a specific jurisdiction

**2019**:
```
Processor muss Datenstandort angeben
```

**2025**:
```
Processor muss Datenstandort angeben
+ Cloud region controls (AWS regions, Azure regions)
+ Data residency guarantees
+ Jurisdiction-specific compliance (EU, US, CN)
```

**Migration**: Cloud-Provider-Verträge prüfen → Data Residency garantieren

---

## Annexes: GDPR Mapping (2019 vs. 2025)

### Annex A: GDPR Article Mapping

**2019**:
```
Annex A.1: Art. 5 DSGVO (Principles) → 6.2.1 bis 6.2.5
Annex A.2: Art. 6 DSGVO (Lawfulness) → 6.2.2
Annex A.3: Art. 9 DSGVO (Special categories) → 6.2.2
...
Annex A.27: Art. 35 DSGVO (DPIA) → 6.8.2
```

**2025**:
```
Alle 2019 Mappings +
+ Art. 22 DSGVO (Automated decisions) → 6.4.1-6.4.8 (NEW!)
+ Art. 8 DSGVO (Children) → 6.7.1-6.7.5 (NEW!)
+ Art. 44-49 DSGVO (Transfers) → 6.5.1 (ERWEITERT mit TIA)
```

**Migration**: Neue Mappings beachten für AI und Kinder

---

## Migration Checklist: ISO 27701:2019 → 2025

### Phase 1: Gap Analysis (Woche 1-2)

- [ ] **AI-Systeme identifizieren**
  - Liste aller AI/ML-Systeme (inkl. Drittanbieter wie ChatGPT, Salesforce Einstein)
  - Risiko-Klassifizierung (high-risk nach AI Act?)
  - Gap zu Clause 6.4 (8 AI Controls)

- [ ] **Kinddaten identifizieren**
  - Verarbeiten wir Daten von <16-Jährigen?
  - Gap zu Clause 6.7 (5 Children Controls)
  - DPIA-Pflicht prüfen

- [ ] **Drittlandtransfers prüfen**
  - Alle Transfers nach Non-EU/EEA
  - TIA durchgeführt? (POST-Schrems II mandatory!)
  - Supplementary measures dokumentiert?

- [ ] **Privacy Metrics evaluieren**
  - Welche KPIs/KRIs tracken wir bereits?
  - Gap zu Clause 8 (Privacy Metrics)

### Phase 2: Quick Wins (Woche 3-4)

- [ ] **6.2.3 - Granular Consent**
  - Consent-Mechanismus auf Granularität prüfen
  - Easy withdrawal implementieren

- [ ] **6.3.1 - DSR SLA Tracking**
  - 1-Monats-Deadline automatisch tracken
  - Automatisierte Erinnerungen bei Fristablauf

- [ ] **6.3.5 - Machine-readable Export**
  - JSON/CSV-Export für DSR-Requests
  - Data Portability (Art. 20 DSGVO)

- [ ] **6.5.1 - TIA für alle Drittlandtransfers**
  - TIA-Template erstellen (based on EDPB Recommendations 01/2020)
  - Durchführung für alle Transfers

### Phase 3: Major Changes (Woche 5-12)

- [ ] **Clause 6.4 - AI Controls (falls AI genutzt)**
  - 6.4.1 - Explainability implementieren
  - 6.4.2 - Bias detection einrichten
  - 6.4.3 - Human-in-the-loop für high-risk AI
  - 6.4.4 - Training data governance
  - 6.4.5 - Model Cards erstellen
  - 6.4.6 - Algorithmic Impact Assessment (AIA)
  - 6.4.7 - AI incident response plan
  - 6.4.8 - Third-party AI audits

- [ ] **Clause 6.7 - Children's Data (falls Kinder <16)**
  - 6.7.1 - Age verification mechanism
  - 6.7.2 - Parental consent workflow
  - 6.7.3 - Age-appropriate privacy notices
  - 6.7.4 - Enhanced protection measures
  - 6.7.5 - Profiling restrictions

- [ ] **Clause 8 - Privacy Metrics**
  - 8.1 - Privacy KPIs definieren (z.B. DPIA completion rate)
  - 8.2 - Privacy KRIs definieren (z.B. breach frequency)
  - 8.3 - 72h-Breach-Notification tracking
  - 8.4 - DSR SLA monitoring (1 month)
  - 8.5 - Consent withdrawal rate
  - 8.6 - Dashboard für Management

### Phase 4: Recertification (Monat 4-6)

- [ ] **Internal Audit**
  - Audit gegen ISO 27701:2025 (nicht 2019!)
  - Non-conformities dokumentieren

- [ ] **External Certification (optional)**
  - Zertifizierungsaudit durch akkreditierte Stelle
  - ISO 27701:2025 Zertifikat

---

## Best Practices für Migration in der App

### 1. Feature Flag für 2025 Controls

```php
// config/modules.yaml
privacy_2025_features:
    enabled: true  # false für 2019-only Modus
    ai_controls: true
    children_controls: true
    privacy_metrics: true
```

### 2. Data Reuse für Migration

```php
// ProcessingActivity mit 2019 → 2025 Migration Flag
$activity = new ProcessingActivity();
$activity->setIso27701Version('2019'); // oder '2025'

// Automatische Prüfung bei Speichern
if ($activity->getIso27701Version() === '2019') {
    // Warnungen für fehlende 2025 Controls
    if ($activity->involvesAI()) {
        $this->auditLogger->log('WARNING: AI detected but ISO 27701:2019 - Upgrade to 2025 recommended!');
    }

    if ($activity->involvesChildren()) {
        $this->auditLogger->log('WARNING: Children detected but ISO 27701:2019 - Upgrade to 2025 MANDATORY!');
    }
}
```

### 3. Migration Command

```php
// src/Command/MigrateIso27701Command.php
class MigrateIso27701Command extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $activities = $this->processingActivityRepository->findAll();

        foreach ($activities as $activity) {
            // 1. Check AI involvement
            if ($this->detectAI($activity)) {
                $activity->setRequiresAiControls(true);
                $output->writeln("AI detected: {$activity->getName()} - Clause 6.4 required");
            }

            // 2. Check children involvement
            if ($this->detectChildren($activity)) {
                $activity->setRequiresChildrenControls(true);
                $output->writeln("Children detected: {$activity->getName()} - Clause 6.7 required");
            }

            // 3. Check third-country transfers
            if ($activity->getThirdCountryTransfer() && !$activity->getTiaCompleted()) {
                $output->writeln("TIA missing: {$activity->getName()} - 6.5.1 requires TIA");
            }

            // 4. Update to 2025
            $activity->setIso27701Version('2025');
            $this->entityManager->persist($activity);
        }

        $this->entityManager->flush();
        $output->writeln("Migration to ISO 27701:2025 completed!");

        return Command::SUCCESS;
    }

    private function detectAI(ProcessingActivity $activity): bool
    {
        $aiKeywords = ['AI', 'ML', 'Machine Learning', 'Automated Decision', 'Algorithm', 'Profiling'];

        foreach ($aiKeywords as $keyword) {
            if (stripos($activity->getDescription(), $keyword) !== false) {
                return true;
            }
        }

        return false;
    }

    private function detectChildren(ProcessingActivity $activity): bool
    {
        $childCategories = ['Kinder', 'Children', 'Schüler', 'Students', '<16', 'Minderjährige'];

        foreach ($childCategories as $category) {
            if (in_array($category, $activity->getDataSubjectCategories())) {
                return true;
            }
        }

        return false;
    }
}
```

---

## Zusammenfassung: 2019 vs. 2025

| Aspekt | ISO 27701:2019 | ISO 27701:2025 | Migration Priority |
|--------|----------------|----------------|-------------------|
| **AI Controls** | ❌ Nicht vorhanden | ✅ Clause 6.4 (8 Controls) | 🔴 **HIGH** (if using AI) |
| **Children's Data** | ❌ Nicht vorhanden | ✅ Clause 6.7 (5 Controls) | 🔴 **HIGH** (if <16 data) |
| **Privacy Metrics** | ❌ Nicht vorhanden | ✅ Clause 8 (6 KPIs/KRIs) | 🟡 **MEDIUM** |
| **TIA (Transfers)** | ⚠️ Nicht mandatory | ✅ Mandatory (6.5.1) | 🔴 **HIGH** (post-Schrems II) |
| **Granular Consent** | ⚠️ Basic | ✅ Enhanced (6.2.3) | 🟡 **MEDIUM** |
| **DSR SLA Tracking** | ⚠️ Manual | ✅ Automated (6.3.1) | 🟢 **LOW** (nice-to-have) |
| **Machine-readable Export** | ⚠️ PDF only | ✅ JSON/CSV (6.3.5) | 🟢 **LOW** (nice-to-have) |
| **Controller Controls** | 27 Controls | 34 Controls (+7) | - |
| **Processor Controls** | 12 Controls | 12 Controls (same) | - |

**Empfehlung**:
- **Wenn AI/ML genutzt** → **sofortiges Upgrade auf 2025 erforderlich**
- **Wenn Kinddaten verarbeitet** → **sofortiges Upgrade auf 2025 erforderlich**
- **Wenn Drittlandtransfers** → **TIA sofort durchführen** (auch mit 2019!)
- **Sonst** → Upgrade auf 2025 innerhalb 12 Monate empfohlen

---

**Version**: 1.0 (November 2025)
**Status**: ISO 27701:2019 ist **PREDECESSOR** - ersetzt durch ISO 27701:2025