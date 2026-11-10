# Module Gating Guide — Developer Reference

> Cross-references: [FORM_AUDIT_2026-05.md](archive/FORM_AUDIT_2026-05.md) | [CONTRIBUTING.md](../CONTRIBUTING.md#module-gating-pattern) | [User Guide](user-guide/MODULE_AKTIVIERUNG.md)

## Overview

Module gating ensures that users only see form fields and UI sections relevant to their
active compliance modules. A `privacy`-only customer does not see DORA fields; a bank
without AI systems does not see EU AI Act constraints.

The system has three layers:

| Layer | Responsibility | Key Artifact |
|---|---|---|
| **Config** | Which modules exist and their metadata | `config/modules.yaml` |
| **Service** | Runtime activation check (per tenant) | `ModuleConfigurationService` |
| **Form / Controller / Twig** | Conditionally include fields / block access | Traits + Twig function |

---

## Module keys

The authoritative list is `config/modules.yaml` (45 keys). Required (always active, cannot be
switched off): `core`, `authentication`, `audit_logging`, `notifications`, `documents`, `objectives`, `workflows`.

Optional modules:

| Key | Name | Depends on |
|---|---|---|
| `assets` | Asset Management | — |
| `risks` | Risk Management | `assets` |
| `controls` | Control Management (SoA) | — |
| `incidents` | Incident Management | `controls` |
| `audits` | Audit Management | `controls` |
| `training` | Training & Awareness | — |
| `access_review` | Zugriffsrezertifizierung (UAR) | `authentication`, `audit_logging` |
| `reviews` | Management Review | `audits` |
| `bcm` | Business Continuity Management | `assets` |
| `compliance` | Multi-Framework Compliance | `controls` |
| `privacy` | Privacy & Datenschutz (GDPR + ISO 27701) | `assets` |
| `nis2_dora` | NIS2 & DORA Compliance | `incidents`, `risks`, `controls` |
| `ai_governance` | AI Governance (EU AI Act + ISO 42001) | `assets` |
| `cloud_security` | Cloud Security (ISO 27017/18, BSI C5/AIC4) | `controls`, `assets` |
| `vulnerability_intel` | Vulnerability & Threat Intelligence | `risks` |
| `marisk` | MaRisk (DACH-Banken/Versicherer) | `risks`, `compliance` |
| `lksg` | LkSG (Lieferkettensorgfaltspflichten) | `suppliers` |
| `tisax` | TISAX (Auto-Industrie) | `controls` |
| `risk_quant` | Quantitative Risikoanalyse (SLE/ARO/ALE) | `risks` |
| `eu_authority_reporting` | EU-Behörden-Reporting-Hub | `compliance` |
| `answer_library` | Antwort-Bibliothek (Sicherheits-Fragebögen) | `core` |
| `suppliers` | Lieferanten & Drittparteien | `risks`, `assets` |
| `locations` | Standorte & Infrastruktur | `assets` |
| `persons` | Personen & Kontakte | `core` |
| `interested_parties` | Interessierte Parteien | `core` |
| `change_requests` | Change Management | `controls` |
| `patches` | Patch Management | `vulnerability_intel` |
| `corrective_actions` | Korrekturmaßnahmen | `audits` |
| `risk_appetite` | Risikoappetit & Toleranz | `risks` |
| `risk_treatment_plans` | Risikobehandlungspläne | `risks`, `controls` |
| `analytics` | Analytics & KPI-Dashboard | `core` |
| `report_builder` | Report Builder | `core` |
| `scheduled_reports` | Geplante Berichte | `report_builder` |
| `portfolio_reports` | Portfolio- & Konzern-Reports | `core` |
| `mris` | MRIS — Mythos-resistente Informationssicherheit | `core`, `controls` |
| `bsi_grundschutz` | BSI IT-Grundschutz | `controls`, `compliance` |
| `resource_planning` | Ressourcenplanung | `core` |
| `ocr_processing` | OCR-Verarbeitung (Compliance-Zertifikate) | `compliance` |

A module key that is not in `config/modules.yaml` is never active — gate only on keys from this list.

---

## Where modules are switched on

- **Setup wizard**, step "Module" (`/setup/step7-modules`): recommendations from industry and size
  (`App\Service\Setup\SetupRecommendationEngine`).
- **Admin → Module** (`/admin/modules`, `AdminModuleController`, voter attribute `MODULE_MANAGE`):
  activation pulls in dependencies.
- **Industry presets** (`fixtures/library/presets/*.yaml`, `IndustryPresetApplier`, admin page or
  `LoadIndustryPresetCommand`): add the preset's modules to the organisation's selection, never remove any.

Compliance wizards do not activate modules; a wizard whose modules are inactive is not offered.

---

## ModuleConfigurationService

`App\Service\ModuleConfigurationService` reads `config/modules.yaml` and the organisation's own
selection (`Tenant::getActiveModules()`); an organisation without a selection inherits the instance
default in `config/active_modules.yaml`. Required modules are always active.
`isModuleActiveForTenant($key, $tenant)` answers outside the request (commands, jobs).

```php
// Direct injection (services, controllers)
public function __construct(
    private readonly ModuleConfigurationService $moduleService,
) {}

$isActive = $moduleService->isModuleActive('privacy'); // bool
```

The service is fully autowired — no manual service definition needed.

---

## Pattern 1: FormType Gating via `ModuleAwareFormTrait`

**File:** `src/Form/Trait/ModuleAwareFormTrait.php`

The trait exposes a single protected helper `isModuleActive(string $key): bool`.
The using class must declare a `$moduleConfiguration` property of type
`ModuleConfigurationService`.

### Full Example

```php
<?php

declare(strict_types=1);

namespace App\Form;

use App\Form\Trait\ModuleAwareFormTrait;
use App\Service\ModuleConfigurationService;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;

class RiskType extends AbstractType
{
    use ModuleAwareFormTrait;

    public function __construct(
        private readonly ModuleConfigurationService $moduleConfiguration,
        // ... other services
    ) {}

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // Always-visible core fields
        $builder->add('title', TextType::class, ['label' => 'risk.field.title']);

        // GDPR subset — privacy module only
        if ($this->isModuleActive('privacy')) {
            $builder->add('involvesPersonalData', CheckboxType::class, [
                'label' => 'risk.field.involves_personal_data',
                'required' => false,
            ]);
            $builder->add('involvesSpecialCategoryData', CheckboxType::class, [
                'label' => 'risk.field.involves_special_category_data',
                'required' => false,
                'attr' => [
                    'data-depends-on' => 'risk_form_involvesPersonalData',
                    'data-depends-on-value' => '1',
                ],
            ]);
        }

        // DORA/NIS2 subset
        if ($this->isModuleActive('nis2_dora')) {
            $builder->add('ictRiskCategory', ChoiceType::class, [/* ... */]);
        }

        // AI Governance — EU AI Act Art. 5 prohibited validation
        if ($this->isModuleActive('ai_governance')) {
            $this->addAiAgentFields($builder);
        }
    }
}
```

### Rules

1. Import `ModuleAwareFormTrait` via `use` — do NOT copy the method inline.
2. Declare `private readonly ModuleConfigurationService $moduleConfiguration` via constructor promotion.
3. Always gate entire logical field groups together, not individual fields.
4. Provide the norm reference in a doc comment next to the `if` block (see existing FormTypes for examples).

---

## Pattern 2: Controller Gating

### Preferred: `#[RequiresModule]` attribute (central enforcement)

**Files:** `src/Attribute/RequiresModule.php`, `src/EventListener/RequiresModuleListener.php`

Put the attribute on the controller class (all actions) or on single actions.
It is repeatable; every listed module must be active.

```php
use App\Attribute\RequiresModule;

#[IsGranted('ROLE_MANAGER')]
#[RequiresModule('nis2_dora')]
final class DoraRegisterExportController extends AbstractController { /* ... */ }

// Core controller with one framework-specific action:
#[Route('/incident/{id}/bcm-impact', name: 'app_incident_bcm_impact')]
#[RequiresModule('bcm')]
public function bcmImpact(Incident $incident): Response { /* ... */ }
```

`RequiresModuleListener` runs on `kernel.controller_arguments` (priority 10,
after `#[IsGranted]`). When the module is inactive for the current tenant:

- HTML requests → redirect to `app_dashboard` + `common.module_not_active` flash
  (same behaviour as `checkModuleActive()`),
- JSON callers (action declared to return `JsonResponse`, XHR, JSON
  `Accept`/`Content-Type`) → `403 {"success": false, "error": "module_not_active", "module": "<key>"}`.

The former `routes:` lists in `config/modules.yaml` were never enforced and
have been removed; the attribute on the controller is the single source.

**CI gate (Gate 11, PHPStan rule `tests/PHPStan/Rule/Gate/ModuleGateRule.php`, since T3):**
besides FormType fields (`->add()` of a regulatory field only inside `if ($this->isModuleActive(...))`,
also through a private helper whose every caller is gated), the rule scans every routed action in
`src/Controller/`. If the class name, route path or route name signals an
optional module (DORA/NIS2/KRITIS, privacy/GDPR, BCM, TISAX, BSI, LkSG,
MaRisk, MRIS, training, suppliers, resource planning, analytics, …; see
`ModuleGateRule::CONTROLLER_MODULES`), the action needs `#[RequiresModule]`, a
`checkModuleActive()`/`isModuleActive()` call for one of the matching
modules, or a `// @no-module-gate-required: <reason>` comment above its
attributes (class-level comment covers all actions). The ISO 27001 backbone
modules (assets, risks, controls, incidents, audits, compliance, reviews) are
deliberately not keyword-mapped for pages — but their **downloads** are (S32): a route
whose path looks like a download (`export`, `pdf`, `excel`, `xlsx`, `csv`, `download`,
a `/reports/` segment) and whose path, name or `requirements` name a risk, asset,
incident, control or audit needs that module's gate (`ModuleGateRule::DOWNLOAD_MODULES`).
A generic route such as `/export/{entityType}` passes with a deciding check over a
constant map (`isModuleActive(self::ENTITY_MODULES[$entityType])`). Scheduled reports
re-check `ScheduledReport::TYPE_MODULES` on every run (`Report\ScheduledReportRunner`,
durable test `tests/Contract/ScheduledReportModuleContractTest`).

For explicit tenants outside a request (commands, jobs) use
`ModuleConfigurationService::isModuleActiveForTenant($key, $tenant)`. Never read
module state from `Tenant::getSettings()['modules']` — that key is not written
anywhere.

### Legacy: `ModuleGatedControllerTrait`

**File:** `src/Controller/Trait/ModuleGatedControllerTrait.php`

Use for **whole-form / whole-module gating** where entire controller actions should be
blocked when a module is inactive (e.g., all GDPR controllers when `privacy` is off).

```php
<?php

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Trait\ModuleGatedControllerTrait;
use App\Service\ModuleConfigurationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Contracts\Translation\TranslatorInterface;

class DataBreachController extends AbstractController
{
    use ModuleGatedControllerTrait;

    public function __construct(
        private readonly ModuleConfigurationService $moduleService,
        private readonly TranslatorInterface $translator,
        // ...
    ) {}

    #[Route('/{locale}/data-breach', name: 'app_data_breach_index')]
    public function index(Request $request): Response
    {
        // Gate entire action — redirects to dashboard with flash if inactive
        if ($redirect = $this->checkModuleActive('privacy')) {
            return $redirect;
        }

        // ... controller logic
    }
}
```

The trait requires the using class to have `$moduleService`, `$translator`,
`addFlash()`, and `redirectToRoute()` available (all provided by `AbstractController` +
constructor injection).

### Which Controllers Use Whole-Form Gating

| Module Key | Gated Controllers |
|---|---|
| `privacy` | DataBreachController, ConsentController, DataSubjectRequestController, ProcessingActivityController, DPIAController, DpoDashboardController |
| `bcm` | BusinessContinuityPlanController, BCExerciseController, CrisisTeamController, BcmOfficerDashboardController |
| `nis2_dora` | DoraComplianceController, DoraRegisterExportController, Nis2ComplianceController, Nis2MusExportController, IctProviderLibraryController |
| `lksg` / `bsi_grundschutz` / `mris` | LksgReportExportController / BsiGrundschutzCheckController, Admin/GstoolImportController / Mris*Controller |
| `training` / `suppliers` | TrainingController / SupplierQuestionnaireController, Admin/SupplierCriticalityController |

`ModuleGateRule` is the authoritative inventory — this table lists examples.

### API Platform resources and custom `/api` endpoints

**API Platform resources** run through API Platform's own placeholder
controller, which `RequiresModuleListener` never sees. The module key is
declared once, **on the entity class** (`#[RequiresModule('bcm')] class
BusinessContinuityPlan`), and `App\EventListener\ApiResourceModuleListener`
denies every operation of that resource (`403 {"error": "module_not_active"}`)
via the `_api_resource_class` attribute API Platform puts on each route. An
entity gets the attribute exactly when its HTML controllers are module-gated;
the ISO 27001 backbone entities (Asset, Risk, Control, Incident,
InternalAudit) stay ungated, as their HTML controllers are.

- **Fields of another module.** A field that a FormType adds
  only inside `if ($this->isModuleActive('<m>'))` carries
  `#[ModuleField('<m>')]` on the entity property when it is in a `:write`
  group. `App\Api\ModuleFieldContextBuilder` then ignores it in REST request
  bodies while the module is off, and the MCP gateway neither offers nor
  accepts it. Gate 89 (`check_api_module_fields.py`) keeps forms and
  attributes in step; `tests/EventListener/ApiResourceModuleListenerTest`
  checks that every API resource listed only by optional modules in
  `config/modules.yaml` carries one of their gates (backbone excepted).

- **Embedded relations follow the parent resource.** A relation serialised
  inside a response (embedded object or IRI) is decided by the gate of the
  resource that was requested, not by the related entity's module. If a
  module-owned entity must never appear inside another resource while its
  module is off, gate it in that resource's serialisation groups / provider —
  the listener does not filter payloads.
- **Custom controllers under `/api` gate themselves.** Routes under `/api` or
  `/{_locale}/api` that are plain Symfony controllers carry no
  `_api_resource_class`, so `ApiResourceModuleListener` ignores them. Use
  `#[RequiresModule]` on the action (or, when the module depends on the
  request — `QuickCreateController` per entity type — an
  `isModuleActive()` check returning `403 module_not_active`).

`tests/Api/ApiRouteModuleGateTest.php` walks the real route collection: every
API Platform operation must carry an existing `_api_resource_class`, every
module-owned resource must be denied while its module is off, and every custom
`/api` controller must be listed in its `CUSTOM_API_CONTROLLERS` inventory with
its module decision (`core`, per-request `self`, or the action's
`#[RequiresModule]` key). A new `/api` controller fails that test until it is
classified.

Current custom `/api` controllers: `AlvaHintFormController` (core — hints are
module-filtered by the evaluator), `LiveCountsController`,
`PushSubscriptionController`, `SsoDiscoveryApiController` (authentication),
`WorkflowStepApiController` (workflows), `CorporateStructureController`,
`GlossaryApiController` (all core), `QuickCreateController` (per entity type:
`asset` → `assets`, `crisis-team` / `business-process` → `bcm`) and
`SearchController` (search results are module-filtered by `SearchService`;
the asset / risk / incident previews require `assets` / `risks` /
`incidents`).

---

## Pattern 3: Twig Template Conditionals

Use the `is_module_active()` Twig function (registered as a global Twig extension)
to conditionally render UI sections.

```twig
{# templates/risk/show.html.twig #}
{% trans_default_domain 'risk' %}

{# Core fields — always shown #}
<div class="card-body">
    <p>{{ risk.title }}</p>
</div>

{# GDPR section — only when privacy module is active #}
{% if is_module_active('privacy') %}
<div class="card">
    <div class="card-header">{{ 'risk.section.gdpr'|trans({}, 'risk') }}</div>
    <div class="card-body">
        {{ risk.involvesPersonalData ? 'Yes' : 'No' }}
    </div>
</div>
{% endif %}

{# NIS2/DORA section #}
{% if is_module_active('nis2_dora') %}
    {# ... DORA-specific display #}
{% endif %}
```

---

## Pattern 4: Stimulus `data-depends-on` for Conditional Sub-Fields

Within a module-gated section, use `data-depends-on` to show sub-fields
conditionally based on a parent field's value — without JavaScript round-trips.

```html
<!-- Parent trigger field (rendered via Symfony form) -->
<input type="checkbox"
       id="risk_form_involvesPersonalData"
       name="risk_form[involvesPersonalData]">

<!-- Sub-field: only visible when parent checkbox is checked -->
<div data-depends-on="risk_form_involvesPersonalData"
     data-depends-on-value="1"
     style="display:none">
    <!-- Special category data field -->
</div>
```

In the FormType, add the attributes to the sub-field:

```php
$builder->add('involvesSpecialCategoryData', CheckboxType::class, [
    'attr' => [
        'data-depends-on' => 'risk_form_involvesPersonalData',
        'data-depends-on-value' => '1',
    ],
]);
```

The Stimulus `conditional-fields` controller reads `data-depends-on` and toggles
visibility. For `<select>` triggers, `data-depends-on-value` is the option value
string (e.g., `'ai_agent'` for an asset type select).

---

## Translation Key Convention

Module-gated fields follow the domain of the surrounding FormType.
There is no separate translation domain for module-gated fields.

```yaml
# translations/risk.de.yaml
risk:
  field:
    involves_personal_data: "Betrifft personenbezogene Daten"
    involves_special_category_data: "Betrifft besondere Kategorien (Art. 9 DSGVO)"
    ict_risk_category: "ICT-Risikokategorie (DORA Art. 28)"

# translations/risk.en.yaml  
risk:
  field:
    involves_personal_data: "Involves personal data"
    involves_special_category_data: "Involves special category data (Art. 9 GDPR)"
    ict_risk_category: "ICT risk category (DORA Art. 28)"
```

For the "module not active" flash message, use the `messages` domain:

```yaml
# translations/messages.de.yaml
common:
  module_not_active: 'Modul "%module%" ist nicht aktiviert. Bitte Setup → Module aktivieren.'
```

---

## Adding a New Module-Gated Field

1. **Check which module key applies** — see the module keys above (`config/modules.yaml`).
2. **Add the entity field** + migration (`isTransactional(): false` for DDL).
3. **Add to the FormType** behind `if ($this->isModuleActive('key'))`.
4. **Add `is_module_active('key')` to show/edit templates** if the field needs display.
5. **Add translation keys** to both `.de.yaml` and `.en.yaml` files in the relevant domain.
6. **Document** the norm reference in a code comment next to the gate.

---

## Testing Module Gating

```php
// In a WebTestCase or KernelTestCase:
$moduleService = static::getContainer()->get(ModuleConfigurationService::class);

// Temporarily override active modules for a test:
// Set via config/active_modules.yaml or mock the service.

// Example: test that DORA fields are absent when nis2_dora is inactive
$client->request('GET', '/en/risk/new');
$this->assertSelectorNotExists('[name="risk_form[ictRiskCategory]"]');
```

---

## See Also

- [FORM_AUDIT_2026-05.md](archive/FORM_AUDIT_2026-05.md) — Compliance-Coverage-Matrix per norm
- [CONTRIBUTING.md](../CONTRIBUTING.md#module-gating-pattern) — Form Standards with Module-Gating subsection
- [user-guide/MODULE_AKTIVIERUNG.md](user-guide/MODULE_AKTIVIERUNG.md) — User-facing setup guide
- `config/modules.yaml` — Module definitions and metadata
- `config/active_modules.yaml` — instance default for organisations without their own selection
- `src/Form/Trait/ModuleAwareFormTrait.php` — Trait source
- `src/Controller/Trait/ModuleGatedControllerTrait.php` — Controller trait source
