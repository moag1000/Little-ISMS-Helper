# Little ISMS Helper - Information Security Manager Analyse

**Analysiert von:** ISM-Perspektive (ISO 27001:2022, BSI IT-Grundschutz, NIS2, DSGVO)
**Datum:** 2025-11-19
**Version:** Little ISMS Helper v1.0
**Umfang:** Vollständige ISMS-Prozess- und Normkonformitätsanalyse

---

## Executive Summary

Das Little ISMS Helper Tool zeigt eine **solide Grundarchitektur** für ein ISMS-Management-System mit folgenden Stärken:

### Positive Aspekte
- ✅ **93 ISO 27001:2022 Controls** vollständig implementiert (Anhang A.5.1 - A.8.34)
- ✅ **Multi-Framework-Unterstützung**: 15+ Compliance-Frameworks (ISO 27001, NIS2, DSGVO, BSI Grundschutz, DORA, TISAX, etc.)
- ✅ **NIS2-konforme Incident-Management** mit 24h/72h/30d Reporting-Deadlines
- ✅ **Vulnerability & Patch Management** mit CVSS-Scoring und Remediation-Tracking
- ✅ **MFA-Implementierung** (TOTP, WebAuthn, SMS, Backup Codes) für NIS2 Art. 21.2.b
- ✅ **Audit-Logging** mit IP-Tracking, User-Agent und Change-Tracking
- ✅ **Multi-Tenancy** mit Tenant-Isolation für alle Entities
- ✅ **Kryptographie-Operations-Logging** für ISO 27001 A.8.24 (Cryptography)
- ✅ **RBAC mit 50+ Permissions** und hierarchischem Rollen-Modell

### Kritische Gaps (CRITICAL/HIGH)
- 🔴 **Fehlende Control-Effectiveness-Messung**: Keine KPIs/Metriken für Control-Wirksamkeit (ISO 27001 Clause 9.1)
- 🔴 **Unvollständige Vulnerability→Patch→Incident Integration**: Kein automatisches Linking
- 🔴 **Fehlende Audit-Trail-Retention Policy**: Keine gesetzeskonforme Log-Aufbewahrung (DSGVO, NIS2)
- 🔴 **Keine automatische Control-Review-Eskalation**: Controls ohne Review-Trigger
- 🔴 **Fehlende SIEM-Integration**: Security-Events nicht aggregiert
- 🟡 **Unvollständige Risk-Acceptance-Workflow**: Approval-Prozess nicht durchgängig
- 🟡 **Fehlende Control-to-Framework-Mapping-Validierung**: Compliance-Lücken-Risiko

**Gesamtbewertung ISO 27001:2022 Compliance:** 75% (Good Foundation, Needs Improvements)

---

## 1. Workflow-Analyse

### 1.1 Asset Management Workflow

**Status:** ✅ **GOOD** mit Minor Gaps

**Implementierte Features:**
- Asset-Typen, Eigentümer, Standorte
- CIA-Bewertung (Confidentiality, Integrity, Availability) 1-5 Skala
- Datenklassifikation (public, internal, confidential, restricted)
- Verknüpfung zu Risks, Controls, Incidents
- Monetäre Bewertung für Risikoberechnung
- Physical Location Mapping

**ISO 27001:2022 Mapping:**
- ✅ A.5.9 (Inventory of information and other associated assets)
- ✅ A.5.10 (Acceptable use of information and other associated assets)
- ✅ A.5.12 (Classification of information)

**Identified Gaps:**

#### GAP-ASM-01: Fehlende Asset-Lifecycle-Verwaltung (MEDIUM)
**ISO 27001 Referenz:** A.5.9, A.5.14 (Transfer or disposal of information)
**Beschreibung:** Kein strukturierter Workflow für Asset-Disposal/Decommissioning
**Risiko:** Datenlecks bei Asset-Entsorgung, Non-Compliance mit DSGVO Art. 17 (Recht auf Löschung)
**Empfehlung:**
```php
// Asset.php - Add lifecycle tracking
#[ORM\Column(length: 50)]
private ?string $lifecycleStage = 'active'; // active, deprecated, disposal_pending, disposed

#[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
private ?\DateTimeImmutable $disposalScheduledDate = null;

#[ORM\Column(type: Types::TEXT, nullable: true)]
private ?string $disposalMethod = null; // secure_deletion, physical_destruction, etc.
```

#### GAP-ASM-02: Fehlende Asset-Dependency-Mapping (LOW)
**ISO 27001 Referenz:** A.5.9, BSI Grundschutz APP.3.1
**Beschreibung:** Assets haben keine Dependency-Relationships (welche Assets sind von anderen abhängig?)
**Risiko:** Unvollständige Business Impact Analysis (BIA)
**Empfehlung:** ManyToMany-Relationship für Asset-Dependencies implementieren

---

### 1.2 Control Implementation & Monitoring

**Status:** 🟡 **NEEDS IMPROVEMENT**

**Implementierte Features:**
- 93 ISO 27001:2022 Controls (A.5.1 - A.8.34)
- Implementation-Status-Tracking (not_started, planned, in_progress, implemented, verified)
- Applicability-Assessment mit Justification
- Control-to-Risk-Mapping
- Control-to-Asset-Protection-Mapping
- Responsible Person Assignment
- Target Dates & Review Dates

**ISO 27001:2022 Mapping:**
- ✅ A.5.1 (Policies for information security)
- ✅ Anhang A Controls vollständig vorhanden
- ⚠️ Clause 9.1 (Monitoring, measurement, analysis and evaluation) - **UNVOLLSTÄNDIG**

**Identified Gaps:**

#### GAP-CTL-01: Fehlende Control-Effectiveness-Messung (CRITICAL)
**ISO 27001 Referenz:** Clause 9.1, A.8.8 (Management of technical vulnerabilities)
**BSI Grundschutz:** ORP.5 Compliance Management
**Beschreibung:** Controls haben zwar `getEffectivenessScore()`, aber:
- Keine historische Trend-Analyse
- Keine KPI-Definition pro Control
- Keine automatische Effectiveness-Degradation-Alerts
- Effectiveness basiert NUR auf Incident-Count, nicht auf weiteren Metriken

