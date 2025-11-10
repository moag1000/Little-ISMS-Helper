# Little ISMS Helper - Lösungsbeschreibung

## 1. Problemstellung und Lösungsansatz

### Welche Probleme löst die Software?

Kleine und mittelständische Unternehmen stehen vor mehreren Herausforderungen bei der Implementierung eines Informationssicherheitsmanagementsystems (ISMS):

**1. Mehrfacherfassung von Daten**
- Dieselben Informationen müssen für verschiedene Compliance-Anforderungen mehrfach dokumentiert werden
- Risikodaten, Asset-Informationen und Business-Continuity-Daten werden isoliert in verschiedenen Dokumenten gepflegt
- Bei Änderungen müssen mehrere Dokumente manuell aktualisiert werden

**2. Fehlende Verknüpfungen**
- Zusammenhänge zwischen Risiken, Vorfällen und Controls sind nicht nachvollziehbar
- Audit-Ergebnisse fließen nicht systematisch in das Risikomanagement ein
- Die Wirksamkeit implementierter Maßnahmen lässt sich schwer überprüfen

**3. Manuelle Compliance-Nachweise**
- Für verschiedene Frameworks (ISO 27001, TISAX, DORA) werden separate Dokumentationen geführt
- Überschneidungen zwischen verschiedenen Anforderungen werden nicht erkannt
- Der Nachweis der Compliance erfordert manuelle Zusammenstellung von Dokumenten

**4. Fehlende Transparenz**
- Der aktuelle Stand der ISMS-Implementierung ist nicht auf einen Blick erkennbar
- Offene Maßnahmen, Risiken und Vorfälle werden in verschiedenen Listen geführt
- Management-Reviews erfordern zeitaufwändige manuelle Datensammlung

**5. Komplexe Workflows**
- Genehmigungsprozesse für Risiken, Controls und Vorfälle sind nicht standardisiert
- Fälligkeiten und Eskalationen werden manuell nachverfolgt
- Benachrichtigungen erfolgen ad-hoc per E-Mail

### Lösungsansatz

Die Software implementiert ein **datenzentrisches ISMS-Managementsystem mit intelligenten Workflows**, bei dem Informationen einmal erfasst und über Module hinweg wiederverwendet werden. Durch systematische Verknüpfungen zwischen Assets, Risiken, Controls, Vorfällen und Compliance-Anforderungen entsteht eine konsistente Datenbasis, die mehrfache Verwendung findet.

---

## 2. Nutzen und Vorteile

### Was ist der Benefit die Lösung einzusetzen?

**Reduzierung von Doppelarbeit**
- Einmal erfasste Daten werden automatisch für verschiedene Zwecke wiederverwendet
- Business-Impact-Analysen (BIA) fließen direkt in Asset-Schutzanforderungen ein
- Control-Implementierungsstatus wird automatisch für Compliance-Nachweise genutzt

**Konsistente Datenbasis**
- Alle Module greifen auf dieselben Stammdaten zu (Assets, Controls, Prozesse)
- Änderungen wirken sich automatisch auf alle abhängigen Berechnungen aus
- Widersprüche zwischen verschiedenen Dokumenten werden vermieden

**Nachvollziehbarkeit für Audits**
- Zusammenhänge zwischen Anforderungen, Controls und Nachweisen sind dokumentiert
- Compliance-Status lässt sich jederzeit abrufen
- Vollständige Audit-Trails durch AuditLog-System

**Datengetriebene Insights**
- Automatische Berechnung von Restrisiken basierend auf Control-Implementierung
- Validierung von Risikobewertungen durch tatsächlich eingetretene Vorfälle
- Erkennung von Mustern in der Vorfallshistorie
- Analytics-Dashboard mit Heat Maps, Trend-Charts und Compliance-Radar

**Unterstützung mehrerer Frameworks**
- Cross-Framework-Mappings zeigen, welche ISO 27001-Controls auch TISAX- oder DORA-Anforderungen erfüllen
- Transitive Compliance-Berechnungen: Erfüllung einer Anforderung trägt automatisch zu anderen bei
- Gap-Analysen identifizieren fehlende Nachweise framework-übergreifend

**Automatisierte Workflows**
- Standardisierte Genehmigungsprozesse für Risiken, Controls und Vorfälle
- Automatische E-Mail-Benachrichtigungen bei Fälligkeiten
- Eskalation überfälliger Aufgaben

---

## 3. Architektur und Technologien

### Technologie-Stack

- **Backend**: PHP 8.4, Symfony 7.3
- **Database**: PostgreSQL/MySQL mit Doctrine ORM
- **Frontend**: Twig Templates, Symfony UX (Turbo, Stimulus)
- **Charts**: Chart.js für Analytics-Visualisierungen
- **Authentication**: Lokale Auth + Azure AD (OAuth 2.0, SAML)
- **Export**: TCPDF (PDF), PhpSpreadsheet (Excel)
- **Testing**: PHPUnit mit 122+ Tests

### Architektur-Prinzipien

**Data Reuse Architecture**
- Services berechnen Insights aus vernetzten Daten
- Entities bieten intelligente Getter-Methoden für Cross-Entity-Analysen
- Vermeidung von Datensilos durch konsequente Verknüpfungen

**Service-Oriented Design**
- Geschäftslogik in wiederverwendbaren Services
- Controller als dünne Schicht für Request-Handling
- Trennung von Datenmodell und Anwendungslogik

**Security by Design**
- Role-Based Access Control (RBAC) mit hierarchischen Rollen
- Granulare Permissions auf Entity-Ebene (view, create, edit, delete)
- Vollständiges Audit Logging aller Änderungen
- CSRF-Protection, Input Validation, SQL Injection Prevention

---

## 4. Module und Funktionen

### Core ISMS Module

#### **4.1 Asset Management**

**Zweck:** Verwaltung von IT-Assets und Informationswerten

**Entity:** `src/Entity/Asset.php`
**Controller:** `src/Controller/AssetController.php`

