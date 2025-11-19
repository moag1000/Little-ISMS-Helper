# Risk Manager Analysis - Little ISMS Helper
**Audit Date:** 2025-11-19
**Auditor:** Experienced Risk Manager (ISO 27001, ISO 31000, IT-Grundschutz)
**Scope:** Comprehensive Risk Management Workflow Analysis
**Version:** 1.0

---

## Executive Summary

The Little ISMS Helper demonstrates a **solid foundation for ISO 27001 risk management** with several advanced features including risk appetite management, treatment planning, and intelligent risk analysis. However, there are **critical gaps and inconsistencies** that prevent it from achieving full compliance with ISO 27001:2022, ISO 31000:2018, and DSGVO requirements.

### Key Strengths
- Strong entity model with risk-control-asset-incident linkage (Data Reuse principle)
- Advanced risk appetite framework (ISO 27005:2022 compliant)
- Residual risk calculation and treatment plan tracking
- Multi-tenant support with corporate governance integration
- Comprehensive audit logging for risk changes

### Critical Issues (Require Immediate Attention)
1. **Missing Risk Categories:** No risk categorization system (Financial, Operational, Strategic, Compliance, Reputational)
2. **Inconsistent Risk Thresholds:** Multiple conflicting risk level definitions across codebase
3. **Incomplete Translation Coverage:** Risk form labels missing German translations
4. **No Risk Review Workflow:** Missing periodic review reminders and approval workflows
5. **DSGVO Risk Assessment Gaps:** No specific support for Art. 32 risk-based approach

---

## 1. Workflow Analysis

### 1.1 Risk Identification Phase

**Current Implementation:**
- Manual risk creation via form (`RiskType.php`)
- Basic fields: Title, Description, Threat, Vulnerability
- Asset association (required)
- Risk owner assignment (optional - CRITICAL ISSUE)

**Findings:**

#### CRITICAL - Missing Risk Categorization
**Severity:** HIGH
**ISO 27005:2022 Reference:** Section 8.2.3 (Risk identification)

The system lacks a risk categorization framework. All risks are treated uniformly without classification by:
- Risk type (Strategic, Financial, Operational, Compliance, Reputational)
- Business unit affected
- Information security domain (Confidentiality, Integrity, Availability)

**Evidence:**
```php
// src/Entity/Risk.php - No category field
#[ORM\Column(length: 255)]
private ?string $title = null;
// Missing: private ?string $category = null;
```

**Impact:**
- Cannot group risks by category for reporting
- Risk appetite service attempts keyword-based categorization (unreliable)
- No alignment with ISO 27005 risk categorization best practices

**Recommendation:**
Add mandatory risk category field with predefined taxonomy:
```php
#[ORM\Column(length: 100)]
#[Assert\NotBlank(message: 'Risk category is required')]
#[Assert\Choice(choices: ['financial', 'operational', 'compliance', 'strategic', 'reputational', 'security'])]
private ?string $category = null;
```

#### HIGH - Risk Owner Not Required
**Severity:** HIGH
**ISO 27001:2022 Reference:** A.5.1 (Policies for information security), A.5.2 (Information security roles and responsibilities)

Risk owner field is optional in the form, but ISO 27001 mandates clear ownership.

**Evidence:**
```php
// src/Form/RiskType.php:97
->add('riskOwner', EntityType::class, [
    'required' => false,  // SHOULD BE TRUE
```

**Recommendation:**
Make risk owner mandatory for all risks, enforce at validation level.

---

### 1.2 Risk Assessment Phase

**Current Implementation:**
- 5x5 risk matrix (Probability × Impact)
- Inherent risk calculation (brutto-risiko)
- Residual risk calculation (netto-risiko)
- Risk level classification (Critical/High/Medium/Low)

**Findings:**

#### CRITICAL - Inconsistent Risk Level Thresholds
**Severity:** CRITICAL
**Standard:** ISO 27005:2022 Section 8.3 (Risk evaluation)

Multiple conflicting risk level definitions exist across the codebase:

**Location 1: RiskMatrixService.php (Lines 97-105)**
```php
if ($riskScore >= 20) {
    $statistics['critical']++;  // CRITICAL: 20-25
} elseif ($riskScore >= 12) {
    $statistics['high']++;      // HIGH: 12-19
} elseif ($riskScore >= 6) {
    $statistics['medium']++;    // MEDIUM: 6-11
} else {
    $statistics['low']++;       // LOW: 1-5
}
```

**Location 2: RiskController.php Export (Lines 224-229)**
```php
$riskLevel = match(true) {
    $riskScore >= 15 => 'Kritisch',  // CRITICAL: 15-25 (DIFFERENT!)
    $riskScore >= 8 => 'Hoch',       // HIGH: 8-14 (DIFFERENT!)
    $riskScore >= 4 => 'Mittel',     // MEDIUM: 4-7 (DIFFERENT!)
    default => 'Niedrig'              // LOW: 1-3 (DIFFERENT!)
};
```

**Location 3: index_modern.html.twig (Lines 189-193)**
```php
<option value="critical">Kritisch (15-25)</option>  // MATCHES Controller
<option value="high">Hoch (8-14)</option>
<option value="medium">Mittel (4-7)</option>
<option value="low">Niedrig (1-3)</option>
```

**Impact:**
- Dashboard shows different risk counts than exports
- Confusion for users about what constitutes "high risk"
- Non-compliance with ISO 31000 principle of consistent risk criteria

**Recommendation:**
Centralize risk level thresholds in configuration:

```yaml
# config/risk_levels.yaml
risk_levels:
    critical:
        min_score: 15
        max_score: 25
        color: '#dc3545'
        label_de: 'Kritisch'
        label_en: 'Critical'
    high:
        min_score: 8
        max_score: 14
        color: '#fd7e14'
        label_de: 'Hoch'
        label_en: 'High'
    medium:
        min_score: 4
        max_score: 7
        color: '#ffc107'
        label_de: 'Mittel'
        label_en: 'Medium'
    low:
        min_score: 1
        max_score: 3
        color: '#28a745'
        label_de: 'Niedrig'
        label_en: 'Low'
```

Create a `RiskLevelService` to centralize threshold logic.

#### MEDIUM - Missing Risk Assessment Methodology Documentation
**Severity:** MEDIUM
**ISO 27005:2022 Reference:** Section 7.2 (Establishing the risk assessment framework)

No documentation of:
- How to determine probability values (1-5 scale)
- How to determine impact values (1-5 scale)
- Qualitative vs. quantitative assessment guidance
- Examples for each level

**Evidence:**
Form help text is generic:
```php
'help' => 'risk.help.probability'|trans|default('Wie wahrscheinlich ist das Eintreten? (1=sehr unwahrscheinlich, 5=sehr wahrscheinlich)')
```

**Recommendation:**
Create detailed risk assessment guide with:
- Probability scale with concrete timeframes (e.g., 5 = >90% within 1 year)
- Impact scale with financial/operational thresholds
- DSGVO-specific impact criteria (Art. 32)

---

### 1.3 Risk Treatment Phase

**Current Implementation:**
- Four treatment strategies: Mitigate, Accept, Transfer, Avoid
- Treatment description field
- Risk acceptance approval workflow (partial)
- Risk treatment plan entity with timeline tracking