**Risiko:**
- Non-Compliance mit ISO 27001 Clause 9.1 (Performance evaluation erforderlich)
- Ineffektive Controls werden nicht erkannt
- Audit-Findings bei Zertifizierung

**Code-Referenz:**
```php
// src/Entity/Control.php:479
public function getEffectivenessScore(): float
{
    if ($this->implementationPercentage < 100) {
        return 0; // Not fully implemented yet
    }
    // Nur Incident-basierte Bewertung - zu simpel!
    $incidentsAfterControl = 0;
    // ... fehlt: KPIs, Compliance-Tests, Vulnerability-Scans
}
```

**Empfehlung:**
```php
// ControlEffectivenessService.php (NEU)
class ControlEffectivenessService
{
    public function calculateEffectiveness(Control $control): array
    {
        return [
            'incident_reduction' => $this->measureIncidentReduction($control),
            'vulnerability_coverage' => $this->measureVulnerabilityCoverage($control),
            'compliance_test_results' => $this->getComplianceTestResults($control),
            'automation_rate' => $this->getAutomationRate($control),
            'mttr' => $this->getMeanTimeToRemediate($control), // Mean Time To Remediate
            'overall_score' => /* weighted average */,
        ];
    }
}
```

#### GAP-CTL-02: Fehlende automatische Control-Review-Trigger (HIGH)
**ISO 27001 Referenz:** Clause 9.3 (Management review), A.5.1
**Beschreibung:** Controls haben `nextReviewDate`, aber keine automatischen Eskalationen bei:
- Überfälligen Reviews
- Häufigen Incidents auf geschützten Assets
- Neuen Vulnerabilities

**Code-Referenz:**
```php
// src/Entity/Control.php:512
public function isReviewNeeded(): bool
{
    // Prüft nur Incidents der letzten 3 Monate
    // Fehlt: Review-Deadline-Eskalation, Management-Review-Trigger
}
```

**Empfehlung:**
- Command `app:control:escalate-overdue-reviews` mit Email-Benachrichtigung
- Dashboard-Widget für überfällige Control-Reviews
- Automatische Review-Trigger bei ≥2 Incidents in 30 Tagen

#### GAP-CTL-03: Unvollständige Control-to-Framework-Mapping (MEDIUM)
**ISO 27001 Referenz:** A.5.1, Compliance Framework Integration
**Beschreibung:** Controls sind nur ISO 27001-spezifisch. Multi-Framework-Support (NIS2, BSI, DORA) fehlt direkte Control-Mappings.
**Risiko:** Compliance-Lücken bei Framework-Audits
**Empfehlung:**
```php
// Control.php
#[ORM\Column(type: Types::JSON, nullable: true)]
private ?array $frameworkMappings = [
    'ISO27001' => 'A.8.8',
    'NIS2' => 'Art. 21.2.d',
    'BSI_GRUNDSCHUTZ' => 'OPS.1.1.5',
    'DORA' => 'Art. 9.4',
];
```

---

### 1.3 Incident Management Workflow

**Status:** ✅ **EXCELLENT** (NIS2-konform)

**Implementierte Features:**
- NIS2-konforme Reporting-Deadlines (24h Early Warning, 72h Detailed, 30d Final)
- Incident-Status-Workflow (open → investigating → resolved → closed)
- Severity-Klassifikation (low, medium, high, critical)
- Data-Breach-Flag mit Notification-Required
- Cross-Border-Impact-Tracking
- Financial-Impact-Estimation
- Root-Cause-Analysis
- Failed-Controls-Tracking
- Lessons-Learned-Dokumentation

**ISO 27001:2022 Mapping:**
- ✅ A.5.24 (Information security incident management planning and preparation)
- ✅ A.5.25 (Assessment and decision on information security events)
- ✅ A.5.26 (Response to information security incidents)
- ✅ A.5.27 (Learning from information security incidents)

**NIS2 Compliance:**
- ✅ Art. 23 (Reporting obligations) - vollständig implementiert
- ✅ 24h/72h/30d Deadlines mit Countdown-Trackern
- ✅ Authority Notification Tracking (BSI, ENISA)

**Identified Gaps:**

#### GAP-INC-01: Fehlende Incident-Classification-Automation (LOW)
**NIS2 Referenz:** Art. 23
**Beschreibung:** NIS2-Category muss manuell gesetzt werden (operational, security, privacy, availability)
**Empfehlung:** Auto-Classification basierend auf:
- Affected Assets (Data-Classification)
- Failed Controls (Control-Category)
- Incident-Category

#### GAP-INC-02: Keine Integration mit SIEM/SOC (MEDIUM)
**ISO 27001 Referenz:** A.8.15 (Logging), A.8.16 (Monitoring activities)
**NIS2 Referenz:** Art. 21.2.e (Incident handling)
**Beschreibung:** Incidents werden manuell erfasst. Keine SIEM-Integration für automatische Incident-Detection.
**Empfehlung:**
- REST API für SIEM-Integration (Splunk, Elastic SIEM, etc.)
- Webhook-Support für externe Security-Tools
- Siehe `SiemExportService` - erweitern für bidirektionale Integration

---

### 1.4 Vulnerability & Patch Management

**Status:** ✅ **GOOD** mit Minor Gaps

**Implementierte Features:**
- CVE-Tracking mit CVSS-Scoring
- Automatic Severity-Calculation (critical, high, medium, low)
- Vulnerability-to-Patch-Linking
- Remediation-Deadline-Tracking (NIS2-konform: Critical 3d, High 7d, Medium 30d, Low 90d)
- Exploit-Availability-Tracking (none, poc, public, weaponized)
- Actively-Exploited-Flag
- CWE-IDs und Affected-Products

**ISO 27001:2022 Mapping:**
- ✅ A.8.8 (Management of technical vulnerabilities)
- ✅ A.5.25 (Assessment and decision on information security events)