**Funktionalität:**
- Erfassung von Assets mit CIA-Bewertung (Confidentiality, Integrity, Availability)
- Asset-Typen: Hardware, Software, Data, Services, People, Premises
- Eigentümer, Standorte, Abteilungen
- Verknüpfung mit Risiken, Controls und Geschäftsprozessen
- Automatische Berechnung von Risiko-Scores basierend auf verknüpften Risiken und Vorfällen
- Modern UI mit Filterung, Suche und Bulk-Actions

**Datenwiederverwendung:**
- Asset-CIA-Werte werden aus Business-Continuity-Daten (RTO/RPO) abgeleitet (via `ProtectionRequirementService`)
- Verfügbarkeitsanforderungen ergeben sich aus der Kritikalität unterstützter Geschäftsprozesse
- Asset-Inventar erfüllt automatisch Asset-Management-Anforderungen in Compliance-Frameworks

#### **4.2 Risk Assessment & Treatment**

**Zweck:** Strukturiertes Risikomanagement nach ISO 27001

**Entity:** `src/Entity/Risk.php`
**Controller:** `src/Controller/RiskController.php`
**Service:** `src/Service/RiskIntelligenceService.php`, `src/Service/RiskMatrixService.php`

**Funktionalität:**
- Risikoidentifikation mit Bedrohungen und Schwachstellen
- Risikobewertung nach Likelihood (1-5) × Impact (1-5) = Risk Level (1-25)
- Risikobehandlungsstrategien: Accept, Mitigate, Transfer, Avoid
- Berechnung von Restrisiken nach Control-Implementierung (RiskIntelligenceService)
- Risk Matrix Visualisierung mit Heat Map
- Workflow-Integration für Risiko-Genehmigungen

**Datenwiederverwendung:**
- Vorfallshistorie validiert Risikobewertungen (wurden Risiken tatsächlich realisiert?)
- Implementierte Controls reduzieren Restrisiken automatisch (30% max Reduktion pro Control, 80% cap)
- Aus Vorfällen werden neue Risiken vorgeschlagen (Threat Intelligence via `suggestRisksFromIncidents()`)

**Risk Intelligence Service:**
```php
// RiskIntelligenceService.php
public function calculateResidualRisk(Risk $risk): array
{
    // 30% max Reduktion pro implementiertem Control
    // 80% cap für maximale Risikoreduktion
    // Zeile 85-93
}

public function suggestRisksFromIncidents(): array
{
    // Threat Intelligence aus Incident-Historie
    // Zeile 31-59
}
```

#### **4.3 Statement of Applicability (SoA)**

**Zweck:** Verwaltung der Anwendbarkeit von ISO 27001 Annex A Controls

**Entity:** `src/Entity/Control.php`
**Controller:** `src/Controller/StatementOfApplicabilityController.php`

**Funktionalität:**
- Verwaltung aller 93 Controls aus ISO 27001:2022 Annex A
- Festlegung der Anwendbarkeit (applicable/not_applicable) mit Begründung
- Implementierungsstatus: not_started, in_progress, implemented, deprecated
- Implementierungsfortschritt (0-100%)
- Verantwortlichkeiten, Zieldaten, Review-Zyklen
- Control-Wirksamkeits-Scoring basierend auf Vorfallsreduktion

**Datenwiederverwendung:**
- Control-Implementierungsstatus fließt in Restrisiko-Berechnung ein (RiskIntelligenceService)
- Implementierte Controls werden für Compliance-Nachweise mehrerer Frameworks verwendet (ComplianceMappingService)
- Control-Wirksamkeit wird durch Vorfallsanalyse validiert (`getEffectivenessScore()`)

**Data Reuse Methoden (Control.php):**
```php
getProtectedAssetValue(): int          // Zeile 341 - Aggregiert CIA-Werte geschützter Assets
getHighRiskAssetCount(): int           // Zeile 354 - Zählt hochriskante Assets
getEffectivenessScore(): float         // Zeile 363 - Vergleicht Vorfälle vor/nach Implementierung
needsReview(): bool                    // Zeile 396 - Trigger aus Incident-Daten
getTrainingStatus(): array             // Zeile 460 - Identifiziert Training-Gaps
```

#### **4.4 Incident Management**

**Zweck:** Strukturierte Behandlung von Sicherheitsvorfällen

**Entity:** `src/Entity/Incident.php`
**Controller:** `src/Controller/IncidentController.php`

**Funktionalität:**
- Vorfallsdokumentation mit Kategorisierung (Data Breach, Malware, Phishing, etc.)
- Schweregrad: Low, Medium, High, Critical
- Zeitstempel für Detected, Contained, Resolved
- Root Cause Analysis und Lessons Learned
- Korrektur- und Präventivmaßnahmen
- Data Breach Tracking für DSGVO-Meldepflichten
- Workflow-Integration für Incident-Response

**Datenwiederverwendung:**
- Vorfälle werden mit Assets, Risiken und Controls verknüpft
- Vorfallsmuster fließen in Risikobewertungen ein (RiskIntelligenceService.analyzeIncidentTrends())
- Erfolgreiche Incident-Response-Maßnahmen werden als Control-Empfehlungen vorgeschlagen
- Vorfallsdaten erfüllen Compliance-Anforderungen für Incident-Management-Nachweise

#### **4.5 Business Continuity Management (BCM)**

**Zweck:** Business Impact Analysis und Kontinuitätsplanung

**Entity:** `src/Entity/BusinessProcess.php`
**Controller:** `src/Controller/BCMController.php`, `src/Controller/BusinessProcessController.php`
**Service:** `src/Service/ProtectionRequirementService.php`

**Funktionalität:**
- Erfassung von Geschäftsprozessen mit Kritikalitätsbewertung (1-5)
- Recovery Time Objective (RTO), Recovery Point Objective (RPO), MTPD
- Finanzielle, reputative, regulatorische und operationelle Impact-Bewertung
- Verknüpfung mit unterstützenden IT-Assets
- Berechnung von Business Impact Scores

**Datenwiederverwendung:**
- RTO/RPO-Werte definieren automatisch Verfügbarkeitsanforderungen für Assets (BusinessProcess.getSuggestedAvailabilityValue())
- Kritikalität von Prozessen fließt in Asset-Schutzanforderungen ein
- BCM-Daten erfüllen DORA-Anforderungen zur operationellen Resilienz

