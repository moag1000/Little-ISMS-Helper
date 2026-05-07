# FormType Norm-Gating Rollout — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make all 68 FormTypes module-aware so users only see fields relevant to their active modules + frameworks. Close audit-critical norm-mandated field gaps. Implement security-critical fixes (privilege escalation, SSRF). Add DORA/NIS2/MaRisk required fields where regulatorily mandatory.

**Architecture:** Direct on `main`. Atomic conventional commits. Integrate findings from 6 specialists (DPO, BSI, BCM, Risk-Mgmt, Pentester, ISMS) consolidated by Compliance-Manager. Module-Service-Injection in FormTypes. Stimulus-conditional sub-section visibility based on field-trigger values. Library-format YAML for cross-framework mappings.

**Tech Stack:** Symfony 7.4, PHP 8.4+, Doctrine ORM 3.6, Twig 3.24, Stimulus 3.2, ModuleConfigurationService (existing), is_module_active() Twig function (existing).

**Specialist-Reviews captured in:** session 2026-05-07. Compliance-Manager final consolidation: skip 14 new FormTypes (use Data-Reuse), consolidate 17 module-keys → 8.

---

## Operational Rules

**Lint-Gate vor jedem Commit (zwingend):**
```bash
npm run stylelint && \
php bin/console lint:twig templates/ && \
php bin/console lint:container && \
find src -name "*.php" -print0 | xargs -0 -n1 php -l >/dev/null 2>&1
```

**Test-Gate (Sprint 1+2 — wo Tests existieren):**
```bash
php bin/phpunit --no-coverage --filter=<TestClass> 2>&1 | tail -5
```

**Decision-Template:** Compliance-Manager-Output ist verbindlich. Bei Konflikt zwischen Specialist-Empfehlung und Compliance-Manager: **Compliance-Manager wins** (Effizienz vor Vollständigkeit).

**Commit-Format:** Conventional Commits, Footer:
```
Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
```

**STOP-Bedingung:** Bei sichtbarem Visual-Regress, Test-Failure, oder unerwartetem Voter-Block: STOPP, melden, auf Entscheidung warten.

---

## Module-Konsolidierung (Compliance-Manager-Entscheidung)

**8 neue Module-Keys** statt 17 (Pre-Sprint-1 in `config/modules.yaml`):

| Modul-Key | Konsolidiert | Aktivierungs-Trigger Setup-Wizard |
|---|---|---|
| `privacy` | gdpr + iso_27701 | "Verarbeiten Sie personenbezogene Daten?" |
| `nis2_dora` | nis2 + dora | "EU-Cyber-Resilience-pflichtig (KRITIS/Bank/Vers)?" |
| `ai_governance` | eu_ai_act + iso_42001 | "Setzen Sie KI-Systeme ein?" |
| `cloud_security` | iso_27017 + iso_27018 + bsi_aic4 + bsi_c5 | "Cloud-Provider/User?" |
| `vulnerability_intel` | vulnerability_management + threat_intelligence + mitre_attack | "Aktives Vuln-/Threat-Mgmt?" |
| `marisk` | MaRisk (DORA-parallel) | "Bank/Versicherung in DACH?" |
| `tisax` | TISAX | "Auto-Industrie-Lieferkette?" |
| `quantitative_risk` | FAIR/CRQ | "Quantitative Risk-Analyse gewünscht?" |

**Bestehend (12 unverändert):** core, assets, risks, controls, incidents, audits, training, reviews, bcm, compliance, authentication, audit_logging.

**Plus bestehend separat:** `bsi_grundschutz` (eigenständig, BSI IT-Grundschutz-Methodik).

**Total: 12 bestehend + 8 neu + 1 separat = 21 Module-Keys.**

---

## File Structure

**Tokens / Config:**
- `config/modules.yaml` — 8 neue Module-Keys
- `config/services.yaml` — ModuleConfigurationService Auto-Wiring (bereits aktiv)
- `config/active_modules.yaml` — pro Tenant (bereits aktiv)

**FormTypes (modify, no new):**
- `src/Form/RiskType.php` — Justifikation, GDPR-Subset-Gating, DORA-Subset, Voter
- `src/Form/AssetType.php` — AI-Agent-Gating, prohibited-Validation
- `src/Form/IncidentType.php` — DORA-Klassifizierung, Evidence, Containment
- `src/Form/DataBreachType.php` — Whole-Form-Gating
- `src/Form/ConsentType.php` — withdrawnAt
- `src/Form/DataSubjectRequestType.php` — responseAt + Frist-Felder
- `src/Form/ProcessingActivityType.php` — Whole-Form-Gating
- `src/Form/DataProtectionImpactAssessmentType.php` — Whole-Form-Gating
- `src/Form/BusinessContinuityPlanType.php` — responseTeamMembers, requiredResources
- `src/Form/CrisisTeamType.php` — members JSON, emergencyContacts
- `src/Form/BCExerciseType.php` — successCriteria, actualRtoAchieved/Rpo
- `src/Form/ManagementReviewType.php` — topManagementAttended, nextReviewDate
- `src/Form/IdentityProviderType.php` — SSRF-Validation
- `src/Form/PatchType.php` — SSRF-Validation
- `src/Form/CrisisTeamType.php` — virtualMeetingUrl SSRF-Validation
- `src/Form/Admin/TenantEmailBrandingType.php` — emailLogoUrl SSRF
- `src/Form/AuditFindingType.php` — source-Klassifikator
- `src/Form/CorrectiveActionType.php` — actionType-Klassifikator
- `src/Form/ChangeRequestType.php` — Tag-Felder
- `src/Form/SupplierType.php` — MaRisk-Subset (Sprint 7)
- `src/Form/ControlType.php` — effectiveness, controlType, controlMaturity (Sprint 6)
- `src/Form/RiskAcceptanceType.php` od. RiskType — acceptanceType-Klassifikator

**Entities (Migrations + Setter):**
- `src/Entity/Risk.php` — likelihoodJustification, impactJustification (TEXT) + decisionApprovedByUser/decisionApprovalDate/decisionRationale
- `src/Entity/Consent.php` — withdrawnAt (DATETIME)
- `src/Entity/DataSubjectRequest.php` — responseAt + extendedDeadline + extensionReason + responseDocument
- `src/Entity/Incident.php` — DORA-Felder (12)
- `src/Entity/Asset.php` — Validation-Constraint prohibited+active
- `src/Entity/BusinessContinuityPlan.php` — responseTeamMembers, requiredResources (JSON)
- `src/Entity/CrisisTeam.php` — members, emergencyContacts (JSON)
- `src/Entity/BCExercise.php` — successCriteria, actualRtoAchieved, actualRpoAchieved
- `src/Entity/ManagementReview.php` — topManagementAttended, nextReviewDate, meetingMinutesDocument
- `src/Entity/AuditFinding.php` — source enum
- `src/Entity/CorrectiveAction.php` — actionType enum
- `src/Entity/Supplier.php` — MaRisk-Felder (Sprint 7)
- `src/Entity/Control.php` — effectiveness, controlType, controlMaturity (Sprint 6)