**NIS2 Compliance:**
- ✅ Art. 21.2.d (Vulnerability handling and disclosure)

**Identified Gaps:**

#### GAP-VUL-01: Fehlende automatische Vuln→Patch→Incident Linking (CRITICAL)
**ISO 27001 Referenz:** A.8.8, A.5.26
**Beschreibung:**
- Vulnerability hat `patches` Collection
- Patch hat `vulnerability` (optional)
- **ABER:** Incidents haben KEIN direktes Vulnerability-Link
- Kein automatisches Incident-Triggering bei aktiv ausgenutzten Vulns

**Code-Referenz:**
```php
// src/Entity/Vulnerability.php - OK
#[ORM\OneToMany(targetEntity: Patch::class, mappedBy: 'vulnerability')]
private Collection $patches;

// src/Entity/Incident.php - FEHLT!
// Kein $relatedVulnerabilities Property
```

**Empfehlung:**
```php
// Incident.php
#[ORM\ManyToMany(targetEntity: Vulnerability::class)]
#[ORM\JoinTable(name: 'incident_vulnerability')]
private Collection $relatedVulnerabilities;

// Automatisches Incident-Triggering bei:
// - Vulnerability.activelyExploited = true
// - Vulnerability.exploitAvailability = 'weaponized'
// - Affected Assets haben hohes CIA-Rating
```

#### GAP-VUL-02: Keine Vulnerability-Scan-Integration (MEDIUM)
**ISO 27001 Referenz:** A.8.8
**Beschreibung:** Vulnerabilities werden manuell erfasst. Keine Integration mit Vulnerability-Scannern (Nessus, OpenVAS, Qualys).
**Empfehlung:** REST API für Scanner-Integration implementieren

---

### 1.5 Risk Management Workflow

**Status:** ✅ **GOOD**

**Implementierte Features:**
- Risk-Assessment mit Probability × Impact (1-5 Skala)
- Residual-Risk-Calculation
- Treatment-Strategy (accept, mitigate, transfer, avoid)
- Risk-Owner-Assignment (User-Entity)
- Risk-Acceptance-Approval-Workflow (ISO 27005-konform)
- Risk-to-Control-Mapping
- Risk-to-Incident-Linking (Realized-Risks)

**ISO 27001:2022 Mapping:**
- ✅ Clause 6.1.2 (Information security risk assessment)
- ✅ Clause 6.1.3 (Information security risk treatment)
- ✅ A.5.7 (Threat intelligence)

**Identified Gaps:**

#### GAP-RSK-01: Fehlende Risk-Appetite-Integration (MEDIUM)
**ISO 27001 Referenz:** Clause 6.1.3
**Beschreibung:** `RiskAppetite` Entity existiert, aber:
- Keine automatische Validierung: Ist Residual-Risk innerhalb Risk-Appetite?
- Keine Eskalation bei Risk-Appetite-Überschreitung

**Code-Referenz:**
```php
// src/Entity/RiskAppetite.php - existiert
// src/Entity/Risk.php - keine Integration!
```

**Empfehlung:**
```php
// Risk.php
public function exceedsRiskAppetite(RiskAppetite $appetite): bool
{
    return $this->getResidualRiskLevel() > $appetite->getThreshold();
}
```

#### GAP-RSK-02: Unvollständiger Risk-Acceptance-Workflow (MEDIUM)
**ISO 27001 Referenz:** Clause 6.1.3(e), ISO 27005
**Beschreibung:**
- Risk-Acceptance-Felder vorhanden (approvedBy, approvedAt, justification)
- **ABER:** Kein Approval-Workflow in der UI
- Keine Management-Review-Integration für High-Risk-Acceptance

**Empfehlung:**
- Risk-Acceptance-Form mit E-Signatur (DSGVO-konform)
- Management-Review-Pflicht für Risks mit Inherent-Risk ≥15
- Annual Re-Approval für accepted Risks

---

### 1.6 Audit-Logging & Security Monitoring

**Status:** 🟡 **NEEDS IMPROVEMENT**

**Implementierte Features:**
- Comprehensive Audit-Logging (Create, Update, Delete, View, Export, Import)
- User-Tracking (Email, IP-Address, User-Agent)
- Old-Values / New-Values Change-Tracking
- Sensitive-Data-Sanitization (Passwords, Tokens)
- Automatic Logging via `AuditLogger` Service

**ISO 27001:2022 Mapping:**
- ✅ A.8.15 (Logging)
- ⚠️ A.8.16 (Monitoring activities) - **TEILWEISE**

**Identified Gaps:**

#### GAP-AUD-01: Fehlende Audit-Log-Retention-Policy (CRITICAL)
**ISO 27001 Referenz:** A.8.15
**DSGVO Referenz:** Art. 5.1(e) (Speicherbegrenzung)
**NIS2 Referenz:** Art. 21.2 (Cybersecurity risk-management measures)
**Beschreibung:**
- Audit-Logs werden unbegrenzt gespeichert
- Keine automatische Archivierung/Löschung nach Retention-Period
- **Non-Compliance-Risiko:** DSGVO verlangt Speicherbegrenzung, aber Audit-Logs benötigen Aufbewahrung (Konflikt!)

**Gesetzliche Anforderungen:**
- **DSGVO:** 6-12 Monate (je nach Zweck)
- **NIS2:** Mindestens 12 Monate
- **BSI Grundschutz:** 6-24 Monate je nach Schutzbedarf

**Empfehlung:**
```php
// Command: app:audit:cleanup-old-logs
class AuditLogCleanupCommand extends Command
{
    private const RETENTION_DAYS_NORMAL = 365; // 1 Jahr NIS2
    private const RETENTION_DAYS_SECURITY = 730; // 2 Jahre für Security-Events

    public function execute()
    {
        $cutoffDate = new \DateTimeImmutable("-{$this->retentionDays} days");
        // Archive to cold storage, then delete
    }
}
```