**Protection Requirement Service:**
```php
// ProtectionRequirementService.php
public function calculateAvailabilityRequirement(BusinessProcess $process): array
{
    // RTO ≤ 1h → Availability 5 (Sehr hoch)
    // Nutzt BusinessProcess.getSuggestedAvailabilityValue()
    // Zeile 32-86
}
```

**Business Process Data Reuse (BusinessProcess.php):**
```php
getBusinessImpactScore(): int          // Zeile 313 - Aggregiert alle Impact-Dimensionen
getSuggestedAvailabilityValue(): int   // Zeile 322 - RTO ≤ 1h → 5, ≤ 4h → 4, ≤ 24h → 3
getProcessRiskLevel(): float           // Zeile 363 - Kombiniert BIA mit tatsächlichen Risiken
isCriticalityAligned(): bool           // Zeile 402 - Cross-Validierung BIA vs Risk Assessment
getSuggestedRTO(): ?int                // Zeile 426 - Risikodaten informieren bessere RTO-Werte
hasUnmitigatedHighRisks(): bool        // Zeile 457 - Automatische Alert-Erkennung
```

#### **4.6 Internal Audit Management**

**Zweck:** Planung und Durchführung interner ISMS-Audits

**Entity:** `src/Entity/InternalAudit.php`, `src/Entity/AuditChecklist.php`
**Controller:** `src/Controller/AuditController.php`

**Funktionalität:**
- Flexible Audit-Geltungsbereiche: gesamtes ISMS, spezifische Frameworks, Assets, Standorte, Abteilungen
- Audit-Team und Zeitplanung (Planned Date, Actual Date)
- Audit-Checklisten mit Verknüpfung zu Compliance-Anforderungen
- Findings, Nichtkonformitäten (Minor, Major, Critical)
- Audit-Berichte mit PDF-Export
- Empfehlungen und Follow-up-Actions

**Datenwiederverwendung:**
- Audit-Checklisten werden aus Compliance-Anforderungen generiert
- Audit-Ergebnisse fließen in Compliance-Fulfillment-Berechnungen ein (ComplianceMappingService.analyzeAuditContribution())
- Nichtkonformitäten können automatisch Risiko-Reviews auslösen

#### **4.7 Management Review**

**Zweck:** Managementbewertung des ISMS nach ISO 27001 Clause 9.3

**Entity:** `src/Entity/ManagementReview.php`
**Controller:** `src/Controller/ManagementReviewController.php`

**Funktionalität:**
- Strukturierte Review-Dokumentation (Quarterly, Semi-Annual, Annual)
- Input: Audit-Ergebnisse, Vorfälle, Risiken, Compliance-Status, KPIs
- Bewertung der ISMS-Performance (Inadequate, Needs Improvement, Adequate, Exceeds Expectations)
- Entscheidungen und daraus resultierende Maßnahmen
- Follow-up vorheriger Reviews
- Teilnehmerverwaltung

**Datenwiederverwendung:**
- KPIs aus anderen Modulen (Vorfälle, Risiken, Compliance-Status) fließen automatisch ein
- Audit-Ergebnisse werden berücksichtigt
- Offene Maßnahmen aus vorherigen Reviews werden getrackt

#### **4.8 Training & Awareness**

**Zweck:** Schulungsmanagement für ISMS-relevante Themen

**Entity:** `src/Entity/Training.php`
**Controller:** `src/Controller/TrainingController.php`

**Funktionalität:**
- Schulungsplanung mit Terminen und Wiederholungszyklen
- Teilnehmerverwaltung mit Anwesenheits-Tracking
- Feedback-Erfassung und Effektivitätsbewertung
- Verknüpfung mit behandelten Controls
- E-Mail-Benachrichtigungen bei Fälligkeiten

**Datenwiederverwendung:**
- Training-Abdeckung wird für Controls nachgewiesen (Control.getTrainingStatus())
- Schulungseffektivität kann mit Control-Implementierungsgrad korreliert werden (Training.getTrainingEffectiveness())
- Kritische Controls mit niedriger Schulungsabdeckung werden identifiziert (Training.addressesCriticalControls())

**Training Data Reuse (Training.php):**
```php
getTrainingEffectiveness(): ?float     // Zeile 282 - Korreliert Training mit Control-Implementierung
addressesCriticalControls(): bool      // Zeile 316 - Verknüpft Training mit kritischen Controls
```

#### **4.9 ISMS Context & Objectives**

**Zweck:** Organisationskontext und strategische ISMS-Ziele

**Entity:** `src/Entity/ISMSContext.php`, `src/Entity/ISMSObjective.php`
**Controller:** `src/Controller/ContextController.php`, `src/Controller/ISMSObjectiveController.php`

**Funktionalität:**
- Definition des ISMS-Geltungsbereichs (Scope)
- Verwaltung interessierter Parteien (Stakeholders)
- Dokumentation gesetzlicher und regulatorischer Anforderungen
- ISMS-Ziele mit messbaren Indikatoren (Target Value, Current Value)
- Fortschrittstracking mit Status (Not Started, In Progress, Completed, Cancelled)
- Verantwortlichkeiten und Deadlines

**Datenwiederverwendung:**
- Gesetzliche Anforderungen werden mit Compliance-Frameworks verknüpft
- ISMS-Ziele können an KPIs aus anderen Modulen gekoppelt werden
- Zielerreichung wird im Management Review Dashboard dargestellt

---

### Compliance & Multi-Framework

#### **4.10 Multi-Framework Compliance Management**

**Zweck:** Parallele Verwaltung mehrerer Compliance-Frameworks

**Entities:** `src/Entity/ComplianceFramework.php`, `src/Entity/ComplianceRequirement.php`, `src/Entity/ComplianceMapping.php`
**Controller:** `src/Controller/ComplianceController.php`
**Services:** `src/Service/ComplianceAssessmentService.php`, `src/Service/ComplianceMappingService.php`