**Migrations:**
- `migrations/Version20260507100000_formtype_audit_norm_fields.php` — Sprint 1+2 fields (alle in einer Migration per CLAUDE.md memory `feedback_migration_consolidation`)
- `migrations/Version20260507100001_formtype_audit_long_tail.php` — Sprint 3-7 fields (Sprint-end-Migration)
- Beide MÜSSEN `isTransactional()=false` (per CLAUDE.md memory `feedback_migration_savepoint`)

**Voters (neu):**
- `src/Security/Voter/RiskAcceptanceVoter.php` — Min-Rolle prüfen für `acceptanceApprovedBy` basierend auf Risk-Score (Pentester PT-F01)

**Stimulus Controllers (modify):**
- `assets/controllers/asset_form_controller.js` — prohibited-Asset-Validation (Sprint 2)

**Twig Templates (modify):**
- `templates/asset/{new,edit}.html.twig` — sections module-conditional via `is_module_active()`
- `templates/incident/{new,edit}.html.twig` — DORA-Subsection conditional
- `templates/data_breach/{new,edit}.html.twig` — whole-form-gating wrapper
- `templates/consent/{new,edit}.html.twig` — withdrawnAt
- `templates/data_subject_request/{new,edit}.html.twig` — Frist-Felder
- `templates/processing_activity/{new,edit}.html.twig` — Whole-Form-Gating
- `templates/dpia/{new,edit}.html.twig` — Whole-Form-Gating
- `templates/business_continuity_plan/{new,edit}.html.twig` — responseTeamMembers JSON-UI
- `templates/crisis_team/{new,edit}.html.twig` — members JSON-UI
- `templates/bc_exercise/{new,edit}.html.twig` — successCriteria JSON-UI
- `templates/management_review/{new,edit}.html.twig` — meetingMinutes-link

**Translations (180 Files):**
- `translations/risk.{de,en}.yaml` — Justifikation-Labels, DORA-Felder, Acceptance-Type
- `translations/asset.{de,en}.yaml` — prohibited-Validation-Message
- `translations/incidents.{de,en}.yaml` — DORA-Klassifikation, Containment, Evidence
- `translations/privacy.{de,en}.yaml` — Consent withdrawnAt + DSR Frist-Felder
- `translations/bcm.{de,en}.yaml` — BC-Plan/Crisis-Team JSON-Felder
- `translations/messages.{de,en}.yaml` — common.module_not_active fallback

**Library Format (Compliance-Manager-Architektur — Sprint 4+):**
- `fixtures/library/frameworks/{framework-id}.yaml` — versionierte Framework-Definitionen
- `fixtures/library/mappings/{framework-A}-to-{framework-B}.yaml` — Cross-Framework-Mappings
- `fixtures/library/presets/{industry-profile}.yaml` — Branchen-Bündel
- `docs/library-schema.json` — JSON-Schema-Validation

**Documentation (FINAL Sprint):**
- `docs/MODULE_GATING_GUIDE.md` — wie Module funktionieren, Setup-Wizard
- `docs/FORM_AUDIT_2026-05.md` — Specialist-Review-Findings konsolidiert
- `docs/library/README.md` — Library-Format-Spec
- `CONTRIBUTING.md` — § Form-Standards erweitert um Module-Gating-Pattern
- `CLAUDE.md` — Module-Awareness-Hinweis

---

## Sprint 1: Quick-Wins (Audit-Critical) — ~7.5 FTE-Tage

### Task 1.1: Module-Gating-Infrastruktur in FormTypes

**Files:**
- Modify: alle 23 gating-relevanten FormTypes
- Common Pattern: `private readonly ModuleConfigurationService $moduleService` injection

- [ ] **Step 1: Verify ModuleConfigurationService is autowired**

```bash
php bin/console debug:autowiring | grep ModuleConfigurationService
```

Expected: shows `App\Service\ModuleConfigurationService` autowireable.

- [ ] **Step 2: Add helper trait for FormTypes**

Create `src/Form/Trait/ModuleAwareFormTrait.php`:

```php
<?php

declare(strict_types=1);

namespace App\Form\Trait;

use App\Service\ModuleConfigurationService;

/**
 * Provides module-awareness to FormTypes — gate fields/sections by active modules.
 *
 * Usage:
 *   class MyFormType extends AbstractType {
 *       use ModuleAwareFormTrait;
 *       public function __construct(
 *           private readonly ModuleConfigurationService $moduleService,
 *       ) {}
 *
 *       public function buildForm(FormBuilderInterface $builder, array $options): void {
 *           $this->addCoreFields($builder);
 *           if ($this->isModuleActive('privacy')) {
 *               $this->addGdprFields($builder);
 *           }
 *       }
 *   }
 */
trait ModuleAwareFormTrait
{
    protected function isModuleActive(string $moduleKey): bool
    {
        return $this->moduleService->isModuleActive($moduleKey);
    }

    protected function isAnyModuleActive(string ...$moduleKeys): bool
    {
        foreach ($moduleKeys as $key) {
            if ($this->moduleService->isModuleActive($key)) {
                return true;
            }
        }
        return false;
    }
}
```

- [ ] **Step 3: Add 8 new module-keys to `config/modules.yaml`**

Append to existing modules section:

```yaml
  privacy:
    name: 'Privacy & Datenschutz'
    description: 'GDPR/DSGVO + ISO 27701 PIMS — DPO-Workflows, DSR, Data Breach 72h, DPIA, Consent'
    required: false
    icon: 'shield-lock'
    entities: [Consent, ProcessingActivity, DataBreach, DataSubjectRequest, DataProtectionImpactAssessment]
    routes: [/consent, /processing-activity, /data-breach, /data-subject-request, /dpia]
    dependencies: [assets]

  nis2_dora:
    name: 'NIS2 & DORA Compliance'
    description: 'EU Cyber-Resilience: NIS2 24h/72h/1mo Reporting + DORA ICT-Risk-Management Art. 6-44'
    required: false
    icon: 'building-shield'
    entities: [Incident, Risk, Supplier]
    routes: []
    dependencies: [incidents, risks, controls]

  ai_governance:
    name: 'AI Governance (EU AI Act + ISO 42001)'
    description: 'KI-System-Klassifikation, Risk-Management für AI-Agents, EU AI Act Art. 9-29 + ISO 42001 AIMS'
    required: false
    icon: 'cpu'
    entities: [Asset]
    routes: []
    dependencies: [assets]

  cloud_security:
    name: 'Cloud Security (ISO 27017/18, BSI C5/AIC4)'
    description: 'Cloud-Provider + Cloud-User Controls + Shared-Responsibility'
    required: false
    icon: 'cloud'
    entities: [Control, Asset]
    routes: []
    dependencies: [controls, assets]

  vulnerability_intel:
    name: 'Vulnerability & Threat Intelligence'
    description: 'CVE/CVSS-Tracking + Threat-Intel + MITRE ATT&CK Mapping'
    required: false
    icon: 'bug'
    entities: [Vulnerability, ThreatIntelligence]
    routes: [/vulnerability, /threat-intelligence]
    dependencies: [risks]

  marisk:
    name: 'MaRisk (DACH-Banken/Versicherer)'
    description: 'Mindestanforderungen Risikomanagement BaFin — Outsourcing AT 9, Risk-Bearing-Capacity AT 4'
    required: false
    icon: 'bank'
    entities: [Supplier, Risk]
    routes: []
    dependencies: [risks, compliance]

  tisax:
    name: 'TISAX (Auto-Industrie)'
    description: 'TISAX Information Security Assessment + Prototype Protection'
    required: false
    icon: 'car-front'
    entities: [PrototypeProtectionAssessment]
    routes: [/prototype-protection]
    dependencies: [controls]

  quantitative_risk:
    name: 'Quantitative Risk (FAIR/CRQ)'
    description: 'Monte-Carlo-Simulation, ALE/SLE, Loss-Magnitude — für Board-Reporting + DORA'
    required: false
    icon: 'graph-up-arrow'
    entities: [Risk]
    routes: []
    dependencies: [risks]
```

- [ ] **Step 4: Lint-gate**

```bash
php bin/console lint:yaml config/modules.yaml ; echo $?
php bin/console lint:container >/dev/null 2>&1 ; echo "container=$?"
```

- [ ] **Step 5: Commit**

```bash
git add config/modules.yaml src/Form/Trait/ModuleAwareFormTrait.php
git commit -m "$(cat <<'EOF'
feat(modules): add 8 consolidated module-keys for FormType-gating + ModuleAwareFormTrait

Compliance-Manager-Konsolidierung: 8 neue Module-Keys statt 17 vorgeschlagene.
ModuleAwareFormTrait für FormType-Service-Injection von ModuleConfigurationService.

Module-Set:
- privacy (gdpr + iso_27701)
- nis2_dora (nis2 + dora)
- ai_governance (eu_ai_act + iso_42001)
- cloud_security (iso_27017/18, bsi_c5/aic4)
- vulnerability_intel (vuln-mgmt + threat-intel + mitre)
- marisk (DACH-Banken-MaRisk parallel zu DORA)
- tisax (Auto-Industrie)
- quantitative_risk (FAIR/CRQ — Optional)

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 1.2: RiskType Justifikation-Felder + GDPR-Subset-Gating + Acceptance-Voter

**Files:**
- Modify: `src/Entity/Risk.php` — likelihoodJustification, impactJustification properties
- Modify: `src/Form/RiskType.php` — gating + Voter
- Create: `src/Security/Voter/RiskAcceptanceVoter.php` — Min-Rolle nach Score
- Migration: include in shared 100000-migration

- [ ] **Step 1: Add Risk entity properties**

Edit `src/Entity/Risk.php`. Add after existing fields:

```php
#[ORM\Column(type: 'text', nullable: true)]
private ?string $likelihoodJustification = null;

#[ORM\Column(type: 'text', nullable: true)]
private ?string $impactJustification = null;

#[ORM\Column(type: 'text', nullable: true)]
private ?string $decisionRationale = null;

#[ORM\ManyToOne(targetEntity: User::class)]
#[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
private ?User $decisionApprovedByUser = null;

#[ORM\Column(type: 'datetime_immutable', nullable: true)]
private ?\DateTimeImmutable $decisionApprovalDate = null;
```

Plus getter/setter pairs (4 sets).

- [ ] **Step 2: Update RiskType form**

Edit `src/Form/RiskType.php`:

1. Use trait: `use ModuleAwareFormTrait;`
2. Constructor: inject `ModuleConfigurationService` + `Security` (für aktuellen User in Voter-Check)
3. In `buildForm()`:

```php
// Justifikation-Felder ZWINGEND nach jedem Score-Eingabe
$builder
    ->add('likelihoodJustification', TextareaType::class, [
        'label' => 'risk.field.likelihood_justification',
        'help' => 'risk.help.likelihood_justification',
        'required' => true,
        'attr' => ['rows' => 3, 'placeholder' => 'risk.placeholder.likelihood_justification'],
        'constraints' => [new NotBlank(message: 'risk.validation.likelihood_justification_required')],
    ])
    ->add('impactJustification', TextareaType::class, [
        'label' => 'risk.field.impact_justification',
        'help' => 'risk.help.impact_justification',
        'required' => true,
        'attr' => ['rows' => 3, 'placeholder' => 'risk.placeholder.impact_justification'],
        'constraints' => [new NotBlank(message: 'risk.validation.impact_justification_required')],
    ]);

// acceptanceApprovedBy: Plain TextType -> EntityType (User) mit Voter-Filter
$builder->remove('acceptanceApprovedBy'); // legacy plain field
$builder->add('decisionApprovedByUser', EntityType::class, [
    'class' => User::class,
    'choice_label' => fn(User $u) => $u->getFullName() . ' (' . $u->getEmail() . ')',
    'query_builder' => function (UserRepository $r) {
        // Tenant-scoped + Min-Rolle ROLE_MANAGER (Voter prüft pro-Risk-Score)
        return $r->createQueryBuilder('u')
            ->where('u.tenant = :tenant')
            ->andWhere(':minRole MEMBER OF u.roles')
            ->setParameter('tenant', $this->tenantContext->getCurrentTenant())
            ->setParameter('minRole', 'ROLE_MANAGER');
    },
    'label' => 'risk.field.decision_approved_by_user',
    'required' => false,
    'placeholder' => 'risk.placeholder.decision_approved_by_user',
])
->add('decisionApprovalDate', DateType::class, [
    'widget' => 'single_text',
    'label' => 'risk.field.decision_approval_date',
    'required' => false,
])
->add('decisionRationale', TextareaType::class, [
    'label' => 'risk.field.decision_rationale',
    'help' => 'risk.help.decision_rationale',
    'required' => false,
    'attr' => ['rows' => 2],
]);

// GDPR-Subset Gating
if ($this->isModuleActive('privacy')) {
    $this->addGdprSubset($builder);
}