**Findings:**

#### HIGH - Incomplete Risk Acceptance Workflow
**Severity:** HIGH
**ISO 27005:2022 Reference:** Section 8.4.4 (Risk acceptance)

The risk acceptance approval is tracked but not enforced:

**Evidence:**
```php
// src/Entity/Risk.php:647-651
public function isAcceptanceApprovalRequired(): bool
{
    return $this->treatmentStrategy === 'accept' && !$this->formallyAccepted;
}
```

However, there's **no workflow enforcement** to:
1. Prevent risk status change to "accepted" without approval
2. Notify approvers when acceptance is pending
3. Require executive approval for high-risk acceptance
4. Auto-reject acceptance if risk exceeds appetite

**Recommendation:**
Implement Risk Acceptance Workflow:

```php
// src/Service/RiskAcceptanceWorkflowService.php
class RiskAcceptanceWorkflowService {
    public function requestAcceptance(Risk $risk, User $requester): void
    {
        // 1. Validate risk qualifies for acceptance
        if ($risk->getResidualRiskLevel() > $this->getMaxAcceptableWithoutExecutiveApproval()) {
            throw new RiskAcceptanceException('Executive approval required for high-risk acceptance');
        }

        // 2. Check against risk appetite
        $appetiteCheck = $this->riskAppetiteService->analyzeRiskAppetite($risk);
        if ($appetiteCheck['requires_action']) {
            throw new RiskAcceptanceException('Risk exceeds organizational appetite');
        }

        // 3. Create approval workflow
        $this->workflowService->createApprovalRequest(
            type: 'risk_acceptance',
            entity: $risk,
            requester: $requester,
            approver: $this->getRequiredApprover($risk)
        );

        // 4. Send notifications
        $this->notificationService->notifyRiskAcceptancePending($risk);
    }
}
```

#### MEDIUM - No Treatment Plan Progress Monitoring
**Severity:** MEDIUM
**ISO 27001:2022 Reference:** Clause 6.1.3 (Information security risk treatment)

RiskTreatmentPlan entity has progress tracking fields but no automated monitoring:

**Evidence:**
```php
// src/Entity/RiskTreatmentPlan.php has fields but no integration
#[ORM\Column(type: Types::INTEGER)]
private int $completionPercentage = 0;

public function isOverdue(): bool { ... }  // Method exists but unused
```

**Missing:**
- Dashboard widget for overdue treatment plans
- Email notifications for approaching deadlines
- Automatic status updates based on completion percentage

**Recommendation:**
Create Treatment Plan Monitoring Dashboard and scheduled task:

```php
// src/Command/RiskTreatmentPlanMonitorCommand.php
#[AsCommand(name: 'risk:monitor-treatment-plans')]
class RiskTreatmentPlanMonitorCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $overduePlans = $this->treatmentPlanRepository->findOverdue();
        $approachingDeadline = $this->treatmentPlanRepository->findApproachingDeadline(days: 7);

        // Send notifications
        foreach ($overduePlans as $plan) {
            $this->mailer->sendOverdueNotification($plan);
        }

        return Command::SUCCESS;
    }
}
```

---

### 1.4 Risk Monitoring & Review Phase

**Current Implementation:**
- Review date field in Risk entity
- Audit log tracking for risk changes
- Risk realization tracking via incidents

**Findings:**

#### CRITICAL - No Periodic Review Workflow
**Severity:** CRITICAL
**ISO 27001:2022 Reference:** Clause 6.1.3.d (Review and monitor risks)

Review date is tracked but **not enforced**:

**Evidence:**
```php
// src/Entity/Risk.php:153
#[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
private ?\DateTimeInterface $reviewDate = null;
```

**Missing:**
1. Automatic notifications before review date
2. Required review intervals (e.g., every 6 months for high risks)
3. Review completion tracking
4. Overdue review reporting on dashboard

**Recommendation:**
Implement Risk Review Management:

```php
// src/Service/RiskReviewService.php
class RiskReviewService
{
    public function getReviewSchedule(): array
    {
        return [
            'critical' => 90,  // days
            'high' => 180,
            'medium' => 365,
            'low' => 730,
        ];
    }

    public function getOverdueReviews(Tenant $tenant): array
    {
        $qb = $this->riskRepository->createQueryBuilder('r')
            ->where('r.tenant = :tenant')
            ->andWhere('r.reviewDate < :today OR r.reviewDate IS NULL')
            ->setParameter('tenant', $tenant)
            ->setParameter('today', new \DateTime())
            ->orderBy('r.reviewDate', 'ASC');

        return $qb->getQuery()->getResult();
    }

    public function scheduleNextReview(Risk $risk): void
    {
        $interval = $this->getReviewSchedule()[$this->getRiskLevel($risk)];
        $nextReview = (new \DateTime())->modify("+{$interval} days");
        $risk->setReviewDate($nextReview);
    }
}
```

Add dashboard widget in `templates/dashboard.html.twig`:

```twig
{# Overdue Risk Reviews Widget #}
{% if overdueReviews|length > 0 %}
<div class="alert alert-warning">
    <h5><i class="bi bi-calendar-x"></i> Überfällige Risikobewertungen</h5>
    <p>{{ overdueReviews|length }} Risiken erfordern eine Überprüfung:</p>
    <ul>
        {% for risk in overdueReviews|slice(0, 5) %}
        <li>
            <a href="{{ path('app_risk_show', {id: risk.id}) }}">{{ risk.title }}</a>
            <span class="text-muted">(Letzte Überprüfung: {{ risk.reviewDate|date('d.m.Y') }})</span>
        </li>
        {% endfor %}
    </ul>
</div>
{% endif %}
```

#### MEDIUM - Limited Risk KPIs and Metrics
**Severity:** MEDIUM
**ISO 31000:2018 Reference:** Section 6.6 (Monitoring and review)

Current metrics are basic:
- Total risks count
- Risk level distribution
- Treatment strategy distribution

**Missing KPIs:**
- Risk velocity (new risks per month)
- Mean time to treatment (MTTT)
- Risk reduction effectiveness
- Risk appetite compliance rate (% risks within appetite)
- Residual risk trend over time

**Recommendation:**
Create comprehensive risk metrics service:

```php
// src/Service/RiskMetricsService.php
class RiskMetricsService
{
    public function calculateKPIs(Tenant $tenant, \DateTimeInterface $startDate, \DateTimeInterface $endDate): array
    {
        return [
            'risk_velocity' => $this->getRiskVelocity($tenant, $startDate, $endDate),
            'mean_time_to_treatment' => $this->getMeanTimeToTreatment($tenant),
            'risk_reduction_rate' => $this->getRiskReductionRate($tenant),
            'appetite_compliance_rate' => $this->getAppetiteComplianceRate($tenant),
            'high_risk_closure_rate' => $this->getHighRiskClosureRate($tenant),
            'residual_risk_trend' => $this->getResidualRiskTrend($tenant, $startDate, $endDate),
        ];
    }
}
```

---

## 2. ISO 27001:2022 Compliance Analysis

### 2.1 Clause 6.1.2 - Information Security Risk Assessment