**Unterstützte Frameworks:**
- ISO 27001:2022 (93 Annex A Controls)
- TISAX (VDA ISA) für die Automobilindustrie
- EU-DORA für Finanzdienstleister
- Erweiterbar für weitere Frameworks

**Funktionalität:**
- Hierarchische Compliance-Anforderungen (Hauptanforderungen mit Detail-Anforderungen)
- Cross-Framework-Mappings zeigen Überschneidungen zwischen Frameworks
- Mapping-Typen mit Prozentangaben:
  - **Weak** (<25%): Anforderungen überschneiden sich minimal
  - **Partial** (25-75%): Anforderungen überschneiden sich teilweise
  - **Full** (75-99%): Anforderungen sind weitgehend identisch
  - **Exceeds** (≥100%): Eine Anforderung erfüllt die andere vollständig und geht darüber hinaus
- Transitive Compliance-Berechnung: Erfüllung einer Anforderung trägt automatisch zu gemappten Anforderungen bei

**Datenwiederverwendung:**
- ISO 27001-Controls werden auf TISAX- und DORA-Anforderungen gemappt
- Compliance-Fulfillment wird aus folgenden Quellen berechnet (ComplianceMappingService):
  - **Control-Implementierungsstatus** (gemappte Controls via `analyzeControlsContribution()`)
  - **Asset-Inventar** (für Asset-Management-Anforderungen via `analyzeAssetsContribution()`)
  - **BCM-Daten** (für Resilienz-Anforderungen via `analyzeBCMContribution()`)
  - **Incident-Management-Nachweise** (via `analyzeIncidentContribution()`)
  - **Audit-Ergebnisse** (via `analyzeAuditContribution()`)
- Gap-Analysen identifizieren fehlende Nachweise framework-übergreifend

**Compliance Assessment Service:**
```php
// ComplianceAssessmentService.php
public function assessFramework(ComplianceFramework $framework): array
{
    // Berechnet Compliance-Status über alle Anforderungen
    // Zeile 24-50
}

public function getComplianceDashboard(): array
{
    // Dashboard mit total_hours_saved (Data Reuse Value)
    // Zeile 173-225
}

public function compareFrameworks(array $frameworks): array
{
    // Cross-Framework-Vergleich und Overlap-Analyse
    // Zeile 276-319
}
```

**Compliance Mapping Service:**
```php
// ComplianceMappingService.php
public function getDataReuseAnalysis(ComplianceRequirement $requirement): array
{
    // Analysiert: Controls, Assets, BCM, Incidents, Audits
    // Zeile 52-101
}

public function calculateDataReuseValue(ComplianceRequirement $requirement): array
{
    // $hoursPerSource = 4 (konservative Schätzung)
    // Zeile 261-287
}
```

---

### Security & Infrastructure

#### **4.11 User Management & Authentication**

**Zweck:** Verwaltung von Benutzern und Zugriffskontrolle

**Entity:** `src/Entity/User.php`
**Controller:** `src/Controller/SecurityController.php`, `src/Controller/UserManagementController.php`

**Funktionalität:**
- Benutzerverwaltung mit vollständigen Profilen (Name, E-Mail, Abteilung, Jobtitel, Telefon)
- Multiple Authentifizierungsmethoden:
  - Lokale Authentifizierung (Username/Password mit bcrypt/argon2)
  - Azure AD OAuth 2.0
  - Azure AD SAML
- Benutzerstatus-Verwaltung (aktiv/inaktiv, verifiziert, gesperrt)
- Last-Login-Tracking und Session-Management
- Mehrsprachige Benutzeroberfläche (Deutsch/Englisch, via Locale-Präferenz)
- Zeitzonenverwaltung für korrekte Zeitstempel
- Profilbild-Upload
- Two-Factor Authentication (2FA) Support

**Datenwiederverwendung:**
- Benutzeraktionen werden im Audit-Log erfasst (AuditLogger)
- Benutzer-Abteilungen können mit ISMS-Kontext verknüpft werden
- User-Activity-Reports im AuditLog-Dashboard

#### **4.12 Role & Permission Management**

**Zweck:** Rollenbasierte Zugriffskontrolle (RBAC) für granulare Berechtigungen

**Entities:** `src/Entity/Role.php`, `src/Entity/Permission.php`
**Controller:** `src/Controller/RoleManagementController.php`

**Funktionalität:**
- Hierarchische Rollenverwaltung mit Vererbung:
  - **ROLE_USER**: Basis-Zugriff (Read-Only für eigene Daten)
  - **ROLE_AUDITOR**: Erweitert User, Audit-Durchführung, Read-Only für alle Daten
  - **ROLE_MANAGER**: Erweitert Auditor, Risiko-/Control-Management, Genehmigungen
  - **ROLE_ADMIN**: Erweitert Manager, User-/Role-Management, System-Konfiguration
  - **ROLE_SUPER_ADMIN**: Vollzugriff, Tenant-Management, Deployment-Wizard
- Granulare Permissions auf Entity-Ebene:
  - **view**: Lesen von Entitäten
  - **create**: Erstellen neuer Entitäten
  - **edit**: Bearbeiten bestehender Entitäten
  - **delete**: Löschen von Entitäten
- Custom Roles mit individuellen Permission-Sets
- Permission-Vererbung über Rollenhierarchie
- Automatische Permission-Prüfung via Symfony Voters

**Datenwiederverwendung:**
- Rollen-Änderungen werden im Audit-Log dokumentiert
- Permissions bestimmen automatisch verfügbare Module im Dashboard
- Role-Based Filtering in allen Listenansichten

#### **4.13 Audit Logging**

**Zweck:** Vollständige Nachvollziehbarkeit aller Systemaktionen

**Entity:** `src/Entity/AuditLog.php`
**Controller:** `src/Controller/AuditLogController.php`
**Service:** `src/Service/AuditLogger.php`

**Funktionalität:**
- Automatisches Logging aller Entity-Änderungen via Doctrine Lifecycle Events:
  - **CREATE**: Neue Entität erstellt
  - **UPDATE**: Entität geändert
  - **DELETE**: Entität gelöscht