private function addGdprSubset(FormBuilderInterface $builder): void {
    $builder
        ->add('involvesPersonalData', CheckboxType::class, [
            'label' => 'risk.field.involves_personal_data',
            'required' => false,
        ])
        ->add('involvesSpecialCategoryData', CheckboxType::class, [
            'label' => 'risk.field.involves_special_category_data',
            'required' => false,
            'attr' => ['data-depends-on' => 'risk_form_involvesPersonalData', 'data-depends-on-value' => '1'],
        ])
        ->add('legalBasis', ChoiceType::class, [
            'label' => 'risk.field.legal_basis',
            'choices' => [
                'risk.legal_basis.consent' => 'consent',
                'risk.legal_basis.contract' => 'contract',
                'risk.legal_basis.legal_obligation' => 'legal_obligation',
                'risk.legal_basis.vital_interests' => 'vital_interests',
                'risk.legal_basis.public_task' => 'public_task',
                'risk.legal_basis.legitimate_interests' => 'legitimate_interests',
            ],
            'required' => false,
            'placeholder' => 'risk.placeholder.legal_basis',
            'attr' => ['data-depends-on' => 'risk_form_involvesPersonalData', 'data-depends-on-value' => '1'],
        ])
        ->add('processingScale', ChoiceType::class, [
            'label' => 'risk.field.processing_scale',
            'choices' => [
                'risk.processing_scale.small' => 'small',
                'risk.processing_scale.medium' => 'medium',
                'risk.processing_scale.large' => 'large',
            ],
            'required' => false,
            'attr' => ['data-depends-on' => 'risk_form_involvesPersonalData', 'data-depends-on-value' => '1'],
        ])
        ->add('requiresDPIA', CheckboxType::class, [
            'label' => 'risk.field.requires_dpia',
            'required' => false,
            'attr' => ['data-depends-on' => 'risk_form_involvesPersonalData', 'data-depends-on-value' => '1'],
        ])
        ->add('dataSubjectImpact', TextareaType::class, [
            'label' => 'risk.field.data_subject_impact',
            'required' => false,
            'attr' => ['rows' => 2, 'data-depends-on' => 'risk_form_involvesPersonalData', 'data-depends-on-value' => '1'],
        ]);
}
```

- [ ] **Step 3: Create RiskAcceptanceVoter**

Create `src/Security/Voter/RiskAcceptanceVoter.php`:

```php
<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Risk;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\Security;

/**
 * RiskAcceptanceVoter — checks whether current user has authority
 * to APPROVE risk acceptance based on score-tier per ISO 31000 §6.5.4.
 *
 * Score-Tier → Min-Rolle:
 *   Low (1-6):      Risk-Owner allein
 *   Medium (7-12):  + ROLE_MANAGER (ISB)
 *   High (13-19):   + ROLE_ADMIN (CISO)
 *   Critical (20-25): + ROLE_SUPER_ADMIN (Vorstand-Proxy)
 */
class RiskAcceptanceVoter extends Voter
{
    public const APPROVE = 'RISK_ACCEPTANCE_APPROVE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::APPROVE && $subject instanceof Risk;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        /** @var Risk $risk */
        $risk = $subject;
        $score = $risk->getInherentRiskScore() ?? ($risk->getProbability() * $risk->getImpact());

        $minRole = match (true) {
            $score >= 20 => 'ROLE_SUPER_ADMIN',
            $score >= 13 => 'ROLE_ADMIN',
            $score >= 7  => 'ROLE_MANAGER',
            default      => 'ROLE_USER',
        };

        return in_array($minRole, $user->getRoles(), true);
    }
}
```

- [ ] **Step 4: Verify lint-gate**

```bash
find src -name "*.php" -print0 | xargs -0 -n1 php -l >/dev/null 2>&1 ; echo "php=$?"
php bin/console lint:container >/dev/null 2>&1 ; echo "container=$?"
```

- [ ] **Step 5: Generate migration (Doctrine diff) — DEFERRED to shared Sprint-1+2 migration**

- [ ] **Step 6: Add translations (de+en) — Justifikations-Felder, Validation-Messages, GDPR-Subset-Labels**

Edit `translations/risk.de.yaml` + `risk.en.yaml`.

- [ ] **Step 7: Update template `templates/risk/{new,edit}.html.twig`** — sections-config erweitert um Justifikations-Felder + GDPR-Subsection (auto-rendered via _auto_form catch-all wenn nicht in sections gelistet — aber besser explizit für Order)

- [ ] **Step 8: Lint+twig+phpunit (RiskController-Test)**

```bash
php bin/console lint:twig templates/risk/ >/dev/null 2>&1 ; echo "twig=$?"
php bin/console lint:yaml translations/ >/dev/null 2>&1 ; echo "yaml=$?"
php bin/phpunit --no-coverage tests/Controller/RiskControllerTest.php 2>&1 | tail -3
```

- [ ] **Step 9: Commit**

```bash
git add src/Entity/Risk.php src/Form/RiskType.php src/Security/Voter/RiskAcceptanceVoter.php translations/risk.de.yaml translations/risk.en.yaml templates/risk/
git commit -m "$(cat <<'EOF'
feat(risk): justifikation-fields + GDPR-subset gating + acceptance-voter

DPO + Risk-Mgmt + Pentester konsolidierte Empfehlungen:
- likelihoodJustification + impactJustification ZWINGEND (ISO 27001 6.1.2.d
  Audit-Pflicht — Score ohne Begründung NICHT auditfest)
- decisionApprovedByUser EntityType statt Plain-Text (Pentester PT-F01
  Privilege-Escalation CVSS 9.1)
- RiskAcceptanceVoter: Min-Rolle pro Score-Tier (ISO 31000 §6.5.4)
- GDPR-Subset (involvesPersonalData/SpecialCategory/legalBasis/processingScale/
  requiresDPIA/dataSubjectImpact) gated auf 'privacy' Modul + Stimulus-conditional

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 1.3: ConsentType +withdrawnAt (GDPR Art. 7(3))

**Files:**
- Modify: `src/Entity/Consent.php` — add `withdrawnAt` (DateTimeImmutable nullable) + `withdrawalReason` (text nullable) + `withdrawalChannel` (string nullable)
- Modify: `src/Form/ConsentType.php` — add 3 fields (visible only when editing OR explicit withdraw-action)
- Migration: include in shared

- [ ] **Step 1: Entity properties**

```php
#[ORM\Column(type: 'datetime_immutable', nullable: true)]
private ?\DateTimeImmutable $withdrawnAt = null;

#[ORM\Column(type: 'text', nullable: true)]
private ?string $withdrawalReason = null;

#[ORM\Column(length: 100, nullable: true)]
private ?string $withdrawalChannel = null;

public function isWithdrawn(): bool { return $this->withdrawnAt !== null; }
```

- [ ] **Step 2: Form fields (auto-rendered via _auto_form catch-all OR explicit section)**