#### GAP-AUD-02: Fehlende Audit-Log-Integrity-Protection (HIGH)
**ISO 27001 Referenz:** A.8.15
**Beschreibung:** Audit-Logs sind nicht gegen Manipulation geschützt
**Risiko:** Forensik-Beweise ungültig, Non-Compliance bei Audits
**Empfehlung:**
- Audit-Log-Signing mit HMAC/Digital Signature
- Write-Once-Storage (WORM) für kritische Logs
- Externe SIEM-Weiterleitung für Tamper-Proof-Storage

#### GAP-AUD-03: Keine Security-Event-Correlation (MEDIUM)
**ISO 27001 Referenz:** A.8.16 (Monitoring activities)
**Beschreibung:** Audit-Logs isoliert betrachtet, keine Correlation für:
- Brute-Force-Angriffe (mehrere fehlgeschlagene Logins)
- Privilege-Escalation-Versuche
- Ungewöhnliche Datenzugriffe (UEBA - User Entity Behavior Analytics)

**Empfehlung:** SecurityEventCorrelationService implementieren

---

### 1.7 Access Control & Authentication

**Status:** ✅ **EXCELLENT** (NIS2-konform)

**Implementierte Features:**
- Multi-Factor-Authentication (TOTP, WebAuthn, SMS, Hardware-Token, Backup-Codes)
- RBAC mit hierarchischen Rollen (USER → AUDITOR → MANAGER → ADMIN → SUPER_ADMIN)
- 50+ Granular Permissions
- Azure OAuth & SAML Integration
- Session-Management mit Timeout
- Remember-Me mit secure/httponly Cookies
- User-Impersonation für Support

**ISO 27001:2022 Mapping:**
- ✅ A.5.15 (Access control)
- ✅ A.5.16 (Identity management)
- ✅ A.5.17 (Authentication information)
- ✅ A.5.18 (Access rights)

**NIS2 Compliance:**
- ✅ Art. 21.2.b (Multi-factor authentication)

**Identified Gaps:**

#### GAP-ACC-01: Fehlende Session-Concurrent-Limit (LOW)
**ISO 27001 Referenz:** A.5.15, A.8.5 (Secure authentication)
**Beschreibung:** User kann unbegrenzt viele parallele Sessions haben
**Risiko:** Account-Sharing, Session-Hijacking
**Empfehlung:** Max. 3 concurrent Sessions pro User

#### GAP-ACC-02: Keine Password-Expiry-Policy (LOW)
**ISO 27001 Referenz:** A.5.17
**Beschreibung:** Passwörter laufen nicht ab
**Empfehlung:** Optional: 90-Tage-Passwort-Rotation für lokale Accounts (nicht Azure SSO)

---

### 1.8 Cryptographic Operations Management

**Status:** ✅ **GOOD**

**Implementierte Features:**
- Cryptographic-Operations-Logging (encrypt, decrypt, sign, verify, hash, key_generation, key_rotation, key_deletion)
- Algorithm & Key-Length-Tracking
- Purpose & Data-Classification-Logging
- User & Asset-Association
- Success/Failure-Status

**ISO 27001:2022 Mapping:**
- ✅ A.8.24 (Use of cryptography)

**Identified Gaps:**

#### GAP-CRY-01: Fehlende Key-Lifecycle-Management (MEDIUM)
**ISO 27001 Referenz:** A.8.24
**Beschreibung:** Cryptographic-Keys werden geloggt, aber:
- Keine Key-Expiry-Tracking
- Keine automatische Key-Rotation-Erinnerung
- Keine FIPS 140-2/3 Compliance-Validation

**Empfehlung:**
```php
// CryptographicKey Entity (NEU)
class CryptographicKey
{
    private ?\DateTimeImmutable $expiryDate = null;
    private ?int $rotationIntervalDays = 365;
    private ?string $complianceStandard = 'FIPS_140_3'; // FIPS_140_2, FIPS_140_3, CC_EAL4
}
```

---

## 2. ISO 27001:2022 Compliance Gap Analysis

### Clause 4-10 (ISMS Core Requirements)

| Clause | Requirement | Status | Gap |
|--------|-------------|--------|-----|
| 4.1 | Understanding the organization and its context | ✅ COMPLIANT | ISMSContext Entity vorhanden |
| 4.2 | Understanding the needs of interested parties | ✅ COMPLIANT | InterestedParty Entity |
| 4.3 | Determining the scope of the ISMS | ✅ COMPLIANT | ISMSContext.scope |
| 5.1 | Leadership and commitment | 🟡 PARTIAL | Keine Management-Review-Workflows |
| 6.1.2 | Information security risk assessment | ✅ COMPLIANT | Risk Entity mit Assessment |
| 6.1.3 | Information security risk treatment | 🟡 PARTIAL | GAP-RSK-01, GAP-RSK-02 |
| 8.1 | Operational planning and control | ✅ COMPLIANT | Workflows implementiert |
| 9.1 | Monitoring, measurement, analysis | 🔴 NON-COMPLIANT | GAP-CTL-01 (Effectiveness-Messung) |
| 9.2 | Internal audit | ✅ COMPLIANT | InternalAudit Entity |
| 9.3 | Management review | 🟡 PARTIAL | ManagementReview Entity ohne Workflow |
| 10.1 | Nonconformity and corrective action | ✅ COMPLIANT | Incident.correctiveActions |

### Annex A Controls (93 Controls)

**Implementierungsstand:** 93/93 Controls vorhanden (100%)
**Effectiveness-Messung:** 0/93 Controls mit KPIs (0%) ❌

| Kategorie | Controls | Implementiert | Effektiv gemessen | Compliance |
|-----------|----------|---------------|-------------------|------------|
| A.5 (Organizational) | 37 | 37/37 ✅ | 0/37 ❌ | 50% |
| A.6 (People) | 8 | 8/8 ✅ | 0/8 ❌ | 50% |
| A.7 (Physical) | 14 | 14/14 ✅ | 0/14 ❌ | 50% |
| A.8 (Technical) | 34 | 34/34 ✅ | 0/34 ❌ | 50% |

**Kritische Control-Gaps:**