- Erfassung von Vorher-/Nachher-Werten (Old Values / New Values) als JSON
- Benutzer-, IP-Adressen- und User-Agent-Tracking
- Filterbare Audit-Trails:
  - Nach Entity-Type (z.B. nur Risk, Control, Asset)
  - Nach User (alle Aktionen eines Benutzers)
  - Nach Action (CREATE, UPDATE, DELETE)
  - Nach Zeitraum (von/bis Datum)
- **Entity-History**: Vollständiger Änderungsverlauf pro Datensatz
- **User-Activity**: Übersicht aller Aktionen eines Benutzers
- **Statistiken**: Aktionen pro Tag/Woche/Monat mit Charts

**Datenwiederverwendung:**
- Audit-Logs dienen als Nachweis für ISO 27001 Clause 9.3 (Management Review)
- Änderungshistorie unterstützt Incident-Investigations (Wer hat wann was geändert?)
- User-Activity hilft bei der Identifikation von Schulungsbedarf
- Compliance-Anforderungen für Audit-Trails werden automatisch erfüllt

**Audit Logger Service:**
```php
// AuditLogger.php
public function logCreate(object $entity, User $user, array $context = []): void
{
    // Logged automatisch bei postPersist Event
    // Zeile 30-50
}

public function logUpdate(object $entity, User $user, array $oldValues, array $newValues): void
{
    // Logged automatisch bei postUpdate Event mit Diff
    // Zeile 52-80
}

public function logDelete(object $entity, User $user): void
{
    // Logged automatisch bei preRemove Event
    // Zeile 82-100
}
```

#### **4.14 Document Management**

**Zweck:** Zentrale Verwaltung von ISMS-Dokumenten

**Entity:** `src/Entity/Document.php`
**Controller:** `src/Controller/DocumentController.php`

**Funktionalität:**
- Dokumenten-Upload mit Versionierung (Version 1.0, 1.1, 2.0, etc.)
- Dokumenten-Typen: Policy, Procedure, Guideline, Record, Evidence, Report
- Verknüpfung mit Controls, Risiken, Audits, Training
- Dokumenten-Status: Draft, Under Review, Approved, Archived
- Genehmigungsworkflow mit Reviewer und Approver
- Review-Zyklen und automatische Fälligkeitsbenachrichtigungen
- Tag-System für bessere Auffindbarkeit
- Volltext-Suche in Dokumenten-Metadaten

**Datenwiederverwendung:**
- Dokumente dienen als Evidence für Compliance-Anforderungen
- Dokumenten-Genehmigungsstatus fließt in Control-Implementierung ein
- Review-Fälligkeiten werden im Dashboard angezeigt

#### **4.15 Workflow Engine**

**Zweck:** Automatisierte Genehmigungs- und Eskalationsprozesse

**Entities:** `src/Entity/Workflow.php`, `src/Entity/WorkflowInstance.php`, `src/Entity/WorkflowStep.php`
**Controller:** `src/Controller/WorkflowController.php`
**Service:** `src/Service/WorkflowService.php`

**Funktionalität:**
- Definition wiederverwendbarer Workflows mit Steps:
  - **Task**: Manuelle Aufgabe (z.B. "Review durchführen")
  - **Approval**: Genehmigungsschritt (Approve/Reject)
  - **Notification**: E-Mail-Benachrichtigung
  - **Decision**: Bedingte Verzweigung
- Workflow-Auslöser: Manuell, Zeitgesteuert, Event-basiert
- Workflow-Instanzen für konkrete Durchläufe
- Schritt-Status: Pending, In Progress, Completed, Skipped
- Automatische Eskalation bei überfälligen Steps
- Workflow-Templates für häufige Prozesse:
  - Risiko-Genehmigung (ISMS Manager → CISO)
  - Control-Review (Owner → Manager → Auditor)
  - Incident-Response (Detect → Contain → Resolve → Lessons Learned)
  - Document-Approval (Draft → Review → Approve → Publish)

**Datenwiederverwendung:**
- Workflow-Daten fließen in KPIs ein (Anzahl laufender Workflows, überfällige Tasks)
- Workflow-History dokumentiert Genehmigungsprozesse für Audits
- Eskalationen triggern automatisch Benachrichtigungen

---

### Analytics & Reporting

#### **4.16 Analytics Dashboard**

**Zweck:** Datengetriebene Insights und Visualisierungen

**Controller:** `src/Controller/AnalyticsController.php`

**Funktionalität:**
- **Risk Heat Map**: 5x5 Matrix mit Likelihood × Impact, farbcodierte Risiken
- **Compliance Radar Chart**: Multi-Framework-Vergleich (ISO 27001, TISAX, DORA)
- **Trend Charts**: Zeitliche Entwicklung von:
  - Anzahl offener Risiken (nach Behandlungsstatus)
  - Incident-Trends (nach Kategorie und Schweregrad)
  - Control-Implementierungsfortschritt
  - Compliance-Scores pro Framework
- **Asset Distribution**: Verteilung nach Typ und CIA-Level
- **Training Coverage**: Abdeckung kritischer Controls durch Schulungen
- **Workflow Metrics**: Anzahl aktiver Workflows, durchschnittliche Durchlaufzeit

**Technologie:**
- Chart.js für interaktive Diagramme
- Stimulus Controller für dynamische Updates
- AJAX-basierte Datenabfragen für Performance

#### **4.17 Report Generator**

**Zweck:** Automatisierte PDF- und Excel-Reports

**Controller:** `src/Controller/ReportController.php`
**Services:** `src/Service/PdfExportService.php`, `src/Service/ExcelExportService.php`

**Report-Typen:**
- **ISMS Dashboard Report**: KPI-Übersicht mit Charts (PDF)
- **Risk Register**: Alle Risiken mit Status, Controls, Residual Risk (PDF/Excel)
- **Statement of Applicability**: Alle 93 Controls mit Status (PDF/Excel)
- **Incident Report**: Vorfallshistorie mit Timeline (PDF)
- **Audit Report**: Audit-Ergebnisse mit Findings und Recommendations (PDF)
- **Training Report**: Schulungsübersicht mit Teilnehmern und Feedback (PDF)
- **Compliance Report**: Multi-Framework-Status mit Gap-Analyse (PDF)