```php
->add('withdrawnAt', DateTimeType::class, [
    'widget' => 'single_text',
    'label' => 'privacy.consent.field.withdrawn_at',
    'required' => false,
    'help' => 'privacy.consent.help.withdrawn_at',
])
->add('withdrawalReason', TextareaType::class, [
    'label' => 'privacy.consent.field.withdrawal_reason',
    'required' => false,
    'attr' => ['rows' => 2],
])
->add('withdrawalChannel', ChoiceType::class, [
    'choices' => [
        'privacy.consent.withdrawal_channel.web' => 'web',
        'privacy.consent.withdrawal_channel.email' => 'email',
        'privacy.consent.withdrawal_channel.phone' => 'phone',
        'privacy.consent.withdrawal_channel.letter' => 'letter',
        'privacy.consent.withdrawal_channel.in_person' => 'in_person',
    ],
    'required' => false,
    'placeholder' => 'privacy.consent.placeholder.withdrawal_channel',
])
```

- [ ] **Step 3: Validation: if withdrawnAt set → withdrawalReason + withdrawalChannel required**

Add via `@Assert\Expression` constraint.

- [ ] **Step 4: Lint+twig+commit**

```bash
git commit -m "feat(consent): GDPR Art. 7(3) Widerruf-Tracking — withdrawnAt + reason + channel"
```

---

### Task 1.4: DSR Frist-Felder (GDPR Art. 12(3))

**Files:**
- Modify: `src/Entity/DataSubjectRequest.php` — responseAt, extendedDeadline (datetime), extensionReason (text), responseDocument (string nullable for filename / Many-to-One Document later), responseMethod (enum), rejectionReason (text)
- Modify: `src/Form/DataSubjectRequestType.php` — add 6 fields

- [ ] **Step 1: Entity**: 6 properties + getter/setter

- [ ] **Step 2: Form**: add 6 fields with proper types

- [ ] **Step 3: Validation**: `responseAt` muss <= 30 Tage nach receivedAt ODER extendedDeadline gesetzt mit reason

- [ ] **Step 4: Translation + Template + Commit**

```bash
git commit -m "feat(dsr): GDPR Art. 12(3) Frist-Tracking — responseAt+extendedDeadline+extensionReason+responseDocument+responseMethod+rejectionReason"
```

---

### Task 1.5: SSRF-Validation für URL-Felder

**Files:**
- Create: `src/Validator/Constraint/NoInternalIp.php` + Validator-Class
- Modify: `src/Form/IdentityProviderType.php` (6 URL fields), `src/Form/CrisisTeamType.php` (virtualMeetingUrl), `src/Form/PatchType.php` (downloadUrl, documentationUrl), `src/Form/Admin/TenantEmailBrandingType.php` (emailLogoUrl)

- [ ] **Step 1: Create Custom Constraint**

`src/Validator/Constraint/NoInternalIp.php`:

```php
<?php

declare(strict_types=1);

namespace App\Validator\Constraint;

use Symfony\Component\Validator\Constraint;

#[\Attribute]
class NoInternalIp extends Constraint
{
    public string $message = 'Internal IPs (127.x, 10.x, 172.16-31.x, 192.168.x, 169.254.x, ::1, fc00::/7) are not allowed for security reasons (SSRF protection).';
}
```

`src/Validator/Constraint/NoInternalIpValidator.php`:

```php
<?php

declare(strict_types=1);

namespace App\Validator\Constraint;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

class NoInternalIpValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof NoInternalIp) {
            throw new \InvalidArgumentException('Expected NoInternalIp constraint');
        }
        if ($value === null || $value === '') {
            return;
        }

        $host = parse_url((string) $value, PHP_URL_HOST);
        if ($host === null || $host === false) {
            return; // URL constraint should catch malformed
        }

        // Resolve hostname to IP
        $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : @gethostbyname($host);
        if ($ip === $host && !filter_var($host, FILTER_VALIDATE_IP)) {
            // DNS resolution failed — block to be safe
            $this->context->buildViolation('Hostname could not be resolved (potential DNS pinning attack).')->addViolation();
            return;
        }

        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            $this->context->buildViolation($constraint->message)->addViolation();
        }
    }
}
```

- [ ] **Step 2: Apply to URL-Felder in 4 FormTypes**

For each URL field, add to `'constraints'` array:

```php
'constraints' => [
    new Url(protocols: ['https'], message: 'sso.validation.url_https_only'),
    new NoInternalIp(),
],
```

- [ ] **Step 3: Lint+commit**

