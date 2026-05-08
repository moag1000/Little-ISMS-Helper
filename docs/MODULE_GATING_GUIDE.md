# Module Gating Guide — Developer Reference

> Cross-references: [FORM_AUDIT_2026-05.md](FORM_AUDIT_2026-05.md) | [CONTRIBUTING.md](../CONTRIBUTING.md#module-gating-pattern) | [User Guide](user-guide/MODULE_AKTIVIERUNG.md)

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

## 21 Module Keys

### 12 Core Modules (always existed)

| Key | Name | Trigger |
|---|---|---|
| `core` | Core ISMS | Required — always active |
| `assets` | Asset Management | ISO 27001 §8.1 |
| `risks` | Risk Management | ISO 27001 §6.1 |
| `controls` | Control Management (SoA) | ISO 27001 Annex A |
| `incidents` | Incident Management | ISO 27001 A.5.26 |
| `audits` | Audit Management | ISO 27001 §9.2 |
| `training` | Training & Awareness | ISO 27001 A.6.3 |
| `reviews` | Management Review | ISO 27001 §9.3 |
| `bcm` | Business Continuity Management | ISO 22301 |
| `compliance` | Multi-Framework Compliance | Framework import active |
| `authentication` | User & Role Management | Always active |
| `audit_logging` | Audit Logging | Always active |

### 8 New Module Keys (T31 Sprint 1 — added 2026-05)

| Key | Name | Setup-Wizard Question | Consolidates |
|---|---|---|---|
| `privacy` | Privacy & Datenschutz | "Verarbeiten Sie personenbezogene Daten?" | GDPR + ISO 27701 |
| `nis2_dora` | NIS2 & DORA Compliance | "EU-Cyber-Resilience-pflichtig (KRITIS/Bank/Vers)?" | NIS2 + DORA |
| `ai_governance` | AI Governance | "Setzen Sie KI-Systeme ein?" | EU AI Act + ISO 42001 |
| `cloud_security` | Cloud Security | "Cloud-Provider/User?" | ISO 27017/18, BSI C5/AIC4 |
| `vulnerability_intel` | Vulnerability & Threat Intelligence | "Aktives Vuln-/Threat-Mgmt?" | Vuln-Mgmt + MITRE ATT&CK |
| `marisk` | MaRisk (DACH-Banken/Versicherer) | "Bank/Versicherung in DACH?" | MaRisk outsourcing rules |
| `tisax` | TISAX (Auto-Industrie) | "Auto-Industrie-Lieferkette?" | VDA ISA |
| `quantitative_risk` | Quantitative Risk (FAIR/CRQ) | "Quantitative Risk-Analyse gewünscht?" | FAIR methodology |

### 1 Standalone Module

| Key | Name | Notes |
|---|---|---|
| `bsi_grundschutz` | BSI IT-Grundschutz | Eigenständige BSI-Methodik, separates Modul |

---

## ModuleConfigurationService

`App\Service\ModuleConfigurationService` reads `config/modules.yaml` and
`config/active_modules.yaml` (per-tenant override) to determine which modules
are active for the current tenant.

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

## Pattern 2: Controller Gating via `ModuleGatedControllerTrait`

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
| `privacy` | DataBreachController, ConsentController, DataSubjectRequestController, ProcessingActivityController, DPIAController |
| `bcm` | BusinessContinuityPlanController, BCExerciseController, CrisisTeamController |

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
    <div class="card-header">{{ 'risk.section.gdpr'|trans }}</div>
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

The Stimulus `form-conditional` controller reads `data-depends-on` and toggles
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
  module_not_active: "Das Modul '%module%' ist für Ihren Tenant nicht aktiviert."

# translations/messages.en.yaml
common:
  module_not_active: "The module '%module%' is not active for your tenant."
```

---

## Adding a New Module-Gated Field

1. **Check which module key applies** — see the 21 Module Keys table above.
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

- [FORM_AUDIT_2026-05.md](FORM_AUDIT_2026-05.md) — Compliance-Coverage-Matrix per norm
- [CONTRIBUTING.md](../CONTRIBUTING.md#module-gating-pattern) — Form Standards with Module-Gating subsection
- [user-guide/MODULE_AKTIVIERUNG.md](user-guide/MODULE_AKTIVIERUNG.md) — User-facing setup guide
- `config/modules.yaml` — Module definitions and metadata
- `config/active_modules.yaml` — Per-tenant activation overrides
- `src/Form/Trait/ModuleAwareFormTrait.php` — Trait source
- `src/Controller/Trait/ModuleGatedControllerTrait.php` — Controller trait source