**Anpassung:**
- Logo und CI-Farben konfigurierbar
- Benutzerdefinierte Filterung (Zeitraum, Status, etc.)
- Scheduling für regelmäßige Reports (via Symfony Messenger)

#### **4.18 Global Search**

**Zweck:** Übergreifende Suche über alle ISMS-Daten

**Controller:** `src/Controller/SearchController.php`

**Funktionalität:**
- Volltext-Suche über alle Entities:
  - Assets (Name, Beschreibung, Owner)
  - Risks (Beschreibung, Threat, Vulnerability)
  - Controls (Control-ID, Name, Beschreibung)
  - Incidents (Titel, Beschreibung, Root Cause)
  - Documents (Titel, Beschreibung, Tags)
- Filterung nach Entity-Typ
- Sortierung nach Relevanz (Doctrine Full-Text Search)
- Highlighting von Suchbegriffen in Ergebnissen
- Quick-View-Modal für schnelle Vorschau

---

### Deployment & Configuration

#### **4.19 Deployment Wizard**

**Zweck:** Geführte Erstinstallation und Konfiguration

**Controller:** `src/Controller/DeploymentWizardController.php`
**Service:** `src/Service/SystemRequirementsChecker.php`, `src/Service/DataImportService.php`

**Funktionalität:**
- **Schritt-für-Schritt Setup-Wizard**:
  1. **System-Requirements-Check**:
     - PHP Version (≥ 8.4)
     - Required Extensions (pdo, intl, mbstring, xml, curl, gd, zip)
     - Database Connection (PostgreSQL/MySQL)
     - File Permissions (var/, public/uploads/)
  2. **Modulauswahl**:
     - Aktiviere/Deaktiviere ISMS-Module nach Bedarf
     - Core-Module (Asset, Risk, Control) immer aktiv
     - Optional: BCM, Training, Workflow, Analytics
  3. **Datenbankinitialisierung**:
     - Migrations ausführen (doctrine:migrations:migrate)
     - Schema-Validierung (doctrine:schema:validate)
  4. **Basis-Daten laden**:
     - ISO 27001:2022 Annex A Controls (93 Controls)
     - Compliance-Frameworks (ISO 27001, TISAX, DORA)
     - Standard-Rollen und Permissions
  5. **Optional: Sample-Daten importieren**:
     - Beispiel-Assets (10 Stück)
     - Beispiel-Risiken (15 Stück)
     - Beispiel-Incidents (5 Stück)
     - Beispiel-Controls mit Implementierungsstatus
  6. **Abschluss**:
     - Konfigurationszusammenfassung
     - Erster Admin-User erstellen
     - Login-Link zum Dashboard

**Validierung:**
- Automatische Prüfung aller Voraussetzungen vor jedem Schritt
- Fehlerbehandlung mit konkreten Handlungsempfehlungen
- Progress-Bar für Installationsfortschritt

**Datenwiederverwendung:**
- Geladene ISO 27001 Controls sind sofort für SoA verfügbar
- Sample-Daten demonstrieren Data-Reuse-Architecture (Assets → Risks → Controls → Compliance)

#### **4.20 Module Management**

**Zweck:** Aktivierung/Deaktivierung von ISMS-Modulen nach Bedarf

**Controller:** `src/Controller/ModuleManagementController.php`
**Service:** `src/Service/ModuleConfigurationService.php`

**Funktionalität:**
- Übersicht aller verfügbaren Module mit Status (enabled/disabled):
  - **Core-Module**: Asset, Risk, Control, Incident, Audit (immer aktiv)
  - **Erweiterte Module**: BCM, Training, Management Review, Compliance
  - **Premium-Funktionen**: Analytics, Workflow, Document Management, Reports
- Modul-Abhängigkeiten-Graph:
  - Compliance benötigt Controls (ISO 27001 Annex A)
  - BCM benötigt Assets (für Prozess-Asset-Verknüpfungen)
  - Analytics benötigt mindestens 2 Core-Module
- Aktivierung/Deaktivierung mit automatischer Dependency-Resolution:
  - Warnung, wenn abhängige Module betroffen sind
  - Rollback bei Fehlern
- Modul-Statistiken:
  - Anzahl Datensätze pro Modul
  - Letzte Aktivität (Last Updated)
  - Beliebtheit (Most Used)
- Export/Import von Modulkonfigurationen als YAML

**Datenwiederverwendung:**
- Aktivierte Module bestimmen verfügbare Datenquellen für Compliance-Mappings
- Module-Dependencies sichern Datenintegrität über Module hinweg
- Deaktivierte Module werden in Dashboards ausgeblendet

#### **4.21 Multi-Tenancy (Optional)**

**Zweck:** Mandantenfähigkeit für SaaS-Betrieb

**Entity:** `src/Entity/Tenant.php`

**Funktionalität:**
- Mandantenverwaltung mit separaten Datenbereichen
- Tenant-Isolation auf Datenbankebene (Row-Level Security)
- Tenant-spezifische Konfiguration (Logo, Farben, Module)
- Cross-Tenant-Reports für Service-Provider
- Tenant-Admin-Rolle für Self-Service-Verwaltung

**Status:** Grundlegende Entity vorhanden, vollständige Integration in Entwicklung

---

### Email Notifications & Alerts

#### **4.22 Email Notification System**

**Service:** `src/Service/EmailNotificationService.php`

**Benachrichtigungstypen:**
- **Fälligkeiten**:
  - Control-Review fällig in 7 Tagen
  - Audit-Termin in 14 Tagen
  - Training-Teilnahme erforderlich
  - Document-Review überfällig
- **Eskalationen**:
  - Risiko seit 30 Tagen ohne Treatment
  - Incident seit 7 Tagen nicht resolved
  - Workflow-Step seit 5 Tagen pending
- **Status-Änderungen**:
  - Risiko wurde hochgestuft (High → Critical)
  - Control-Implementierung abgeschlossen
  - Audit-Finding wurde erstellt
  - Document wurde genehmigt