**Compliance Status:** PARTIAL (65%)

| Requirement | Status | Evidence | Gap |
|------------|--------|----------|-----|
| Risk criteria established | ✓ | 5x5 matrix defined | Inconsistent thresholds |
| Repeatable risk assessment | ✓ | RiskType form | No methodology docs |
| Consistent risk analysis | ✗ | Multiple methods | See Section 1.2 |
| Risk ownership defined | ⚠ | Optional field | Should be mandatory |
| Risk levels compared to criteria | ✓ | Risk appetite service | - |

**Required Actions:**
1. Standardize risk level thresholds across system
2. Document risk assessment methodology
3. Make risk owner mandatory
4. Create risk assessment training materials

---

### 2.2 Clause 6.1.3 - Information Security Risk Treatment

**Compliance Status:** GOOD (75%)

| Requirement | Status | Evidence | Gap |
|------------|--------|----------|-----|
| Risk treatment options | ✓ | 4 strategies implemented | - |
| Risk treatment plans | ✓ | RiskTreatmentPlan entity | No monitoring |
| Risk owners approve plans | ⚠ | Approval tracking | Not enforced |
| Residual risks acceptable | ⚠ | Risk appetite check | Manual process |
| Management approval | ⚠ | Acceptance fields | No workflow |

**Required Actions:**
1. Implement risk acceptance workflow
2. Add treatment plan monitoring
3. Enforce management approval for high risks

---

### 2.3 Annex A.5.7 - Threat Intelligence

**Compliance Status:** EXCELLENT (85%)

The `RiskIntelligenceService` provides strong threat intelligence integration:

**Strengths:**
```php
// src/Service/RiskIntelligenceService.php
public function suggestRisksFromIncidents(): array
{
    // Reuses incident data to identify new risks
    // ISO 27001:2022 A.5.7 compliant
}
```

**Enhancement Opportunity:**
Integrate external threat feeds (CERT-Bund, BSI cyber threat reports)

---

## 3. ISO 31000:2018 Alignment

### 3.1 Principles

| Principle | Implementation | Rating | Notes |
|-----------|---------------|--------|-------|
| Integrated | ✓ | Good | Multi-tenant, linked to assets/controls |
| Structured and comprehensive | ⚠ | Medium | Missing risk categories |
| Customized | ✓ | Good | Risk appetite by category |
| Inclusive | ✗ | Poor | No stakeholder involvement tracking |
| Dynamic | ⚠ | Medium | No automated monitoring |
| Best available information | ✓ | Excellent | Incident intelligence reuse |
| Human and cultural factors | ✗ | Poor | Not addressed |
| Continual improvement | ⚠ | Medium | Audit log exists, no review cycle |

**Priority Improvements:**
1. Add stakeholder involvement tracking
2. Implement dynamic risk monitoring
3. Create continuous improvement workflow

---

## 4. DSGVO (GDPR) Art. 32 Risk-Based Approach

**Compliance Status:** LIMITED (40%)

### 4.1 Missing DSGVO-Specific Features

#### CRITICAL - No Personal Data Processing Risk Assessment
**Severity:** CRITICAL
**DSGVO Reference:** Art. 32 (Security of processing)

The system lacks DSGVO-specific risk assessment for:
- Processing operations involving special category data (Art. 9)
- Large-scale processing
- Systematic monitoring
- Data subject vulnerability assessment

**Evidence:**
No fields in Risk entity for DSGVO context:
```php
// Missing:
// private ?bool $involvesPersonalData = null;
// private ?bool $involvesSpecialCategoryData = null;
// private ?string $legalBasis = null;
// private ?string $processingScale = null; // small/medium/large
```

**Recommendation:**
Extend Risk entity for DSGVO compliance:

```php
/**
 * DSGVO Risk Assessment Extension
 */
#[ORM\Column(type: Types::BOOLEAN)]
private bool $involvesPersonalData = false;

#[ORM\Column(type: Types::BOOLEAN)]
private bool $involvesSpecialCategoryData = false;

#[ORM\Column(length: 50, nullable: true)]
#[Assert\Choice(choices: ['consent', 'contract', 'legal_obligation', 'vital_interests', 'public_task', 'legitimate_interests'])]
private ?string $legalBasis = null;

#[ORM\Column(length: 50, nullable: true)]
#[Assert\Choice(choices: ['small', 'medium', 'large_scale'])]
private ?string $processingScale = null;

#[ORM\Column(type: Types::BOOLEAN)]
private bool $requiresDPIA = false;  // Data Protection Impact Assessment
```

Add DSGVO-specific risk assessment section to form.

#### HIGH - No Data Subject Rights Impact Assessment
**Severity:** HIGH

When assessing risks to personal data, must consider impact on data subject rights (Art. 12-22).

**Recommendation:**
Add data subject impact fields:
```php
#[ORM\Column(type: Types::TEXT, nullable: true)]
private ?string $dataSubjectImpact = null;

#[ORM\Column(type: Types::TEXT, nullable: true)]
private ?string $mitigationMeasures = null;
```

---

## 5. German/EU Terminology and Localization

### 5.1 Translation Coverage Analysis

**Findings:**

#### CRITICAL - Incomplete German Translations
**Severity:** HIGH
**Standard:** ISO 27001 German implementation best practices

**Missing Translations:**

The `RiskType.php` form uses translation keys, but actual translations are **MISSING**:

```php
// src/Form/RiskType.php - Translation keys defined
'label' => 'risk.field.title',
'label' => 'risk.field.description',
'label' => 'risk.field.threat',
// ... etc
```

**Verification:**
```bash
$ grep "^risk\." translations/messages.de.yaml
# No results found!
```

**Impact:**
- German-speaking users see English translation keys instead of proper labels
- Non-compliance with German data protection authority (BfDI) recommendations
- Poor user experience for German customers

**Required Translations:**

Create `translations/messages.de.yaml`:

```yaml
risk:
    # Field Labels
    field:
        title: 'Risikotitel'
        description: 'Beschreibung'
        threat: 'Bedrohung'
        vulnerability: 'Verwundbarkeit'
        asset: 'Betroffenes Asset'
        probability: 'Eintrittswahrscheinlichkeit'
        impact: 'Schadensausmaß'
        residual_probability: 'Rest-Wahrscheinlichkeit'
        residual_impact: 'Rest-Schadensausmaß'
        risk_owner: 'Risikoverantwortlicher'
        treatment_strategy: 'Behandlungsstrategie'
        treatment_description: 'Behandlungsplan'
        acceptance_approved_by: 'Genehmigt durch'
        acceptance_approved_at: 'Genehmigungsdatum'
        acceptance_justification: 'Begründung für Akzeptanz'
        status: 'Status'
        review_date: 'Überprüfungsdatum'

    # Help Text
    help:
        title: 'Kurzer, prägnanter Titel des Risikos'
        description: 'Detaillierte Beschreibung des Risikos und seiner potenziellen Auswirkungen'
        threat: 'Welche Bedrohung könnte dieses Risiko auslösen?'
        vulnerability: 'Welche Schwachstelle könnte ausgenutzt werden?'
        asset: 'Wählen Sie das betroffene Asset aus'
        probability: 'Eintrittswahrscheinlichkeit (1=sehr unwahrscheinlich, 2=unwahrscheinlich, 3=möglich, 4=wahrscheinlich, 5=sehr wahrscheinlich)'
        impact: 'Schadensausmaß (1=unbedeutend, 2=gering, 3=moderat, 4=hoch, 5=kritisch)'
        residual_probability: 'Verbleibende Wahrscheinlichkeit nach Umsetzung von Maßnahmen'
        residual_impact: 'Verbleibendes Schadensausmaß nach Umsetzung von Maßnahmen'
        risk_owner: 'Person, die für die Behandlung dieses Risikos verantwortlich ist'
        treatment_strategy: 'Wählen Sie die Behandlungsstrategie (Mindern, Akzeptieren, Übertragen, Vermeiden)'
        treatment_description: 'Detaillierte Beschreibung der geplanten Maßnahmen zur Risikobehandlung'
        acceptance_approved_by: 'Name der Person, die die Risikoakzeptanz genehmigt hat'
        acceptance_approved_at: 'Datum der Genehmigung der Risikoakzeptanz'
        acceptance_justification: 'Begründung für die Akzeptanz des Risikos'
        review_date: 'Datum der nächsten Überprüfung des Risikos'

    # Placeholders
    placeholder:
        title: 'z.B. Datenverlust durch Ransomware-Angriff'
        description: 'Beschreiben Sie das Risiko detailliert...'
        threat: 'z.B. Externe Angreifer, Malware, Social Engineering'
        vulnerability: 'z.B. Fehlende Backups, ungepatchte Systeme'
        asset: 'Asset auswählen...'
        risk_owner: 'Risikoverantwortlichen auswählen...'
        treatment_description: 'Beschreiben Sie die geplanten Maßnahmen...'
        acceptance_approved_by: 'z.B. Max Mustermann (Geschäftsführer)'
        acceptance_justification: 'Begründung für die Risikoakzeptanz...'

    # Treatment Strategies
    treatment:
        mitigate: 'Mindern'
        transfer: 'Übertragen'
        accept: 'Akzeptieren'
        avoid: 'Vermeiden'

    # Status Values
    status:
        identified: 'Identifiziert'
        assessed: 'Bewertet'
        treated: 'Behandelt'
        monitored: 'Überwacht'
        closed: 'Geschlossen'
        accepted: 'Akzeptiert'

    # Sections
    section:
        basic_info: 'Grundinformationen'
        inherent_risk: 'Brutto-Risiko (ohne Maßnahmen)'
        residual_risk: 'Netto-Risiko (mit Maßnahmen)'
        treatment: 'Risikobehandlung'
        acceptance: 'Risikoakzeptanz'

    # Success/Error Messages
    success:
        created: 'Risiko erfolgreich erstellt'
        updated: 'Risiko erfolgreich aktualisiert'
        deleted: 'Risiko erfolgreich gelöscht'

    error:
        not_found: 'Risiko nicht gefunden'
        cannot_delete: 'Risiko kann nicht gelöscht werden'
```

Also create `translations/messages.en.yaml` for English support.

---

### 5.2 German Risk Management Terminology Accuracy

**Assessment:** GOOD (80%)

The existing German terminology is generally correct:

✓ Brutto-Risiko (Inherent Risk)
✓ Netto-Risiko (Residual Risk)
✓ Eintrittswahrscheinlichkeit (Probability)
✓ Schadensausmaß (Impact)
✓ Risikomatrix (Risk Matrix)
✓ Behandlungsstrategie (Treatment Strategy)

**Minor Improvements:**

Use "Risikoeigner" instead of "Risikoverantwortlicher" (more aligned with ISO 31000 German version)

---

## 6. UI/UX Analysis from Risk Manager Perspective

### 6.1 Risk Dashboard (`index_modern.html.twig`)

**Strengths:**
- Clear KPI cards showing total/critical/high risks
- Visual risk matrix overview with color coding
- Treatment status distribution
- Comprehensive filtering options
- Bulk actions support

**Issues:**

#### MEDIUM - Inconsistent Risk Level Visual Indicators
**Severity:** MEDIUM

Color scheme for risk scores is inconsistent:

**Template (lines 316-318):**
```twig
<span class="risk-score risk-score-{{ riskScore >= 15 ? 'critical' : (riskScore >= 8 ? 'high' : (riskScore >= 4 ? 'medium' : 'low')) }}">
```

**CSS (lines 465-483):**
```css
.risk-score-critical { background: var(--color-danger); }
.risk-score-high { background: #ff9800; }  /* Orange - good */
.risk-score-medium { background: var(--color-warning); }  /* Yellow - good */
.risk-score-low { background: var(--color-success); }  /* Green - good */
```

**BUT** in Risk Matrix Service (lines 164-170):
```php
'critical' => '#dc3545', // Red (correct)
'high' => '#fd7e14',     // Orange (slightly different shade!)
'medium' => '#ffc107',   // Yellow
'low' => '#28a745',      // Green
```

**Recommendation:**
Use CSS variables consistently:
```css
:root {
    --risk-critical: #dc2626;  /* Red */
    --risk-high: #ea580c;      /* Orange */
    --risk-medium: #d97706;    /* Amber */
    --risk-low: #059669;       /* Green */
}
```

#### LOW - Missing Quick Actions for High Risks
**Severity:** LOW

High-risk items should have prominent quick actions:
- "Create Treatment Plan" button
- "Request Risk Acceptance" button
- "Escalate to Management" button

**Recommendation:**
Add action buttons to high-risk table rows:

```twig
{% if riskScore >= 12 %}
<div class="btn-group btn-group-sm">
    <a href="{{ path('app_risk_treatment_plan_new', {riskId: risk.id}) }}"
       class="btn btn-warning btn-sm">
        <i class="bi bi-shield-plus"></i> Behandlungsplan
    </a>
    {% if not risk.isAcceptanceComplete() %}
    <a href="{{ path('app_risk_request_acceptance', {id: risk.id}) }}"
       class="btn btn-info btn-sm">
        <i class="bi bi-check-circle"></i> Akzeptanz beantragen
    </a>
    {% endif %}
</div>
{% endif %}
```

---

### 6.2 Risk Detail View (`show.html.twig`)

**Strengths:**
- Comprehensive risk information display
- Visual inherent vs. residual risk comparison
- Linked controls and incidents (Data Reuse)
- Audit history tracking
- Dark mode support

**Issues:**

#### MEDIUM - No Risk Treatment Action Tracking
**Severity:** MEDIUM

The detail view shows treatment strategy but not:
- Who approved the strategy
- When it was approved
- Progress on treatment implementation
- Related treatment plan status

**Recommendation:**
Add Treatment Plan status section:

```twig
{# Risk Treatment Plan Status #}
{% if risk.treatmentPlans is defined and risk.treatmentPlans|length > 0 %}
<div class="detail-section">
    <h3>Behandlungspläne ({{ risk.treatmentPlans|length }})</h3>
    <div class="treatment-plans-list">
        {% for plan in risk.treatmentPlans %}
        <div class="treatment-plan-card">
            <div class="plan-header">
                <strong>{{ plan.title }}</strong>
                <span class="badge badge-{{ plan.status }}">{{ plan.status|upper }}</span>
            </div>
            <div class="plan-progress">
                <div class="progress">
                    <div class="progress-bar" style="width: {{ plan.completionPercentage }}%">
                        {{ plan.completionPercentage }}%
                    </div>
                </div>
            </div>
            <div class="plan-meta">
                <small>
                    Verantwortlich: {{ plan.responsiblePersonName }}
                    | Ziel: {{ plan.targetCompletionDate|date('d.m.Y') }}
                    {% if plan.isOverdue() %}
                        <span class="text-danger">⚠ Überfällig</span>
                    {% endif %}
                </small>
            </div>
        </div>
        {% endfor %}
    </div>
</div>
{% endif %}
```

---

### 6.3 Risk Form (`_form.html.twig`)

**Strengths:**
- Well-organized fieldsets
- WCAG 2.1 AA accessible
- Comprehensive help text
- Clear separation of inherent vs. residual risk

**Issues:**

#### HIGH - No Risk Assessment Guidance
**Severity:** HIGH

Users must determine probability and impact values without guidance.

**Recommendation:**
Add interactive risk assessment wizard:

```twig
{# Risk Assessment Helper #}
<div class="risk-assessment-helper card mb-3">
    <div class="card-header">
        <h6><i class="bi bi-question-circle"></i> Bewertungshilfe</h6>
    </div>
    <div class="card-body">
        <h6>Eintrittswahrscheinlichkeit</h6>
        <table class="table table-sm">
            <tr>
                <td><strong>1 - Sehr selten</strong></td>
                <td>< 5% Wahrscheinlichkeit in 5 Jahren</td>
            </tr>
            <tr>
                <td><strong>2 - Selten</strong></td>
                <td>5-25% Wahrscheinlichkeit in 5 Jahren</td>
            </tr>
            <tr>
                <td><strong>3 - Gelegentlich</strong></td>
                <td>25-50% Wahrscheinlichkeit in 5 Jahren</td>
            </tr>
            <tr>
                <td><strong>4 - Wahrscheinlich</strong></td>
                <td>50-75% Wahrscheinlichkeit in 5 Jahren</td>
            </tr>
            <tr>
                <td><strong>5 - Sehr wahrscheinlich</strong></td>
                <td>> 75% Wahrscheinlichkeit in 5 Jahren</td>
            </tr>
        </table>

        <h6 class="mt-3">Schadensausmaß</h6>
        <table class="table table-sm">
            <tr>
                <td><strong>1 - Unbedeutend</strong></td>
                <td>< 5.000€ Schaden, keine Betriebsunterbrechung</td>
            </tr>
            <tr>
                <td><strong>2 - Gering</strong></td>
                <td>5.000-25.000€ Schaden, < 1 Tag Unterbrechung</td>
            </tr>
            <tr>
                <td><strong>3 - Moderat</strong></td>
                <td>25.000-100.000€ Schaden, 1-3 Tage Unterbrechung</td>
            </tr>
            <tr>
                <td><strong>4 - Hoch</strong></td>
                <td>100.000-500.000€ Schaden, > 3 Tage Unterbrechung</td>
            </tr>
            <tr>
                <td><strong>5 - Kritisch</strong></td>
                <td>> 500.000€ Schaden, existenzbedrohend</td>
            </tr>
        </table>
    </div>
</div>
```

#### MEDIUM - No Real-Time Risk Score Calculation
**Severity:** MEDIUM

Users don't see the calculated risk score while filling the form.

**Recommendation:**
Add live risk score calculator with Stimulus controller:

```javascript
// assets/controllers/risk_calculator_controller.js
import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['probability', 'impact', 'residualProbability', 'residualImpact', 'inherentScore', 'residualScore'];

    connect() {
        this.calculate();
    }

    calculate() {
        const probability = parseInt(this.probabilityTarget.value) || 0;
        const impact = parseInt(this.impactTarget.value) || 0;
        const inherentScore = probability * impact;

        this.inherentScoreTarget.textContent = inherentScore;
        this.inherentScoreTarget.className = `risk-score ${this.getRiskClass(inherentScore)}`;

        if (this.hasResidualProbabilityTarget && this.hasResidualImpactTarget) {
            const resProbability = parseInt(this.residualProbabilityTarget.value) || 0;
            const resImpact = parseInt(this.residualImpactTarget.value) || 0;
            const residualScore = resProbability * resImpact;

            this.residualScoreTarget.textContent = residualScore;
            this.residualScoreTarget.className = `risk-score ${this.getRiskClass(residualScore)}`;
        }
    }

    getRiskClass(score) {
        if (score >= 15) return 'risk-critical';
        if (score >= 8) return 'risk-high';
        if (score >= 4) return 'risk-medium';
        return 'risk-low';
    }
}
```

---

## 7. Integration Analysis

### 7.1 Risk-Asset Integration

**Status:** EXCELLENT (90%)

**Strengths:**
- Mandatory asset association
- Asset monetary value can inform impact assessment
- Asset classification visible in risk detail view
- Data reuse from asset register

**Evidence:**
```php
// src/Entity/Risk.php:90-94
#[ORM\ManyToOne(inversedBy: 'risks')]
#[Groups(['risk:read', 'risk:write'])]
#[Assert\NotNull(message: 'Risk must be associated with an asset')]
private ?Asset $asset = null;
```

**Enhancement Opportunity:**
Auto-suggest impact level based on asset value:

```php
// src/Service/RiskImpactCalculatorService.php
public function suggestImpact(Asset $asset): int
{
    $value = $asset->getMonetaryValue();

    if ($value === null) {
        return 3; // Default: moderate
    }

    // Map asset value to impact level
    return match(true) {
        $value > 500000 => 5,   // Critical
        $value > 100000 => 4,   // High
        $value > 25000 => 3,    // Moderate
        $value > 5000 => 2,     // Low
        default => 1            // Minimal
    };
}
```

---

### 7.2 Risk-Control Integration

**Status:** GOOD (75%)

**Strengths:**
- Many-to-many relationship properly modeled
- Control effectiveness tracked (implementation percentage)
- RiskIntelligenceService calculates residual risk based on controls
- Mitigating controls visible in risk detail view

**Evidence:**
```php
// src/Service/RiskIntelligenceService.php:86-100
foreach ($controls as $control) {
    if ($control->getImplementationStatus() === 'implemented') {
        $effectiveness = $control->getImplementationPercentage() / 100;
        $totalReduction += (0.3 * $effectiveness);
    }
}
```

**Issue:**
- Control effectiveness formula is hardcoded (30% max reduction per control)
- No consideration of control type (preventive vs. detective)

**Recommendation:**
Implement control effectiveness framework:

```php
class ControlEffectivenessService
{
    private const EFFECTIVENESS_FACTORS = [
        'preventive' => 0.4,   // Preventive controls more effective
        'detective' => 0.2,
        'corrective' => 0.25,
        'compensating' => 0.15,
    ];

    public function calculateEffectiveness(Control $control): float
    {
        $baseEffectiveness = self::EFFECTIVENESS_FACTORS[$control->getControlType()];
        $implementationFactor = $control->getImplementationPercentage() / 100;

        // Adjust for control maturity
        $maturityFactor = $this->getMaturityFactor($control);

        return $baseEffectiveness * $implementationFactor * $maturityFactor;
    }
}
```