```bash
git commit -m "$(cat <<'EOF'
fix(security): SSRF-protection for URL fields — NoInternalIp constraint (Pentester PT-F03)

Custom Validator-Constraint blocks Internal-Ranges (127.x/10.x/172.16-31.x/
192.168.x/169.254.x/::1/fc00::/7) on:
- IdentityProviderType (6 URL fields — discoveryUrl, issuer, authEndpoint,
  tokenEndpoint, userinfoEndpoint, jwksUri) — Server fetcht diese URLs für
  JWT-Discovery, CVSS 8.6 ohne Schutz
- CrisisTeamType.virtualMeetingUrl
- PatchType.downloadUrl + documentationUrl
- TenantEmailBrandingType.emailLogoUrl

OWASP A10:2021 SSRF, CWE-918, NIS2 Art. 21(2)(d).

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 1.6: File-Upload-Audit — alle 5 FileTypes nutzen FileUploadSecurityService

**Files:** `src/Form/{TenantType,DocumentType,UserType,Admin/ComplianceImportUploadType,Admin/GstoolImportUploadType}.php`

- [ ] **Step 1: Audit per FormType**

Für jede:
1. Bestehende `'constraints'` lesen
2. Verifizieren: `File`-Constraint mit max-size + mime-types
3. Verifizieren: Controller ruft `FileUploadSecurityService::validate()` auf

- [ ] **Step 2: Per Gap: ergänzen**

Pattern:
```php
'constraints' => [
    new File(
        maxSize: '10M',
        mimeTypes: ['application/pdf', 'image/jpeg', 'image/png', /* per Form */],
        mimeTypesMessage: 'document.validation.mime_type_invalid',
        maxSizeMessage: 'document.validation.max_size_exceeded',
    ),
],
```

- [ ] **Step 3: Verifizieren `FileUploadSecurityService` calls in jeweiligen Controllers** (siehe `grep -rn "FileUploadSecurityService" src/Controller/`)

- [ ] **Step 4: Falls fehlend: Service-Call hinzufügen**

- [ ] **Step 5: Commit**

```bash
git commit -m "fix(security): File-Upload-Hardening alle 5 FileTypes — MIME+Size+FileUploadSecurityService (Pentester PT-F04)"
```

---

### Task 1.7: Sprint-1-Migration generieren

- [ ] **Step 1: Generate Doctrine diff**

```bash
php bin/console doctrine:migrations:diff --no-interaction
```

- [ ] **Step 2: Verify migration includes only Sprint-1 fields:**
- Risk: likelihoodJustification, impactJustification, decisionApprovedByUser, decisionApprovalDate, decisionRationale
- Consent: withdrawnAt, withdrawalReason, withdrawalChannel
- DataSubjectRequest: responseAt, extendedDeadline, extensionReason, responseDocument, responseMethod, rejectionReason

- [ ] **Step 3: Manuell `isTransactional()=false` hinzufügen** (per CLAUDE.md memory `feedback_migration_savepoint`)

- [ ] **Step 4: Run migration**

```bash
php bin/console doctrine:migrations:migrate --no-interaction
```

- [ ] **Step 5: Verify**

```bash
php bin/console doctrine:schema:validate
```

- [ ] **Step 6: Commit**

```bash
git commit -m "feat(migration): Sprint-1 norm-fields — Risk justifikation, Consent withdrawal, DSR frist-tracking"
```

---

## Sprint 2: Module-Gating Roll-out + DORA/NIS2 Critical — ~12 FTE-Tage

### Task 2.1: WHOLE-FORM-Gating für 5 GDPR-FormTypes + 3 BCM-FormTypes

**Pattern in Controller (statt FormType-Level):**

Edit each Controller (Privacy + BCM) — `ProcessingActivityController`, `DataBreachController`, `ConsentController`, `DataSubjectRequestController`, `DPIAController`, `BusinessContinuityPlanController`, `BCExerciseController`, `CrisisTeamController`:

In `index()` + `new()` + `edit()`:

```php
public function index(Request $request): Response
{
    if (!$this->moduleService->isModuleActive('privacy')) {
        $this->addFlash('warning', 'common.module_not_active');
        return $this->redirectToRoute('app_dashboard');
    }
    // ... existing logic
}
```

- [ ] **Step 1: 8 Controllers + 24 Methoden (index/new/edit pro Controller)**
- [ ] **Step 2: Mega-Menu-Items per `is_module_active()` ausblenden** in `templates/_components/_mega_menu_panel_only.html.twig`
- [ ] **Step 3: Translation `common.module_not_active`**
- [ ] **Step 4: Commit**

---

### Task 2.2: IncidentType DORA-Subset (12 Felder + 4 ISO 27001 A.5.28)

**Entity: `src/Entity/Incident.php`** — add:
- `incidentClassification` (string, event|incident)
- `containmentActions` (text)
- `evidencePreserved` (bool)
- `evidenceArtifacts` (Many-to-Many to Document)
- DORA-Subset (gated `nis2_dora`):
  - `ictIncidentClassification` (enum)
  - `dataLossOccurred` + `dataLeakageOccurred` (bool)
  - `economicImpact` (decimal)
  - `reputationalImpact` (smallint 1-5)
  - `criticalServicesAffected` (Many-to-Many to BusinessProcess)
  - `recurringIncident` (bool)
  - `clientsAffected` (int) + `clientsAffectedFinancialVolume` (decimal)
  - `replicationOfImpact` (bool)
  - `initialReportSubmittedAt` + `intermediateReportSubmittedAt` (datetime — DORA 4h+72h, separate von NIS2 24h+72h)
  - `dataRecoveryStrategy` (text)

**FormType:** add via Module-Gating-Trait wie in Sprint 1.

- [ ] **Step 1-N**: analog zu Task 1.2

---

### Task 2.3: AssetType AI-Agent gating + prohibited-Validation

**FormType `src/Form/AssetType.php`:**

```php
if ($this->isModuleActive('ai_governance')) {
    // 9 AI-Agent-Felder (existieren bereits) — nur unter Modul-Aktiv hinzufügen
    $this->addAiAgentFields($builder);
}
```

**Validation (Constraint auf Asset-Entity):**

```php
#[Assert\Callback]
public function validateAiAgentProhibited(ExecutionContextInterface $context): void
{
    if ($this->aiAgentClassification === 'prohibited' && $this->status === 'active') {
        $context->buildViolation('asset.validation.ai_prohibited_must_be_inactive')
            ->atPath('status')
            ->addViolation();
    }
}
```

**Per User-Direktive: prohibited darf inactive sein, aber nicht active.**

- [ ] **Step 1-5**: implement, lint, test, commit

---

### Task 2.4: BC Plan structured fields + Crisis Team members JSON + BCExercise successCriteria

**Entities:**
- `BusinessContinuityPlan` — `responseTeamMembers` (json), `requiredResources` (json), `escalationLevels` (json), `crisisTeams` (Many-to-Many to CrisisTeam)
- `CrisisTeam` — `members` (json), `emergencyContacts` (json), `availableResources` (json), `lastActivatedAt` (datetime), `activationCount` (int default 0), `escalationMatrix` (json)
- `BCExercise` — `successCriteria` (json), `actualRtoAchieved` (decimal), `actualRpoAchieved` (decimal), `bcPlansTested` (Many-to-Many to BCPlan), `evidenceArtifacts` (Many-to-Many to Document)

**FormType:** Stimulus-Controller für JSON-UI (key-value-Editor).

- [ ] **Step 1-N**: implement systematically

---

### Task 2.5: ManagementReviewType ISO 27001 §9.3 Gaps

**Entity:** `topManagementAttended` (bool), `nextReviewDate` (date), `meetingMinutesDocument` (Many-to-One Document), `riskTreatmentEffectiveness` (text), `policyReviewOutcome` (text), `frameworkComplianceStatus` (json), `actionItemsWithDeadlines` (json)

- [ ] **Step 1-N**: implement

---

### Task 2.6: Sprint-2-Migration

Generate diff, manuell `isTransactional()=false`, migrate, validate, commit.

---

## Sprint 3+: Long-Tail (5-15 FTE-Tage je Sprint)

### Task 3.1: AuditFindingType +source-Klassifikator (ISO §10.1)

Add `source` enum: `internal_audit | external_audit | incident | review | customer_complaint | management_review`

### Task 3.2: CorrectiveActionType +actionType-Klassifikator

`actionType` enum: `corrective | preventive | improvement` (statt separater Entities NonConformityType + ContinualImprovementInitiativeType).

### Task 3.3: ChangeRequestType +Tag-Felder für §6.3/§8.1

### Task 3.4: RiskAcceptance +acceptanceType (für Exception-Pattern)

### Task 4.1: TrainingType +Program-Klassifikator + AwarenessProgram-View

### Task 4.2: User +`competencies` JSON + CompetenceMatrix-View

### Task 4.3: DocumentType erweitert — `type=communication_plan` + Stakeholder-Many-to-Many

### Task 5.1: ISMSObjectiveType +monitoring-Felder (statt MonitoringPlanType)

`measurement_frequency` (enum: daily/weekly/monthly/quarterly/yearly), `measurement_method` (text), `responsible_for_measurement` (User-Ref).

### Task 5.2: KPI-Snapshot-Job + Read-Only-View (kein FormType — async-job)

### Task 6.1: ControlType +Cloud-Mappings (gated `cloud_security`)

`cloudControlReference` + `cloudPrivacyReference` + `pimsReference` + `customerOrProviderResponsibility`

### Task 6.2: ControlType +`effectiveness` + `controlType` + `controlMaturity`

### Task 7.1: RiskType DORA-ICT-Subset (10 Felder gated `nis2_dora`)

### Task 7.2: SupplierType +MaRisk-Felder gated `marisk` modul

`outsourcingClassification` (substantielle vs unwesentliche), `outsourcingDueDiligenceCompleted`, `outsourcingExitStrategy`, `bafinNotificationRequired`, `bafinNotificationDate`, `riskBearingCapacityImpact`, `boardLevelRiskAcceptance`, `complianceFunctionInvolvement`, `internalAuditFunctionInvolvement`.

### Task 8.1: ThreatIntelType +TLP/MITRE/IOCs gated `vulnerability_intel`

### Task 8.2 (Optional): Quantitative Risk Sub-Section (FAIR — 12 Felder) gated `quantitative_risk`

---

## Library-Format-Architektur (Sprint 4 parallel)

Compliance-Manager priorisiert. Ermöglicht Framework-Updates ohne Code:

### Task L.1: Schema-Definition

`docs/library-schema.json` — JSON-Schema für Frameworks, Mappings, Presets.

### Task L.2: Framework-Files migrieren

`fixtures/library/frameworks/`:
- `iso27001-2022.yaml`
- `iso27001-2013.yaml`
- `bsi-it-grundschutz-2024.yaml`
- `bsi-c5-2026.yaml`
- `dora-2024.yaml`
- `nis2-directive-2022.yaml`
- `tisax-vda-isa-6.yaml`
- `gdpr.yaml`
- `bdsg.yaml`
- `iso22301-2019.yaml`
- `iso27017-2015.yaml`
- `iso27018-2019.yaml`
- `iso27701-2025.yaml`
- `iso42001-2023.yaml`

### Task L.3: Mappings-Files

`fixtures/library/mappings/`:
- `iso27001-to-nis2-art21.yaml`
- `iso27001-to-dora.yaml`
- `iso27001-to-tisax.yaml`
- `iso27001-to-bsi-grundschutz.yaml`
- `bsi-c5-to-iso27001.yaml`
- `gdpr-to-iso27701.yaml`
- (Community-PR-fähig)

### Task L.4: Branchen-Presets

`fixtures/library/presets/`:
- `de-mittelstand-nis2.yaml`
- `de-bafin-financial.yaml`
- `kritis-energie.yaml`
- `auto-tier-1-supplier.yaml`
- `eu-ai-act-high-risk.yaml`

### Task L.5: Loader-Service

`src/Service/Library/LibraryLoaderService.php` — lädt YAMLs, validiert gegen Schema, erzeugt DB-Entries idempotent.

### Task L.6: Setup-Wizard-Integration

8-Frage-Wizard (siehe Compliance-Manager-Empfehlung) → Lädt Preset + Frameworks + Mappings.

---

## Documentation Sprint (FINAL — nach allen Code-Sprints)

### Task D.1: MODULE_GATING_GUIDE.md

`docs/MODULE_GATING_GUIDE.md`:
- Wie Module funktionieren (`is_module_active()`, ModuleAwareFormTrait)
- Setup-Wizard 8-Fragen-Schema
- Module-Aktivierungs-Effekte pro Modul-Key

### Task D.2: FORM_AUDIT_2026-05.md

`docs/FORM_AUDIT_2026-05.md`:
- Specialist-Findings konsolidiert
- Vor/Nach-Zustand pro FormType
- Compliance-Coverage-Matrix (welche Norm braucht welche Felder, sind sie implementiert)

### Task D.3: Library-Format-Spec

`docs/library/README.md`:
- YAML-Format-Spezifikation
- Wie eigene Frameworks/Mappings/Presets beitragen
- JSON-Schema-Validation
- Beispiel-Workflow Community-PR

### Task D.4: CONTRIBUTING.md erweitern

Neue Section "Form Standards (Aurora v4 + Module-Gating)" mit:
- ModuleAwareFormTrait verwenden
- Stimulus-conditional via `data-depends-on`
- Library-format für Framework-Updates

### Task D.5: CLAUDE.md erweitern

Section "Module-Awareness in FormTypes" + Cross-Reference zu MODULE_GATING_GUIDE.

### Task D.6: User-facing Dokumentation

`docs/user-guide/MODULE_AKTIVIERUNG.md`:
- Wie der Setup-Wizard funktioniert
- Welche Module wann aktivieren
- FAQ für Audit-Vorbereitung

---

## Final Verification (vor Sprint-Closure)

### Acceptance Criteria

- [ ] **Lint-Suite alles grün:** stylelint, twig, container, php-syntax, yaml
- [ ] **PHPUnit-Suite:** 4385+ tests, 0 errors, 0 failures (pre-existing notices akzeptabel)
- [ ] **Coverage:** alle 23 gating-relevanten FormTypes nutzen ModuleAwareFormTrait
- [ ] **Module-Activation-Test:** `is_module_active()` Twig + PHP konsistent (manueller Test mit aktiviertem/deaktiviertem Modul)
- [ ] **Voter-Test:** RiskAcceptanceVoter blockt bei zu niedriger Rolle (PHPUnit-Test)
- [ ] **SSRF-Test:** NoInternalIpValidator wirft Constraint-Violation bei `http://127.0.0.1` (PHPUnit-Test)
- [ ] **Visual-Smoke (manuell, User-Job):** 5 Top-Pages pro Modul-Aktivierungs-Status (Mit/Ohne Privacy, BCM, NIS2/DORA)
- [ ] **Migration-Test:** `doctrine:schema:validate` clean, `doctrine:migrations:status` keine pending
- [ ] **Translation-Coverage:** `php bin/console debug:translation de --only-missing` = 0
- [ ] **Translation-Quality:** `python3 scripts/quality/check_translation_issues.py` ≤ pre-existing baseline