**Templates:**
- Mehrsprachige HTML-E-Mails (Deutsch/Englisch)
- Responsive Design für mobile Geräte
- Direkt-Links zu betroffenen Entities
- Unsubscribe-Funktionalität

---

## 5. KPI Dashboard & Metriken

### Welche KPIs werden automatisch berechnet?

**ISMS-Übersicht:**
- Anzahl verwalteter Assets (gesamt, kritisch mit CIA ≥ 4)
- Anzahl identifizierter Risiken (gesamt, High-Risks mit Level ≥ 12)
- Anzahl implementierter Controls (gesamt, Implementierungsgrad in %)
- Anzahl offener Vorfälle (gesamt, Critical-Incidents)
- Compliance-Status pro Framework (ISO 27001, TISAX, DORA in %)

**Risk Management:**
- Risk Distribution nach Treatment-Status (Accept, Mitigate, Transfer, Avoid)
- Average Residual Risk Level
- Anzahl Risks ohne zugeordnete Controls
- Anzahl realisierter Risiken (basierend auf Incidents)

**Control Implementation:**
- Anzahl applicable Controls (vs. not_applicable)
- Implementation Status Distribution (not_started, in_progress, implemented)
- Average Implementation Percentage
- Controls mit Review-Bedarf (needsReview() = true)

**Incident Management:**
- Incidents pro Kategorie (Data Breach, Malware, Phishing, etc.)
- Incidents pro Schweregrad (Low, Medium, High, Critical)
- Average Time-to-Resolution (Detected → Resolved)
- Anzahl Data Breaches (für DSGVO-Reporting)

**Training & Awareness:**
- Training-Abdeckung kritischer Controls (in %)
- Anzahl geplanter vs. durchgeführter Trainings
- Average Training-Effectiveness-Score
- Anzahl Teilnehmer gesamt

**Workflow & Tasks:**
- Anzahl aktiver Workflows
- Anzahl überfälliger Workflow-Steps
- Average Workflow Duration
- Eskalationsrate (in %)

**Data Reuse Value:**
- Geschätzte eingesparte Arbeitsstunden durch Datenwiederverwendung
- Berechnung: Anzahl Data Sources × 4 Stunden
- Data Sources: Controls, Assets, BCM, Incidents, Audits

---

## 6. Effizienzsteigerungen

### Welche Effizienzsteigerungen bietet das Tool gegenüber manueller Erfassung?

#### **6.1 Reduzierung von Dokumentationsaufwand**

**Business-Continuity-Daten**
- **Manuell**: RTO/RPO-Daten in separaten BCM-Dokumenten erfassen, dann Asset-Schutzanforderungen manuell ableiten und übertragen
- **Mit Tool**: RTO/RPO einmal erfassen, Software schlägt automatisch Verfügbarkeitsanforderungen vor (RTO ≤ 1h → Availability 5)
- **Zeitersparnis**: ~2-3 Stunden pro Geschäftsprozess (initial), ~1 Stunde pro Änderung

**Compliance-Nachweise**
- **Manuell**: Für jedes Framework (ISO 27001, TISAX, DORA) separat dokumentieren, welche Anforderungen durch welche Maßnahmen erfüllt werden
- **Mit Tool**: Control-Implementierung einmal erfassen, Cross-Framework-Mappings zeigen automatisch transitive Compliance
- **Zeitersparnis**: ~4 Stunden pro Compliance-Anforderung bei Multi-Framework-Nachweisen

**Risikobewertungen**
- **Manuell**: Likelihood und Impact schätzen, bei Vorfällen manuell abgleichen und Risikobewertung anpassen
- **Mit Tool**: Vorfälle mit Risiken verknüpfen, System zeigt automatisch Validierung und schlägt Anpassungen vor
- **Zeitersparnis**: ~1-2 Stunden pro Risiko bei Review-Zyklen

#### **6.2 Vermeidung von Inkonsistenzen**

**Szenario: Änderung eines Control-Status**
- **Manuell**: Control-Status in mehreren Dokumenten aktualisieren (SoA, Risiko-Behandlungsplan, Compliance-Nachweisdokumente)
- **Mit Tool**: Control-Status einmal ändern, alle abhängigen Berechnungen (Restrisiken, Compliance-Fulfillment) automatisch aktualisiert
- **Zeitersparnis**: ~30-60 Minuten pro Control-Änderung

**Szenario: Neuer Sicherheitsvorfall**
- **Manuell**: Vorfall dokumentieren, dann manuell prüfen, welche Risikobewertungen angepasst werden sollten
- **Mit Tool**: Vorfall mit Assets und Risiken verknüpfen, System schlägt automatisch Risikobewertungs-Updates vor
- **Zeitersparnis**: ~1-2 Stunden pro Vorfall bei Nachbearbeitung

#### **6.3 Beschleunigte Audit-Vorbereitung**

**Audit-Checklisten**
- **Manuell**: Checklisten manuell aus Compliance-Anforderungen erstellen, Nachweise aus verschiedenen Dokumenten zusammenstellen
- **Mit Tool**: Checklisten automatisch generiert, verknüpfte Controls, Assets und Nachweise direkt angezeigt
- **Zeitersparnis**: ~4-8 Stunden pro Audit-Vorbereitung

**Management-Reviews**
- **Manuell**: KPIs aus verschiedenen Quellen manuell zusammentragen (Excel-Listen, Dokumente)
- **Mit Tool**: KPIs automatisch berechnet und im Dashboard angezeigt
- **Zeitersparnis**: ~2-4 Stunden pro Management-Review

#### **6.4 Gap-Analysen**

**Multi-Framework-Gap-Analysen**
- **Manuell**: Pro Framework manuell ermitteln, welche Anforderungen noch nicht erfüllt sind
- **Mit Tool**: System berechnet automatisch Compliance-Status für alle Frameworks mit priorisierten Lücken
- **Zeitersparnis**: ~8-16 Stunden pro Framework (initial), ~2-4 Stunden bei Updates

#### **6.5 Workflow-Automatisierung**