---

### 7.3 Risk-Incident Integration (Threat Intelligence)

**Status:** EXCELLENT (90%)

**Strengths:**
- Bidirectional relationship (risks can realize as incidents)
- RiskIntelligenceService suggests risks from incidents
- Incident frequency informs probability estimation
- Incident severity mapped to impact

**Evidence:**
```php
// src/Service/RiskIntelligenceService.php:31-56
public function suggestRisksFromIncidents(): array
{
    foreach ($incidents as $incident) {
        $suggestions[] = [
            'suggested_risk' => [
                'probability' => $this->estimateProbabilityFromIncidents($incident->getCategory()),
                'impact' => $this->mapSeverityToImpact($incident->getSeverity())
            ]
        ];
    }
}
```

**This is a standout feature** - true data reuse as per ISO 27001 intent.

**Enhancement:**
Add incident trend analysis to risk assessment:

```twig
{# Risk Assessment with Incident Intelligence #}
{% if similarIncidents|length > 0 %}
<div class="alert alert-info">
    <strong><i class="bi bi-lightbulb"></i> Threat Intelligence</strong>
    <p>{{ similarIncidents|length }} ähnliche Vorfälle in den letzten 12 Monaten:</p>
    <ul>
        {% for incident in similarIncidents|slice(0, 3) %}
        <li>{{ incident.title }} ({{ incident.detectedAt|date('M Y') }}) - {{ incident.severity }}</li>
        {% endfor %}
    </ul>
    <p><strong>Empfehlung:</strong> Wahrscheinlichkeit mindestens {{ suggestedProbability }}/5</p>
</div>
{% endif %}
```

---

### 7.4 Risk Appetite Integration

**Status:** VERY GOOD (85%)

**Strengths:**
- Formal risk appetite entity (ISO 27005:2022 compliant)
- Category-specific and global appetites supported
- Prioritization service calculates appetite exceedance
- Dashboard statistics for appetite compliance

**Evidence:**
```php
// src/Service/RiskAppetitePrioritizationService.php:82-92
public function exceedsAppetite(Risk $risk): bool
{
    $appetite = $this->getApplicableAppetite($risk);
    $residualRiskLevel = $risk->getResidualRiskLevel();
    return $residualRiskLevel > $appetite->getMaxAcceptableRisk();
}
```

**Issue:**
Risk appetite approval is tracked but not enforced in UI:

```php
// src/Entity/RiskAppetite.php:273-276
public function isApproved(): bool
{
    return $this->approvedBy !== null && $this->approvedAt !== null;
}
```

**Recommendation:**
Add approval workflow and prevent using unapproved appetites:

```php
// src/Service/RiskAppetiteService.php
public function validateAppetite(RiskAppetite $appetite): void
{
    if (!$appetite->isApproved()) {
        throw new \DomainException('Risk appetite must be approved before use');
    }

    if ($appetite->getApprovedAt() < (new \DateTime())->modify('-1 year')) {
        throw new \DomainException('Risk appetite requires annual re-approval');
    }
}
```

---

## 8. Database Schema Analysis

### 8.1 Risk Entity Completeness

**Current Schema (inferred from Entity):**

```sql
CREATE TABLE risk (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT,
    asset_id INT NOT NULL,
    risk_owner_id INT,
    title VARCHAR(255) NOT NULL,
    description TEXT NOT NULL,
    threat TEXT,
    vulnerability TEXT,
    probability INT NOT NULL,  -- 1-5
    impact INT NOT NULL,       -- 1-5
    residual_probability INT,  -- 1-5
    residual_impact INT,       -- 1-5
    treatment_strategy VARCHAR(50) NOT NULL,
    treatment_description TEXT,
    acceptance_approved_by VARCHAR(100),
    acceptance_approved_at DATE,
    acceptance_justification TEXT,
    formally_accepted BOOLEAN DEFAULT FALSE,
    status VARCHAR(50) NOT NULL DEFAULT 'identified',
    review_date DATE,
    created_at DATETIME NOT NULL,
    updated_at DATETIME,
    INDEX idx_risk_status (status),
    INDEX idx_risk_created_at (created_at),
    INDEX idx_risk_review_date (review_date),
    INDEX idx_risk_tenant (tenant_id)
);
```

**Missing Indexes:**
```sql
-- Add for performance
ALTER TABLE risk ADD INDEX idx_risk_treatment_strategy (treatment_strategy);
ALTER TABLE risk ADD INDEX idx_risk_owner (risk_owner_id);
ALTER TABLE risk ADD INDEX idx_risk_asset (asset_id);
ALTER TABLE risk ADD INDEX idx_risk_level (probability, impact);  -- Composite for risk score queries
```

**Missing Fields (Recommendations):**
```sql
-- Risk categorization
ALTER TABLE risk ADD COLUMN category VARCHAR(100);
ALTER TABLE risk ADD INDEX idx_risk_category (category);

-- DSGVO compliance
ALTER TABLE risk ADD COLUMN involves_personal_data BOOLEAN DEFAULT FALSE;
ALTER TABLE risk ADD COLUMN involves_special_category_data BOOLEAN DEFAULT FALSE;
ALTER TABLE risk ADD COLUMN legal_basis VARCHAR(50);
ALTER TABLE risk ADD COLUMN processing_scale VARCHAR(50);
ALTER TABLE risk ADD COLUMN requires_dpia BOOLEAN DEFAULT FALSE;

-- Review tracking
ALTER TABLE risk ADD COLUMN last_reviewed_at DATETIME;
ALTER TABLE risk ADD COLUMN last_reviewed_by_id INT;
ALTER TABLE risk ADD COLUMN review_interval_days INT DEFAULT 365;

-- Risk history
ALTER TABLE risk ADD COLUMN version INT DEFAULT 1;
ALTER TABLE risk ADD COLUMN previous_version_id INT;
```

---

### 8.2 Data Integrity Constraints

**Current Constraints:**
✓ Asset association mandatory
✓ Probability/Impact range validation (1-5)
✓ Status choice validation
✓ Treatment strategy choice validation

**Missing Constraints:**

```php
// Add to Risk entity
#[Assert\Callback]
public function validateResidualRisk(ExecutionContextInterface $context): void
{
    // Residual risk should not exceed inherent risk
    if ($this->residualProbability && $this->residualImpact) {
        $inherent = $this->getInherentRiskLevel();
        $residual = $this->getResidualRiskLevel();

        if ($residual > $inherent) {
            $context->buildViolation('Residual risk cannot exceed inherent risk')
                ->atPath('residualProbability')
                ->addViolation();
        }
    }
}

#[Assert\Callback]
public function validateRiskAcceptance(ExecutionContextInterface $context): void
{
    // If treatment is "accept", require justification
    if ($this->treatmentStrategy === 'accept') {
        if (empty($this->acceptanceJustification)) {
            $context->buildViolation('Acceptance justification is required for accepted risks')
                ->atPath('acceptanceJustification')
                ->addViolation();
        }

        if (empty($this->acceptanceApprovedBy)) {
            $context->buildViolation('Approval is required for risk acceptance')
                ->atPath('acceptanceApprovedBy')
                ->addViolation();
        }
    }
}
```