---

## Self-Review Checklist (am Ende)

Diese Checkliste wird VOR Plan-Commit + nach jedem Sprint durchgegangen:

### Spec-Coverage
- [ ] Alle DPO-Empfehlungen (5 GDPR-FormTypes + RiskType-Subset) abgedeckt? → Sprint 1.2 + 1.3 + 1.4 + 2.1
- [ ] Alle BSI-Empfehlungen (AI-Agent-Felder + ISO 42001 + AIC4) abgedeckt? → Sprint 2.3 + Library-File `iso42001`/`bsi_aic4` (via cloud_security)
- [ ] Alle BCM-Empfehlungen (3 FormTypes + Cross-Link + Pandemic/Cyber-Provisions)? → Sprint 2.4
- [ ] Alle Risk-Mgmt-Empfehlungen (Justifikation + DORA + Quantitative + Vuln/ThreatIntel)? → Sprint 1.2 + 7.1 + 8.x
- [ ] Alle Pentester-Empfehlungen (Voter + SSRF + File-Upload + IDOR-Audit)? → Sprint 1.2 + 1.5 + 1.6 + Audit-Notiz für IDOR
- [ ] Alle ISMS-Empfehlungen (IncidentType DORA + ManagementReview + Cross-cutting Clauses)? → Sprint 2.2 + 2.5 + Long-tail
- [ ] Alle Compliance-Manager-Konsolidierungen (8 Module statt 17, 0 neue Entities, Library-Format)? → Sprint 1.1 + Library-Tasks