**Genehmigungsprozesse**
- **Manuell**: E-Mails für Genehmigungen versenden, Antworten tracken, Eskalationen manuell nachhalten
- **Mit Tool**: Workflows mit automatischen Benachrichtigungen und Eskalationen
- **Zeitersparnis**: ~1-2 Stunden pro Genehmigungsprozess

#### **6.6 Quantifizierung der Gesamteffizienzsteigerung**

Die Software berechnet selbst einen **Data Reuse Value**, der geschätzte eingesparte Arbeitsstunden durch Datenwiederverwendung anzeigt.

**Berechnungsbasis (ComplianceMappingService.php, Zeile 267):**
```php
$hoursPerSource = 4; // Average time to gather evidence from scratch
```

**Beispielrechnung für ein mittelständisches Unternehmen:**

Bei typischer Nutzung mit:
- 100 Assets
- 93 ISO 27001 Controls
- 150 TISAX-Anforderungen
- 80 DORA-Anforderungen
- 40 Geschäftsprozesse (BCM)
- 30 Vorfälle pro Jahr
- 4 interne Audits pro Jahr
- 50 Workflows pro Jahr

**Geschätzte Zeitersparnis im ersten Jahr:**
- Control-Mappings: 150 TISAX + 80 DORA × 4h = **920 Stunden**
- BCM → Asset-Protection: 40 Prozesse × 2h = **80 Stunden**
- Incident → Risk-Updates: 30 Vorfälle × 1,5h = **45 Stunden**
- Audit-Vorbereitung: 4 Audits × 6h = **24 Stunden**
- Management-Reviews: 4 Reviews × 3h = **12 Stunden**
- Workflow-Automatisierung: 50 Workflows × 1,5h = **75 Stunden**

**Gesamtersparnis im ersten Jahr: ~1.150 Arbeitsstunden**

**Jährliche Ersparnis im laufenden Betrieb:**
- Vermiedene Doppelerfassung: ~200 Stunden
- Audit-Zyklen: ~50 Stunden
- Management-Reviews: ~12 Stunden
- Workflow-Automatisierung: ~75 Stunden

**Jährliche Ersparnis ab Jahr 2: ~350 Arbeitsstunden**

#### **6.7 Qualitative Vorteile**

Neben der quantifizierbaren Zeitersparnis bietet das Tool folgende qualitative Verbesserungen:

- **Aktualität**: Echtzeit-Überblick über ISMS-Status statt veralteter Excel-Listen
- **Nachvollziehbarkeit**: Zusammenhänge zwischen Anforderungen und Nachweisen sind dokumentiert (Audit-Trails)
- **Risikovalidierung**: Risikobewertungen werden durch tatsächliche Vorfälle validiert
- **Konsistenz**: Inkonsistenzen zwischen verschiedenen Dokumenten werden vermieden
- **Audit-Sicherheit**: Vollständige Audit-Trails durch AuditLog-System
- **Transparenz**: Dashboard mit KPIs zeigt ISMS-Status auf einen Blick
- **Collaboration**: Workflow-System ermöglicht strukturierte Zusammenarbeit
- **Compliance**: Multi-Framework-Support reduziert Compliance-Aufwand erheblich

---

## 7. Zusammenfassung

Der **Little ISMS Helper** adressiert die Herausforderung der Mehrfacherfassung und isolierten Datenhaltung bei ISMS-Dokumentation. Durch systematische Verknüpfungen zwischen Modulen, intelligente Datenwiederverwendung und automatisierte Workflows reduziert die Software den Dokumentationsaufwand erheblich, ohne dabei Kompromisse bei der Vollständigkeit der Dokumentation einzugehen.

### Kernmerkmale

✅ **Data Reuse Architecture** - 4 Core Services für intelligente Datenwiederverwendung
✅ **23 Entities** - Vollständiges ISMS-Datenmodell mit 5 Compliance-Frameworks
✅ **13 Services** - Geschäftslogik für Compliance, Risk Intelligence, Protection Requirements
✅ **24 Controller** - Komplette Web-UI für alle ISMS-Prozesse
✅ **Multi-Framework Support** - ISO 27001, TISAX, DORA mit Cross-Framework-Mappings
✅ **Workflow Engine** - Automatisierte Genehmigungs- und Eskalationsprozesse
✅ **Analytics Dashboard** - Risk Heat Maps, Compliance Radar, Trend Charts
✅ **Audit Logging** - Vollständige Nachvollziehbarkeit aller Systemaktionen
✅ **RBAC** - Hierarchische Rollen mit granularen Permissions
✅ **Report Generator** - PDF/Excel-Export für alle ISMS-Reports

### Zielgruppe

Die Lösung ist primär für **kleine und mittelständische Unternehmen** konzipiert, die:
- Mehrere Compliance-Frameworks parallel erfüllen müssen (ISO 27001 + TISAX, oder ISO 27001 + DORA)
- Von der automatischen Wiederverwendung von Nachweisen profitieren möchten
- Ihre ISMS-Prozesse standardisieren und automatisieren wollen
- Transparenz über den aktuellen ISMS-Status benötigen
- Audit-Sicherheit durch vollständige Dokumentation erreichen wollen

### Technologie

- **Modern**: PHP 8.4, Symfony 7.3, PostgreSQL/MySQL
- **Getestet**: 122+ PHPUnit-Tests für Core-Funktionalität
- **Sicher**: RBAC, Audit Logging, CSRF-Protection, Input Validation
- **Skalierbar**: Multi-Tenancy-fähig für SaaS-Betrieb
- **Integrierbar**: REST API (in Vorbereitung) für Integrationen

### Geschätzte ROI

Bei einem mittelständischen Unternehmen mit typischer ISMS-Nutzung:
- **Jahr 1**: ~1.150 eingesparte Arbeitsstunden
- **Ab Jahr 2**: ~350 eingesparte Arbeitsstunden pro Jahr
- **Qualitativ**: Höhere Audit-Sicherheit, bessere Compliance, mehr Transparenz

---

**Version:** 1.0
**Stand:** 2025-11-07
**Geprüft gegen:** claude/cleanup-git-repo-011CUtNvJ1WfHESJXV4Dmhbe Branch