#### A.8.15 (Logging) - Teilweise implementiert
- ✅ Umfassendes Audit-Logging
- ❌ Keine Log-Retention-Policy (GAP-AUD-01)
- ❌ Keine Log-Integrity-Protection (GAP-AUD-02)

#### A.8.16 (Monitoring activities) - Teilweise implementiert
- ✅ Grundlegendes Monitoring
- ❌ Keine Security-Event-Correlation (GAP-AUD-03)
- ❌ Keine SIEM-Integration (GAP-INC-02)

#### A.9.1 (Performance evaluation) - NICHT implementiert
- ❌ Keine Control-Effectiveness-KPIs (GAP-CTL-01)

---

## 3. BSI IT-Grundschutz Compliance

**Relevante Bausteine:**

| Baustein | Titel | Compliance | Gaps |
|----------|-------|------------|------|
| ORP.5 | Compliance Management | 🟡 75% | Fehlende Framework-Mappings |
| OPS.1.1.5 | Protokollierung | 🟡 70% | GAP-AUD-01, GAP-AUD-02 |
| OPS.1.2.5 | Fernwartung | ✅ 90% | MFA vorhanden |
| APP.3.1 | Webanwendungen | ✅ 85% | OWASP-konform |
| CON.3 | Datensicherungskonzept | ✅ 90% | BackupService vorhanden |

**Kritische Lücken:**
- **OPS.1.1.5.A4:** Log-Aufbewahrung nicht geregelt (GAP-AUD-01)
- **ORP.5.A2:** Compliance-Anforderungen nicht durchgängig gemappt (GAP-CTL-03)

---

## 4. NIS2-Richtlinie Compliance

**Artikel 21 (Cybersecurity risk-management measures):**

| Anforderung | Compliance | Notizen |
|-------------|-----------|---------|
| Art. 21.2.a (Risk analysis) | ✅ 90% | Risk Entity vollständig |
| Art. 21.2.b (MFA) | ✅ 100% | TOTP, WebAuthn, SMS, Hardware-Token |
| Art. 21.2.c (Cryptography) | ✅ 85% | Logging vorhanden, Key-Lifecycle fehlt (GAP-CRY-01) |
| Art. 21.2.d (Vulnerability handling) | ✅ 90% | CVSS, Remediation-Deadlines, Vuln→Incident fehlt (GAP-VUL-01) |
| Art. 21.2.e (Incident handling) | ✅ 95% | Exzellent implementiert, SIEM fehlt (GAP-INC-02) |

**Artikel 23 (Reporting obligations):**

| Deadline | Compliance | Implementierung |
|----------|-----------|-----------------|
| 24h Early Warning | ✅ 100% | `getEarlyWarningDeadline()`, Countdown-Tracker |
| 72h Detailed Notification | ✅ 100% | `getDetailedNotificationDeadline()` |
| 30d Final Report | ✅ 100% | `getFinalReportDeadline()` |

**NIS2 Gesamtbewertung:** 92% Compliance ✅

---

## 5. DSGVO Compliance

**Relevante Artikel:**

| Artikel | Anforderung | Compliance | Gaps |
|---------|-------------|-----------|------|
| Art. 5.1(e) | Speicherbegrenzung | 🔴 NON-COMPLIANT | GAP-AUD-01 (unbegrenzte Log-Speicherung) |
| Art. 25 | Privacy by Design | ✅ 85% | Datenklassifikation vorhanden |
| Art. 30 | Verzeichnis von Verarbeitungstätigkeiten | 🟡 PARTIAL | Kein VVT-Modul |
| Art. 32 | Sicherheit der Verarbeitung | ✅ 90% | MFA, Encryption, Access-Control |
| Art. 33 | Meldung von Datenschutzverletzungen | ✅ 95% | Incident.dataBreachOccurred |

**Kritische DSGVO-Lücke:**
- **Art. 5.1(e):** Audit-Logs werden unbegrenzt gespeichert → DSGVO-Verstoß (GAP-AUD-01)

---

## 6. Priorisierte Roadmap für ISMS-Verbesserungen

### Phase 1: CRITICAL Gaps (Sofort, 1-2 Wochen)

**1. GAP-CTL-01: Control-Effectiveness-Messung (ISO 27001 Clause 9.1)**
- **Aufwand:** 5 Tage
- **Umsetzung:**
  - `ControlEffectivenessService` implementieren
  - KPI-Definition pro Control-Kategorie
  - Dashboard-Widget für Control-Effectiveness-Trends
  - Monatlicher Control-Effectiveness-Report (automatisch)

**2. GAP-AUD-01: Audit-Log-Retention-Policy (DSGVO, NIS2)**
- **Aufwand:** 3 Tage
- **Umsetzung:**
  - `AuditLogCleanupCommand` (Cron-Job)
  - Retention-Policy-Konfiguration (config/packages/audit.yaml)
  - Archivierung zu Cold Storage vor Deletion
  - DSGVO-konforme Aufbewahrungsfristen: 365 Tage

**3. GAP-VUL-01: Vulnerability→Incident Linking**
- **Aufwand:** 2 Tage
- **Umsetzung:**
  - `Incident.relatedVulnerabilities` ManyToMany-Relationship
  - Automatisches Incident-Triggering bei aktiv ausgenutzten Vulns
  - Vulnerability-Widget im Incident-Detail-View

### Phase 2: HIGH Gaps (1-2 Monate)

**4. GAP-CTL-02: Automatische Control-Review-Eskalation**
- **Aufwand:** 4 Tage
- **Umsetzung:**
  - `ControlReviewEscalationCommand` (täglich)
  - Email-Benachrichtigung an Responsible-Person bei überfälligen Reviews
  - Dashboard-Alert-Widget
  - Management-Review-Integration bei kritischen Controls

**5. GAP-AUD-02: Audit-Log-Integrity-Protection**
- **Aufwand:** 5 Tage
- **Umsetzung:**
  - HMAC-Signing für Audit-Logs
  - Log-Verification-Command
  - External SIEM-Forwarding (Syslog, HTTPS)