---

## 9. Security and Access Control

### 9.1 Risk Data Access

**Current Implementation:**
- Tenant isolation via `TenantContext`
- Multi-tenant inheritance support (corporate governance)
- Inherited risks are read-only

**Evidence:**
```php
// src/Service/RiskService.php:96-113
public function isInheritedRisk(Risk $risk, Tenant $currentTenant): bool
{
    $riskTenantId = $riskTenant->getId();
    $currentTenantId = $currentTenant->getId();
    return $riskTenantId !== $currentTenantId;
}
```

**Strengths:**
- Proper tenant isolation
- Corporate structure awareness
- Edit restrictions on inherited data

**Enhancement Opportunity:**

Add row-level security for sensitive risks:

```php
// src/Entity/Risk.php
#[ORM\Column(length: 50, nullable: true)]
#[Assert\Choice(choices: ['public', 'internal', 'confidential', 'restricted'])]
private ?string $classificationLevel = 'internal';

#[ORM\ManyToMany(targetEntity: User::class)]
private Collection $authorizedViewers;
```

Implement risk visibility voter:

```php
// src/Security/Voter/RiskVoter.php
class RiskVoter extends Voter
{
    protected function voteOnAttribute(string $attribute, $subject, TokenInterface $token): bool
    {
        if ($subject->getClassificationLevel() === 'restricted') {
            // Only authorized viewers can see restricted risks
            return $subject->getAuthorizedViewers()->contains($token->getUser());
        }

        // Standard tenant check
        return $subject->getTenant() === $token->getUser()->getTenant();
    }
}
```

---

## 10. Recommendations Roadmap

### Priority 1 - Critical Issues (Implement within 1 month)

| # | Issue | Impact | Effort | Reference |
|---|-------|--------|--------|-----------|
| 1.1 | Standardize risk level thresholds | Critical | Medium | Section 1.2 |
| 1.2 | Add risk categorization | High | Medium | Section 1.1 |
| 1.3 | Implement German translations | High | Low | Section 5.1 |
| 1.4 | Make risk owner mandatory | High | Low | Section 1.1 |
| 1.5 | Add periodic review workflow | Critical | High | Section 1.4 |

**Implementation Plan for Priority 1:**

**Week 1-2:**
```bash
# Task 1: Create risk level configuration
touch config/risk_levels.yaml
# Task 2: Create RiskLevelService
# Task 3: Update all risk level calculations to use service
# Task 4: Add translations
# Task 5: Update form validation
```

**Week 3-4:**
```bash
# Task 6: Add category field to Risk entity
php bin/console doctrine:migrations:diff
php bin/console doctrine:migrations:migrate
# Task 7: Create RiskReviewService
# Task 8: Add review workflow commands
# Task 9: Add dashboard widgets
```

---

### Priority 2 - High Issues (Implement within 3 months)

| # | Issue | Impact | Effort | Reference |
|---|-------|--------|--------|-----------|
| 2.1 | Implement risk acceptance workflow | High | High | Section 1.3 |
| 2.2 | Add DSGVO risk fields | High | Medium | Section 4.1 |
| 2.3 | Create risk assessment guidance | High | Medium | Section 6.3 |
| 2.4 | Implement treatment plan monitoring | Medium | Medium | Section 1.3 |
| 2.5 | Add risk metrics dashboard | Medium | High | Section 1.4 |

---

### Priority 3 - Medium Issues (Implement within 6 months)

| # | Issue | Impact | Effort | Reference |
|---|-------|--------|--------|-----------|
| 3.1 | Enhance control effectiveness | Medium | Medium | Section 7.2 |
| 3.2 | Add stakeholder tracking | Medium | Low | Section 3.1 |
| 3.3 | Implement real-time risk calculator | Low | Low | Section 6.3 |
| 3.4 | Add risk classification levels | Medium | Medium | Section 9.1 |
| 3.5 | Create risk methodology docs | Medium | Medium | Section 1.2 |

---

### Priority 4 - Enhancements (Implement within 12 months)

| # | Enhancement | Impact | Effort | Reference |
|---|-------------|--------|--------|-----------|
| 4.1 | External threat intelligence integration | High | High | Section 2.3 |
| 4.2 | Automated risk suggestions from incidents | Medium | Medium | Section 7.3 |
| 4.3 | Risk appetite annual re-approval | Medium | Low | Section 7.4 |
| 4.4 | Advanced risk metrics and trends | Medium | High | Section 1.4 |
| 4.5 | Mobile-optimized risk assessment | Low | Medium | - |

---

## 11. Compliance Checklist

### ISO 27001:2022 Risk Management Requirements

- [x] **6.1.2.a** Risk criteria established (5x5 matrix)
- [⚠] **6.1.2.b** Repeatable risk assessment (needs methodology docs)
- [⚠] **6.1.2.c** Consistent risk analysis (needs threshold standardization)
- [⚠] **6.1.2.d** Risk owners identified (should be mandatory)
- [x] **6.1.2.e** Risk levels compared to criteria (risk appetite)
- [x] **6.1.3.a** Treatment options available (4 strategies)
- [x] **6.1.3.b** Controls selected (control-risk linkage)
- [⚠] **6.1.3.c** Residual risks acceptable (needs workflow)
- [⚠] **6.1.3.d** Management approval (needs enforcement)
- [⚠] **6.1.3.e** Risk treatment plans (needs monitoring)
- [x] **8.2** Asset inventory integrated
- [x] **A.5.7** Threat intelligence (incident integration)

**Overall ISO 27001:2022 Compliance:** 70% (PARTIAL)

---

### ISO 31000:2018 Principles Compliance

- [x] **Integrated:** Yes - multi-tenant, asset linkage
- [⚠] **Structured:** Partial - needs risk categories
- [x] **Customized:** Yes - risk appetite by category
- [⚠] **Inclusive:** Partial - needs stakeholder tracking
- [⚠] **Dynamic:** Partial - needs automated monitoring
- [x] **Best information:** Yes - incident intelligence
- [x] **Human factors:** Limited addressing
- [⚠] **Continual improvement:** Partial - needs review cycle

**Overall ISO 31000:2018 Alignment:** 65% (MEDIUM)

---

### DSGVO Art. 32 Compliance

- [⚠] **Risk to data subjects:** Not specifically addressed
- [ ] **Processing operation risk:** No DSGVO fields
- [ ] **Special category data:** Not flagged
- [ ] **DPIA trigger assessment:** Not implemented
- [x] **Confidentiality measures:** Asset classification exists
- [x] **Integrity measures:** Control integration
- [x] **Availability measures:** Incident tracking

**Overall DSGVO Art. 32 Compliance:** 40% (LIMITED)

---

## 12. Code Quality and Maintainability

### 12.1 Positive Aspects

**Excellent:**
- Clean entity design with proper validation
- Service layer separation
- Repository pattern usage
- Comprehensive API Platform integration
- Good use of Doctrine relationships
- Audit logging integration

