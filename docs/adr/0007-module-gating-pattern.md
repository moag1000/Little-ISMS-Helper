# ADR-0007: 40+ Module Gating Architecture

**Status:** Accepted  
**Date:** 2026-01-15  
**Deciders:** moag1000  
**Tags:** modules, feature-flags, compliance, multi-framework, gating

---

## Context

Little ISMS Helper implements compliance features for ISO 27001, GDPR, NIS2, DORA, BSI IT-
Grundschutz, TISAX, BCM (ISO 22301), and several niche frameworks. Operators range from:

- A small law firm that needs ISO 27001 + GDPR only
- A financial institution needing DORA + NIS2 + ISO 27001
- An automotive Tier-1 supplier requiring TISAX + ISO 27001
- A German KRITIS operator requiring BSI IT-Grundschutz + NIS2-UmsuCG

If all features are always active:
1. **UI overwhelm:** Non-DORA users see ICT-risk and digital-resilience fields everywhere.
2. **Performance:** Loading all framework-specific queries, joins, and eager-loaded relations
   regardless of relevance adds CPU and memory overhead.
3. **Audit noise:** ISO 27001 audit reports including DORA fields confuse auditors and generate
   finding notices for "unused fields".
4. **Data model coherence:** GDPR Art. 35 DPIA fields on ProcessingActivity are meaningless to
   a non-GDPR tenant.

Feature flags (true/false per deployment) were considered but rejected: the application is
multi-tenant, and different tenants within one deployment need different feature sets. A
per-deployment flag cannot serve a MSP hosting both a law firm and a bank.

---

## Decision

**Implement a two-layer per-tenant module activation system with 40 named module keys.**

### Layer 1: `config/modules.yaml` — global module catalogue

Defines all 40 module keys with metadata:

```yaml
modules:
    core:          { required: true,  label: 'Core ISMS' }
    authentication: { required: true,  label: 'Authentication' }
    privacy:       { required: false, label: 'GDPR / Privacy' }
    nis2_dora:     { required: false, label: 'NIS2 / DORA' }
    bsi_grundschutz: { required: false, label: 'BSI IT-Grundschutz' }
    tisax:         { required: false, label: 'TISAX' }
    bcm:           { required: false, label: 'Business Continuity' }
    # … 33 further keys
```

Six modules are required (`core`, `authentication`, `documents`, `audit_logging`, `workflows`,
`objectives`) and cannot be deactivated.

### Layer 2: `config/active_modules.yaml` — per-tenant overrides

Stores activation state per tenant ID. Admin UI at `/admin/modules` writes to this file (or to a
DB table on hosted deployments).

### Enforcement points

Three canonical enforcement patterns:

**FormType** (via `ModuleAwareFormTrait`):
```php
if ($this->isModuleActive('privacy')) {
    $builder->add('gdprField', ...);
}
```

**Controller** (via `ModuleGatedControllerTrait`):
```php
if ($redirect = $this->checkModuleActive('nis2_dora')) return $redirect;
```

**Twig template** (via global `is_module_active()` function):
```twig
{% if is_module_active('bcm') %}…{% endif %}
```

Every feature that relates to an optional compliance framework MUST be module-gated. Ungated DORA
fields, ungated GDPR routes, or ungated BSI Grundschutz queries are considered bugs. The CLAUDE.md
§"Module-Awareness" section contains the enforcement rule and is referenced in PR checklist.

---

## Consequences

### Positive

- **Right-sized UI:** Each tenant sees only the features they have activated. Onboarding is
  significantly simpler for narrow-scope customers.
- **MSP support:** A single installation can simultaneously serve a GDPR-only tenant and a
  DORA+NIS2 tenant with no data-model changes.
- **Performance:** Module-gated queries (e.g., DPIA counts, DORA ICT-risk aggregations) are
  skipped entirely for non-subscribed tenants.
- **Audit clarity:** Generated reports contain only relevant framework fields.

### Negative

- **Gate discipline required:** Every new feature needs a module check. When a developer forgets,
  a non-subscribed tenant silently sees features they should not. Code review must check for module
  gates on all compliance-specific additions.
- **Testing matrix:** PHPUnit scenarios must cover both `moduleActive=true` and `moduleActive=false`
  paths for gated features. Test coverage gaps hide behind the happy path.
- **Config file write on hosted deployments:** Writing `config/active_modules.yaml` at runtime
  from a web process requires the file to be writable by the webserver user — a security concern
  on multi-app shared hosting. Consider migrating per-tenant state to a DB column in a future
  sprint (deferred — currently tracked in `config/active_modules.yaml` and a DB fallback exists).

---

## Module Key Reference

Full catalogue: `config/modules.yaml`. Key groups:

| Group | Module keys |
|---|---|
| Required (6) | `core`, `authentication`, `documents`, `audit_logging`, `workflows`, `objectives` |
| ISO 27001 | `annex_a`, `soa`, `risk_treatment`, `internal_audit` |
| GDPR | `privacy`, `dpia`, `dsr`, `consent_management` |
| DORA / NIS2 | `nis2_dora`, `ict_risk`, `digital_resilience`, `third_party_risk` |
| BSI | `bsi_grundschutz`, `bsi_c5`, `bsi_kritis` |
| Automotive | `tisax`, `vda_isa` |
| BCM | `bcm`, `bc_plans`, `bc_exercises`, `crisis_team` |
| Operations | `change_management`, `patch_management`, `vulnerability_mgmt`, `crypto` |
| Reporting | `analytics`, `scheduled_reports`, `group_reporting` |

---

## References

- `config/modules.yaml` — full module catalogue
- `config/active_modules.yaml` — per-tenant activation state
- `src/Form/Trait/ModuleAwareFormTrait.php`
- `src/Controller/Trait/ModuleGatedControllerTrait.php`
- `src/Service/ModuleConfigurationService.php`
- `src/Twig/ModuleExtension.php` — `is_module_active()` global function
- `docs/MODULE_GATING_GUIDE.md` — full gating guide with examples
- CLAUDE.md §"Module-Awareness"