### Implementierungs-Konsistenz
- [ ] ModuleAwareFormTrait wird in JEDEM gating-relevanten FormType genutzt?
- [ ] Stimulus `data-depends-on` Pattern für conditional sub-fields konsistent angewendet?
- [ ] Translation-Keys folgen `<domain>.<entity>.<context>.<key>` Konvention?
- [ ] Migration-Memory `isTransactional()=false` für jede DDL-Migration?
- [ ] Voter wird im Controller via `denyAccessUnlessGranted()` genutzt?

### Audit-Pflicht-Lücken geschlossen
- [ ] RiskType +`likelihoodJustification` + `impactJustification` (ISO 27001 6.1.2.d)?
- [ ] RiskType `acceptanceApprovedBy` von TextType → EntityType + Voter (Pentester PT-F01)?
- [ ] ConsentType +`withdrawnAt` (GDPR Art. 7(3))?
- [ ] DSR +`responseAt`+`extendedDeadline`+`extensionReason`+`responseDocument` (GDPR Art. 12(3))?
- [ ] IncidentType +`evidencePreserved` + `containmentActions` (ISO 27001 A.5.28+A.5.26)?

### Documentation-Coverage
- [ ] MODULE_GATING_GUIDE für Devs?
- [ ] FORM_AUDIT_2026-05 für Auditoren?
- [ ] Library-Format-Spec für Community-Contribute?
- [ ] CONTRIBUTING.md erweitert?
- [ ] CLAUDE.md Module-Awareness-Section?
- [ ] User-facing MODULE_AKTIVIERUNG.md?

### Frühe Fehler-Erkennung
- [ ] Sprint 1 hat ALLE 5 KRITISCHEN Audit-Lücken adressiert?
- [ ] Voter-Test in PHPUnit (nicht erst im Pentest-Re-Audit)?
- [ ] Migration in Test-DB durchgelaufen vor Production-Deploy?
- [ ] Stimulus `data-depends-on` Smoke-Test in Browser (sonst stille UX-Bug)?
- [ ] Module-Deaktivierung testen: Form versteckt Felder, aber DB-Daten bleiben?

---

## Risk + Rollback

| Risk | Wahrscheinlichkeit | Mitigation |
|---|---|---|
| Migration scheitert wegen pre-existing schema-drift | Mittel | Pre-Migration: `doctrine:schema:validate` + ggf. `app:schema:reconcile --dry-run` |
| Voter blockt legitimen User in Edge-Cases (z.B. CISO ohne ROLE_ADMIN-Tag) | Mittel | Test-Suite mit echten Tenant-User-Fixtures + manueller Smoke |
| Stimulus `data-depends-on` propagiert nicht in Modal-Forms | Niedrig | Test in 3 Modal-Use-Cases (Risk-Modal, Asset-Modal) |
| Module-Deaktivierung versteckt Form, aber bestehende Entries bleiben "verwaist" sichtbar in Reports | Mittel | Reports auch via `is_module_active()` filtern (Sprint 3-Erweiterung) |
| Library-Format YAMLs werden inkompatibel mit zukünftigen Schema-Updates | Niedrig | Versionsfeld in YAML + Schema-Validation pre-Load |
| Performance-Hit durch ModuleConfigurationService-Lookup pro Form-Render | Niedrig | Service hat Cache (verifiziert in `src/Service/ModuleConfigurationService.php`) |

**Rollback:** Per-Commit `git revert <sha>` möglich. Bei Migration-Rollback: `php bin/console doctrine:migrations:migrate prev --no-interaction`.

---

## Aufwandsschätzung

| Sprint | Tasks | FTE-Tage | Audit-Wirkung |
|---|---|---|---|
| Sprint 1 | Module-Trait + RiskType + Consent + DSR + SSRF + FileUpload + Migration | ~7.5 | 4 von 5 KRITISCHEN Lücken zu |
| Sprint 2 | 8 FormTypes Whole-Form-Gating + IncidentType DORA + Asset AI + BCM Strukturierung + ManagementReview + Migration | ~12 | DORA-Compliance 70%, NIS2-Compliance 80% |
| Sprint 3 | AuditFinding source + CorrectiveAction actionType + ChangeRequest tags + RiskAcceptance type | ~5 | Cross-Cutting Cleanup |
| Sprint 4 | Training-Programs + Competence-Matrix + CommPlan via DocumentType | ~8 | ISO 27001 §7.2-§7.4 abgedeckt |
| Sprint 4 (parallel) | Library-Format YAMLs + Loader + Setup-Wizard | ~10 | Architektur-Foundation für Future-Frameworks |
| Sprint 5 | ISMSObjective monitoring + KPI-Snapshot-Job | ~5 | ISO 27001 §9.1 |
| Sprint 6 | ControlType Cloud-Mappings + effectiveness + maturity | ~5 | ISO 27017/18 + Maturity |
| Sprint 7 | RiskType DORA-ICT + Supplier MaRisk | ~5 | DORA Art. 6-15 + MaRisk |
| Sprint 8+ | ThreatIntel TLP/MITRE + Quantitative Risk FAIR | ~9 | Optional Advanced |
| Documentation | 6 Doku-Files | ~3 | Audit-Bereitschaft + Onboarding |
| **Total** | | **~70 FTE-Tage** | Vollständige Norm-Compliance |

**Realistisch:** 14-16 Wochen verteilt auf 1-2 FTE; Sprint 1+2 in 4-5 Wochen für Audit-Critical.

---

## Referenzen

- Specialist-Sessions: 2026-05-07 (DPO, BSI, BCM, Risk-Mgmt, Pentester, ISMS, Compliance-Manager)
- ISO 27001:2022, ISO 27005:2022, ISO 31000:2018, ISO 22301:2019, ISO 22313:2020
- BSI 200-1, 200-2, 200-3, 200-4, AIC4
- EU DSGVO 2016/679, BDSG, ISO 27701:2025
- EU DORA 2022/2554 + RTS 2024/1772-1859
- EU NIS2 2022/2555 + DE NIS2UmsuCG (Nov 2025)
- EU AI Act 2024/1689
- ISO 42001:2023 (AI Management System)
- ISO 27017/18 (Cloud Security/Privacy)
- MaRisk AT (BaFin)
- OWASP Top 10 2021/2025, CVSS 4.0, NIST 800-53/150
- MITRE ATT&CK, FAIR Institute
- Memory: feedback_migration_consolidation, feedback_migration_savepoint, feedback_no_competitor_names, feedback_dora_replaces_vait_bait