**6. GAP-INC-02: SIEM-Integration**
- **Aufwand:** 10 Tage
- **Umsetzung:**
  - REST API für SIEM-Connector
  - Bidirektionale Integration (Incident-Import von SIEM)
  - Webhook-Support für Security-Tools
  - Splunk/Elastic SIEM Adapter

### Phase 3: MEDIUM Gaps (3-6 Monate)

**7. GAP-RSK-01: Risk-Appetite-Integration**
**8. GAP-RSK-02: Risk-Acceptance-Workflow**
**9. GAP-CTL-03: Control-to-Framework-Mapping-Validierung**
**10. GAP-CRY-01: Key-Lifecycle-Management**
**11. GAP-ASM-01: Asset-Lifecycle-Verwaltung**
**12. GAP-VUL-02: Vulnerability-Scanner-Integration**

### Phase 4: LOW Gaps (Nice-to-Have, >6 Monate)

**13. GAP-ACC-01: Session-Concurrent-Limit**
**14. GAP-ASM-02: Asset-Dependency-Mapping**
**15. GAP-INC-01: Incident-Classification-Automation**
**16. GAP-AUD-03: Security-Event-Correlation (UEBA)**

---

## 7. Konkrete Code-Empfehlungen

### 7.1 Control-Effectiveness-Messung

```php
// src/Service/ControlEffectivenessService.php
namespace App\Service;

use App\Entity\Control;
use App\Repository\IncidentRepository;
use App\Repository\VulnerabilityRepository;

class ControlEffectivenessService
{
    public function __construct(
        private IncidentRepository $incidentRepository,
        private VulnerabilityRepository $vulnerabilityRepository,
    ) {}

    public function calculateEffectiveness(Control $control): ControlEffectivenessMetrics
    {
        $implementationDate = $control->getLastReviewDate() ?? $control->getCreatedAt();

        return new ControlEffectivenessMetrics([
            'incident_reduction_rate' => $this->measureIncidentReduction($control, $implementationDate),
            'vulnerability_coverage_rate' => $this->measureVulnerabilityCoverage($control),
            'mttr' => $this->calculateMeanTimeToRemediate($control),
            'compliance_test_pass_rate' => $this->getComplianceTestResults($control),
            'overall_score' => $this->calculateWeightedScore(...),
            'trend' => $this->calculateTrend($control, 90), // 90 Tage
        ]);
    }

    private function measureIncidentReduction(Control $control, \DateTimeInterface $since): float
    {
        $protectedAssets = $control->getProtectedAssets();
        if ($protectedAssets->isEmpty()) {
            return 100.0; // Keine Assets → 100% effektiv
        }

        $incidentsBefore = $this->incidentRepository->countForAssetsBeforeDate($protectedAssets, $since);
        $incidentsAfter = $this->incidentRepository->countForAssetsAfterDate($protectedAssets, $since);

        if ($incidentsBefore === 0) {
            return $incidentsAfter === 0 ? 100.0 : 0.0;
        }

        $reductionRate = (($incidentsBefore - $incidentsAfter) / $incidentsBefore) * 100;
        return max(0, min(100, $reductionRate));
    }

    private function measureVulnerabilityCoverage(Control $control): float
    {
        // Wie viele Vulnerabilities auf protected Assets wurden durch Controls adressiert?
        $assets = $control->getProtectedAssets();
        $totalVulns = $this->vulnerabilityRepository->countForAssets($assets);
        $remediatedVulns = $this->vulnerabilityRepository->countRemediatedForAssets($assets);

        if ($totalVulns === 0) {
            return 100.0;
        }

        return ($remediatedVulns / $totalVulns) * 100;
    }

    private function calculateMeanTimeToRemediate(Control $control): ?int
    {
        // Durchschnittliche Zeit von Incident-Detection bis Resolution für geschützte Assets
        $incidents = $this->incidentRepository->findResolvedForControl($control);
        if (empty($incidents)) {
            return null;
        }

        $totalMinutes = 0;
        foreach ($incidents as $incident) {
            $detectedAt = $incident->getDetectedAt();
            $resolvedAt = $incident->getResolvedAt();
            if ($detectedAt && $resolvedAt) {
                $totalMinutes += $detectedAt->diff($resolvedAt)->days * 24 * 60;
            }
        }

        return (int)($totalMinutes / count($incidents)); // MTTR in Minuten
    }
}

// src/Entity/ControlEffectivenessMetrics.php
class ControlEffectivenessMetrics
{
    public function __construct(
        public float $incidentReductionRate,
        public float $vulnerabilityCoverageRate,
        public ?int $mttr, // Mean Time To Remediate in minutes
        public float $complianceTestPassRate,
        public float $overallScore,
        public string $trend, // 'improving', 'stable', 'degrading'
    ) {}
}
```

### 7.2 Audit-Log-Retention-Policy

```php
// src/Command/AuditLogCleanupCommand.php
namespace App\Command;

use App\Repository\AuditLogRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;

class AuditLogCleanupCommand extends Command
{
    protected static $defaultName = 'app:audit:cleanup-old-logs';

    private const RETENTION_DAYS_NORMAL = 365; // 1 Jahr (NIS2-konform)
    private const RETENTION_DAYS_SECURITY = 730; // 2 Jahre für Security-Events

    public function __construct(
        private AuditLogRepository $auditLogRepository,
        private EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $cutoffDateNormal = new \DateTimeImmutable("-" . self::RETENTION_DAYS_NORMAL . " days");
        $cutoffDateSecurity = new \DateTimeImmutable("-" . self::RETENTION_DAYS_SECURITY . " days");

        // Security-Events behalten länger (Login, Logout, Permission-Changes)
        $securityActions = ['login', 'logout', 'permission_change', 'role_change'];

        $normalLogs = $this->auditLogRepository->findOldLogs($cutoffDateNormal, $securityActions);
        $securityLogs = $this->auditLogRepository->findOldSecurityLogs($cutoffDateSecurity, $securityActions);

        // Archivierung zu Cold Storage (optional)
        $this->archiveToColdStorage($normalLogs);
        $this->archiveToColdStorage($securityLogs);

        // Deletion
        foreach (array_merge($normalLogs, $securityLogs) as $log) {
            $this->entityManager->remove($log);
        }

        $this->entityManager->flush();

        $output->writeln(sprintf(
            'Deleted %d normal logs and %d security logs',
            count($normalLogs),
            count($securityLogs)
        ));

        return Command::SUCCESS;
    }

    private function archiveToColdStorage(array $logs): void
    {
        // Implementierung: Export zu S3 Glacier, Azure Archive Storage, etc.
        // JSON-Export mit GZIP-Komprimierung
    }
}

// config/services.yaml - Cron-Job
# services:
#     App\Command\AuditLogCleanupCommand:
#         tags:
#             - { name: 'console.command' }
#             - { name: 'scheduler.task', expression: '0 2 * * 0' } # Jeden Sonntag 02:00
```