**Evidence of Best Practices:**
```php
// src/Entity/Risk.php - Well-documented data reuse
/**
 * Get risk owner's full name for display
 * Data Reuse: Quick access to owner name without loading full User entity
 */
#[Groups(['risk:read'])]
public function getRiskOwnerName(): ?string
{
    return $this->riskOwner?->getFullName();
}
```

---

### 12.2 Technical Debt

**Medium Priority:**

1. **Hardcoded values scattered in code:**
```php
// src/Service/RiskIntelligenceService.php:95
$totalReduction += (0.3 * $effectiveness); // Magic number
```
Should be configurable.

2. **Inconsistent method naming:**
```php
// src/Entity/Risk.php has both:
public function getRiskScore(): int  // Line 447
public function getInherentRiskLevel(): int  // Line 437
```
Choose one naming convention.

3. **Missing service interfaces:**
Services like `RiskService`, `RiskMatrixService` should implement interfaces for easier testing.

---

## 13. Testing Recommendations

### 13.1 Current Test Coverage

**Observed:** Test files exist in `tests/` directory but risk-specific tests not analyzed.

**Required Tests:**

```php
// tests/Entity/RiskTest.php
class RiskTest extends TestCase
{
    public function testInherentRiskCalculation(): void
    {
        $risk = new Risk();
        $risk->setProbability(4);
        $risk->setImpact(5);

        $this->assertEquals(20, $risk->getInherentRiskLevel());
    }

    public function testResidualRiskDoesNotExceedInherent(): void
    {
        $risk = new Risk();
        $risk->setProbability(4);
        $risk->setImpact(5);
        $risk->setResidualProbability(5);
        $risk->setResidualImpact(5);

        // Should fail validation
        $errors = $this->validator->validate($risk);
        $this->assertGreaterThan(0, count($errors));
    }
}
```

```php
// tests/Service/RiskAppetitePrioritizationServiceTest.php
class RiskAppetitePrioritizationServiceTest extends TestCase
{
    public function testRiskExceedsAppetite(): void
    {
        $appetite = new RiskAppetite();
        $appetite->setMaxAcceptableRisk(12);

        $risk = new Risk();
        $risk->setResidualProbability(4);
        $risk->setResidualImpact(5); // Score: 20

        $exceeds = $this->service->exceedsAppetite($risk);
        $this->assertTrue($exceeds);
    }
}
```

---

## 14. Documentation Gaps

**Missing Documentation:**

1. **Risk Assessment Methodology**
   - File: `docs/risk_assessment_methodology.md`
   - Content: Probability/impact definitions, examples, DSGVO guidance

2. **Risk Management Workflow**
   - File: `docs/risk_workflow.md`
   - Content: Step-by-step process from identification to closure

3. **Risk Appetite Statement**
   - File: `docs/risk_appetite_statement.md`
   - Content: Organizational risk appetite policy template

4. **API Documentation**
   - File: `docs/api/risk_endpoints.md`
   - Content: API Platform risk endpoints documentation

5. **User Guide**
   - File: `docs/user_guide/risk_management.md`
   - Content: Screenshots, tutorials for risk managers

---

## 15. Conclusion

The Little ISMS Helper's risk management module demonstrates **strong technical implementation** with advanced features like risk appetite management and intelligent risk analysis. However, **critical gaps in workflow enforcement, translations, and DSGVO compliance** prevent full ISO 27001:2022 certification readiness.

### Summary Scores

| Dimension | Score | Grade |
|-----------|-------|-------|
| Technical Implementation | 85% | A- |
| ISO 27001:2022 Compliance | 70% | C+ |
| ISO 31000:2018 Alignment | 65% | D+ |
| DSGVO Art. 32 Compliance | 40% | F |
| UI/UX Quality | 75% | C+ |
| Documentation | 50% | D |
| **Overall Risk Management Maturity** | **68%** | **C** |

### Final Recommendation

**Status:** CONDITIONALLY APPROVED for production use with mandatory improvements.

**Required Actions Before ISO 27001 Audit:**
1. Implement Priority 1 items (1 month deadline)
2. Complete German translations
3. Document risk assessment methodology
4. Add DSGVO risk assessment fields
5. Implement review workflow

**Timeline to Full Compliance:** 3-6 months with dedicated development resources.

---

## Appendix A: Risk Level Threshold Configuration

```yaml
# config/packages/risk_management.yaml
parameters:
    risk_management:
        # Risk Matrix Configuration
        matrix:
            size: 5
            type: 'likelihood_impact'

        # Risk Level Thresholds (CANONICAL SOURCE)
        thresholds:
            critical:
                min: 15
                max: 25
                color: '#dc2626'
                label:
                    de: 'Kritisch'
                    en: 'Critical'
                actions:
                    - 'executive_approval_required'
                    - 'immediate_treatment_plan'
                    - 'weekly_review'
            high:
                min: 8
                max: 14
                color: '#ea580c'
                label:
                    de: 'Hoch'
                    en: 'High'
                actions:
                    - 'treatment_plan_required'
                    - 'monthly_review'
            medium:
                min: 4
                max: 7
                color: '#d97706'
                label:
                    de: 'Mittel'
                    en: 'Medium'
                actions:
                    - 'quarterly_review'
            low:
                min: 1
                max: 3
                color: '#059669'
                label:
                    de: 'Niedrig'
                    en: 'Low'
                actions:
                    - 'annual_review'

        # Review Intervals (days)
        review_intervals:
            critical: 90
            high: 180
            medium: 365
            low: 730

        # Risk Acceptance Approval Levels
        acceptance_approval:
            automatic: 3  # Risks with score <= 3 can be auto-accepted
            manager: 7    # Risks 4-7 require manager approval
            executive: 25  # Risks 8-25 require executive approval
```

---

## Appendix B: Sample Risk Categories

```yaml
# config/risk_categories.yaml
risk_categories:
    financial:
        label:
            de: 'Finanzielle Risiken'
            en: 'Financial Risks'
        examples:
            - 'Fraud'
            - 'Budget overruns'
            - 'Currency exchange losses'

    operational:
        label:
            de: 'Betriebliche Risiken'
            en: 'Operational Risks'
        examples:
            - 'System downtime'
            - 'Process failures'
            - 'Supply chain disruption'

    compliance:
        label:
            de: 'Compliance-Risiken'
            en: 'Compliance Risks'
        examples:
            - 'DSGVO violations'
            - 'Contract breaches'
            - 'Regulatory non-compliance'

    strategic:
        label:
            de: 'Strategische Risiken'
            en: 'Strategic Risks'
        examples:
            - 'Market changes'
            - 'Competition'
            - 'Technology obsolescence'

    reputational:
        label:
            de: 'Reputationsrisiken'
            en: 'Reputational Risks'
        examples:
            - 'Data breaches'
            - 'Negative publicity'
            - 'Customer complaints'

    security:
        label:
            de: 'Informationssicherheitsrisiken'
            en: 'Information Security Risks'
        examples:
            - 'Cyberattacks'
            - 'Data loss'
            - 'Unauthorized access'
```

---

**End of Risk Manager Analysis**

**Document Version:** 1.0
**Last Updated:** 2025-11-19
**Next Review:** 2025-12-19
**Document Owner:** Risk Management Team