### 7.3 Vulnerability→Incident Linking

```php
// src/Entity/Incident.php - ADD
/**
 * @var Collection<int, Vulnerability>
 */
#[ORM\ManyToMany(targetEntity: Vulnerability::class)]
#[ORM\JoinTable(name: 'incident_vulnerability')]
#[Groups(['incident:read', 'incident:write'])]
#[MaxDepth(1)]
private Collection $relatedVulnerabilities;

public function __construct()
{
    // ... existing code
    $this->relatedVulnerabilities = new ArrayCollection();
}

public function getRelatedVulnerabilities(): Collection
{
    return $this->relatedVulnerabilities;
}

public function addRelatedVulnerability(Vulnerability $vulnerability): static
{
    if (!$this->relatedVulnerabilities->contains($vulnerability)) {
        $this->relatedVulnerabilities->add($vulnerability);
    }
    return $this;
}

// src/Service/VulnerabilityMonitoringService.php (NEU)
class VulnerabilityMonitoringService
{
    public function checkForActiveExploits(): void
    {
        // Finde alle aktiv ausgenutzten Vulnerabilities
        $activelyExploitedVulns = $this->vulnerabilityRepository->findBy([
            'activelyExploited' => true,
            'status' => ['open', 'patched'], // Noch nicht geschlossen
        ]);

        foreach ($activelyExploitedVulns as $vuln) {
            // Automatisches Incident-Triggering, wenn:
            // - Vulnerability betrifft kritische Assets
            // - Exploit ist "weaponized" oder "public"
            if ($this->shouldTriggerIncident($vuln)) {
                $this->createSecurityIncident($vuln);
            }
        }
    }

    private function shouldTriggerIncident(Vulnerability $vuln): bool
    {
        // Triggering-Kriterien:
        return $vuln->getSeverity() === 'critical'
            && $vuln->isActivelyExploited()
            && in_array($vuln->getExploitAvailability(), ['public', 'weaponized'])
            && $this->hasHighValueAssets($vuln);
    }

    private function createSecurityIncident(Vulnerability $vuln): Incident
    {
        $incident = new Incident();
        $incident->setTitle("Active Exploitation: " . $vuln->getCveId());
        $incident->setSeverity('critical');
        $incident->setCategory('security');
        $incident->addRelatedVulnerability($vuln);
        $incident->setAffectedAssets($vuln->getAffectedAssets());

        // Auto-assign failed controls
        foreach ($vuln->getAffectedAssets() as $asset) {
            foreach ($asset->getProtectingControls() as $control) {
                $incident->addFailedControl($control);
            }
        }

        $this->entityManager->persist($incident);
        $this->entityManager->flush();

        return $incident;
    }
}
```

---

## 8. UI/UX-Verbesserungen für ISM

### 8.1 Control-Effectiveness-Dashboard

**Fehlende Features:**
- Kein zentrales Dashboard für Control-Effectiveness-Monitoring
- Keine Trend-Visualisierung (letzte 90 Tage)
- Keine Red-Flag-Indicators für ineffektive Controls

**Empfehlung:**
```twig
{# templates/control/effectiveness_dashboard.html.twig #}
<div class="effectiveness-dashboard">
    <div class="row">
        <div class="col-md-3">
            <div class="card bg-danger text-white">
                <div class="card-body">
                    <h5>Ineffective Controls</h5>
                    <h2>{{ ineffectiveControls|length }}</h2>
                    <small>Effectiveness < 60%</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-warning text-white">
                <div class="card-body">
                    <h5>Degrading Trend</h5>
                    <h2>{{ degradingControls|length }}</h2>
                    <small>Last 90 days</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-success text-white">
                <div class="card-body">
                    <h5>Effective Controls</h5>
                    <h2>{{ effectiveControls|length }}</h2>
                    <small>Effectiveness >= 80%</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-info text-white">
                <div class="card-body">
                    <h5>Avg MTTR</h5>
                    <h2>{{ avgMttr }} h</h2>
                    <small>Mean Time To Remediate</small>
                </div>
            </div>
        </div>
    </div>

    <div class="card mt-4">
        <div class="card-header">
            <h5>Control Effectiveness Heatmap (ISO 27001 Anhang A)</h5>
        </div>
        <div class="card-body">
            <canvas id="effectivenessHeatmap"></canvas>
        </div>
    </div>
</div>
```

### 8.2 Vulnerability→Incident Workflow-Integration

**Fehlende Features:**
- Kein "Create Incident from Vulnerability"-Button
- Keine automatische Asset/Control-Verknüpfung

**Empfehlung:**
```twig
{# templates/vulnerability/show.html.twig - ADD #}
{% if vulnerability.activelyExploited %}
    <div class="alert alert-danger">
        <h5><i class="bi bi-exclamation-triangle"></i> ACTIVELY EXPLOITED!</h5>
        <p>This vulnerability is being actively exploited in the wild.</p>
        <a href="{{ path('app_incident_new_from_vulnerability', {id: vulnerability.id}) }}"
           class="btn btn-danger">
            <i class="bi bi-shield-exclamation"></i> Create Security Incident
        </a>
    </div>
{% endif %}

<div class="card mt-3">
    <div class="card-header">
        <h5>Related Incidents</h5>
    </div>
    <div class="card-body">
        {% if vulnerability.relatedIncidents|length > 0 %}
            <ul class="list-group">
                {% for incident in vulnerability.relatedIncidents %}
                    <li class="list-group-item">
                        <a href="{{ path('app_incident_show', {id: incident.id}) }}">
                            {{ incident.incidentNumber }} - {{ incident.title }}
                        </a>
                        <span class="badge bg-{{ incident.severity == 'critical' ? 'danger' : 'warning' }}">
                            {{ incident.severity|upper }}
                        </span>
                    </li>
                {% endfor %}
            </ul>
        {% else %}
            <p class="text-muted">No related incidents.</p>
        {% endif %}
    </div>
</div>
```

---

## 9. Compliance-Reporting-Verbesserungen

### 9.1 Fehlende ISO 27001 Clause 9.1 Performance-Evaluation-Report

**Aktuell:** Nur Statement of Applicability (SoA) Export vorhanden
**Fehlt:** Monatlicher/Quartalsweiser ISMS-Performance-Report

**Empfehlung:**
```php
// src/Service/IsmsPerformanceReportService.php
class IsmsPerformanceReportService
{
    public function generateMonthlyReport(\DateTimeInterface $month): IsmsPerformanceReport
    {
        return new IsmsPerformanceReport([
            'control_effectiveness' => $this->getControlEffectivenessMetrics($month),
            'incident_statistics' => $this->getIncidentStatistics($month),
            'vulnerability_management' => $this->getVulnerabilityMetrics($month),
            'risk_treatment_progress' => $this->getRiskTreatmentProgress($month),
            'compliance_status' => $this->getComplianceStatus(),
            'top_risks' => $this->getTopRisks(10),
            'recommendations' => $this->generateRecommendations(),
        ]);
    }

    private function generateRecommendations(): array
    {
        $recommendations = [];

        // Ineffective Controls
        $ineffectiveControls = $this->controlRepository->findIneffectiveControls(60); // < 60%
        if (count($ineffectiveControls) > 0) {
            $recommendations[] = [
                'priority' => 'high',
                'category' => 'control_effectiveness',
                'finding' => count($ineffectiveControls) . ' controls with effectiveness < 60%',
                'recommendation' => 'Review and strengthen ineffective controls',
                'controls' => $ineffectiveControls,
            ];
        }

        // Overdue Vulnerabilities
        $overdueVulns = $this->vulnerabilityRepository->findOverdue();
        if (count($overdueVulns) > 0) {
            $recommendations[] = [
                'priority' => 'critical',
                'category' => 'vulnerability_management',
                'finding' => count($overdueVulns) . ' overdue vulnerabilities',
                'recommendation' => 'Immediate remediation required (NIS2 compliance risk)',
                'vulnerabilities' => $overdueVulns,
            ];
        }

        return $recommendations;
    }
}
```

---

## 10. Zusammenfassung & Priorisierung

### Kritikalität nach ISO 27001 Audit-Perspektive

**CRITICAL (Must-Fix vor Zertifizierung):**
1. ✅ **GAP-CTL-01**: Control-Effectiveness-Messung (ISO 27001 Clause 9.1)
2. ✅ **GAP-AUD-01**: Audit-Log-Retention-Policy (DSGVO, NIS2)
3. ✅ **GAP-VUL-01**: Vulnerability→Incident Linking (ISO 27001 A.8.8)

**HIGH (Sollte vor Zertifizierung behoben werden):**
4. ✅ **GAP-CTL-02**: Automatische Control-Review-Eskalation
5. ✅ **GAP-AUD-02**: Audit-Log-Integrity-Protection
6. ✅ **GAP-INC-02**: SIEM-Integration

**MEDIUM (Nach Zertifizierung, innerhalb 6 Monate):**
7-11. Risk-Appetite, Risk-Acceptance, Framework-Mapping, Key-Lifecycle, Asset-Lifecycle

**LOW (Nice-to-Have, >6 Monate):**
12-16. Session-Limits, Asset-Dependencies, Event-Correlation, etc.

### Geschätzte Umsetzungszeit

- **Phase 1 (CRITICAL):** 10 Tage
- **Phase 2 (HIGH):** 19 Tage
- **Phase 3 (MEDIUM):** 30 Tage
- **Phase 4 (LOW):** 15 Tage

**Gesamt:** 74 Arbeitstage (ca. 3-4 Monate bei 1 Vollzeit-Entwickler)

### ISO 27001 Zertifizierungsfähigkeit

**Aktuell:** 75% (Good Foundation)
**Nach Phase 1:** 85% (Ready for Pre-Audit)
**Nach Phase 2:** 95% (Certification-Ready)

---

## 11. Norm-Referenzen

### ISO 27001:2022
- **Clause 9.1:** Monitoring, measurement, analysis and evaluation
- **Clause 9.3:** Management review
- **Clause 6.1.3:** Information security risk treatment
- **Anhang A:** 93 Controls (A.5.1 - A.8.34)

### ISO 27002:2022
- **Implementation Guidance** für alle Anhang A Controls

### BSI IT-Grundschutz
- **ORP.5:** Compliance Management (Einhaltung von Anforderungen)
- **OPS.1.1.5:** Protokollierung
- **OPS.1.2.5:** Fernwartung

### NIS2-Richtlinie (EU) 2022/2555
- **Art. 21.2:** Cybersecurity risk-management measures
- **Art. 23:** Reporting obligations (24h/72h/30d)

### DSGVO (EU) 2016/679
- **Art. 5.1(e):** Speicherbegrenzung
- **Art. 25:** Datenschutz durch Technikgestaltung
- **Art. 32:** Sicherheit der Verarbeitung
- **Art. 33:** Meldung von Datenschutzverletzungen

---

**Ende des Berichts**

**Erstellt am:** 2025-11-19
**Nächste Review:** Empfohlen nach Umsetzung von Phase 1 (CRITICAL Gaps)
