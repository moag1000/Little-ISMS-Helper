# Policy Parameter Catalog — Plan 1: Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the foundation that lets a tenant resolve any policy parameter's effective value (per-run override → tenant profile → industry baseline → catalog default), backed by a versioned YAML catalog and a per-tenant profile entity.

**Architecture:** Mirror the existing `*ConfigResolver` pattern (see `src/Service/*ConfigResolver.php` + `tests/Service/*ConfigResolverTest.php`). YAML catalog is shipped config (like `IndustryBaselineService` / `MappingLibraryLoader` YAML parsing). Tenant values live in a new Doctrine entity `OrganizationSecurityProfile` with optimistic-lock `#[ORM\Version]`. No wizard/UI yet — this slice is loader + entity + resolver, fully unit-testable.

**Tech Stack:** PHP 8.4, Symfony 7.4, Doctrine ORM 3.6, `symfony/yaml`, PHPUnit 13.1.

**Reference spec:** `docs/superpowers/specs/2026-05-30-policy-parameter-catalog-design.md`

---

## File Structure

- Create: `config/policy_parameters/access_control.yaml` — first catalog slice (1 param to prove schema).
- Create: `src/Service/PolicyParameter/PolicyParameterCatalog.php` — loads + validates catalog YAML, exposes param definitions.
- Create: `src/Service/PolicyParameter/ParameterDefinition.php` — value object for one catalog entry.
- Create: `src/Entity/OrganizationSecurityProfile.php` — per-tenant chosen values + org-context flags + version.
- Create: `src/Repository/OrganizationSecurityProfileRepository.php`.
- Create: `src/Service/PolicyParameter/PolicyParameterResolver.php` — resolution chain.
- Create: migration under `migrations/`.
- Test: `tests/Service/PolicyParameter/PolicyParameterCatalogTest.php`, `tests/Service/PolicyParameter/PolicyParameterResolverTest.php`, `tests/Entity/OrganizationSecurityProfileTest.php`.

---

### Task 1: Catalog YAML + ParameterDefinition value object

**Files:**
- Create: `config/policy_parameters/access_control.yaml`
- Create: `src/Service/PolicyParameter/ParameterDefinition.php`
- Test: `tests/Service/PolicyParameter/ParameterDefinitionTest.php`

- [ ] **Step 1: Write the catalog YAML**

`config/policy_parameters/access_control.yaml`:
```yaml
mfa_scope:
  category: access_control
  type: enum
  allowed: [all, privileged_external, privileged_only, none]
  default: privileged_external
  iso_clauses: [A.8.5, A.5.17]
  framework_constraints:
    dora: { min: all, authority: regulatory, source: "DORA Art. 9(3)" }
    nis2: { min: privileged_external, authority: regulatory, source: "NIS2 Art. 21(2)(i)" }
  template_slot:
    interpolate: policy.access.mfa_value
    section_if: { not: none }
  wizard_step: governance_controls
  labels: { de: "MFA-Geltungsbereich", en: "MFA scope" }
```

- [ ] **Step 2: Write the failing test**

`tests/Service/PolicyParameter/ParameterDefinitionTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyParameter;

use App\Service\PolicyParameter\ParameterDefinition;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ParameterDefinitionTest extends TestCase
{
    #[Test]
    public function it_exposes_key_default_and_allowed_values(): void
    {
        $def = ParameterDefinition::fromArray('mfa_scope', [
            'category' => 'access_control',
            'type' => 'enum',
            'allowed' => ['all', 'none'],
            'default' => 'all',
            'wizard_step' => 'governance_controls',
        ]);

        self::assertSame('mfa_scope', $def->key);
        self::assertSame('all', $def->default);
        self::assertSame(['all', 'none'], $def->allowed);
        self::assertSame('governance_controls', $def->wizardStep);
    }

    #[Test]
    public function it_returns_framework_min_when_present(): void
    {
        $def = ParameterDefinition::fromArray('mfa_scope', [
            'type' => 'enum',
            'default' => 'privileged_external',
            'framework_constraints' => [
                'dora' => ['min' => 'all', 'authority' => 'regulatory', 'source' => 'DORA Art. 9(3)'],
            ],
        ]);

        self::assertSame('all', $def->frameworkMin('dora'));
        self::assertSame('regulatory', $def->frameworkAuthority('dora'));
        self::assertNull($def->frameworkMin('nis2'));
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `php bin/phpunit tests/Service/PolicyParameter/ParameterDefinitionTest.php`
Expected: FAIL — class `App\Service\PolicyParameter\ParameterDefinition` not found.

- [ ] **Step 4: Write the value object**

`src/Service/PolicyParameter/ParameterDefinition.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\PolicyParameter;

/**
 * Immutable definition of one policy parameter, loaded from
 * config/policy_parameters/*.yaml. See design spec 2026-05-30.
 */
final readonly class ParameterDefinition
{
    /**
     * @param list<string>           $allowed
     * @param list<string>           $isoClauses
     * @param array<string, mixed>   $frameworkConstraints
     * @param array<string, mixed>   $templateSlot
     * @param array<string, string>  $labels
     */
    public function __construct(
        public string $key,
        public string $category,
        public string $type,
        public mixed $default,
        public array $allowed = [],
        public array $isoClauses = [],
        public array $frameworkConstraints = [],
        public array $templateSlot = [],
        public string $wizardStep = 'governance_controls',
        public array $labels = [],
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(string $key, array $data): self
    {
        return new self(
            key: $key,
            category: (string) ($data['category'] ?? 'uncategorised'),
            type: (string) ($data['type'] ?? 'string'),
            default: $data['default'] ?? null,
            allowed: array_values($data['allowed'] ?? []),
            isoClauses: array_values($data['iso_clauses'] ?? []),
            frameworkConstraints: $data['framework_constraints'] ?? [],
            templateSlot: $data['template_slot'] ?? [],
            wizardStep: (string) ($data['wizard_step'] ?? 'governance_controls'),
            labels: $data['labels'] ?? [],
        );
    }

    public function frameworkMin(string $framework): mixed
    {
        return $this->frameworkConstraints[$framework]['min'] ?? null;
    }

    public function frameworkAuthority(string $framework): ?string
    {
        $authority = $this->frameworkConstraints[$framework]['authority'] ?? null;

        return $authority === null ? null : (string) $authority;
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php bin/phpunit tests/Service/PolicyParameter/ParameterDefinitionTest.php`
Expected: PASS (2 tests).

- [ ] **Step 6: Commit**

```bash
git add config/policy_parameters/access_control.yaml \
        src/Service/PolicyParameter/ParameterDefinition.php \
        tests/Service/PolicyParameter/ParameterDefinitionTest.php
git commit -m "feat(policy-params): ParameterDefinition value object + first catalog slice"
```

---

### Task 2: PolicyParameterCatalog loader

**Files:**
- Create: `src/Service/PolicyParameter/PolicyParameterCatalog.php`
- Test: `tests/Service/PolicyParameter/PolicyParameterCatalogTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Service/PolicyParameter/PolicyParameterCatalogTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyParameter;

use App\Service\PolicyParameter\PolicyParameterCatalog;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PolicyParameterCatalogTest extends TestCase
{
    private function catalog(): PolicyParameterCatalog
    {
        // Points at the real shipped config dir (project_dir/config/policy_parameters).
        return new PolicyParameterCatalog(\dirname(__DIR__, 3) . '/config/policy_parameters');
    }

    #[Test]
    public function it_loads_mfa_scope_from_yaml(): void
    {
        $def = $this->catalog()->get('mfa_scope');

        self::assertSame('mfa_scope', $def->key);
        self::assertSame('privileged_external', $def->default);
        self::assertContains('all', $def->allowed);
    }

    #[Test]
    public function it_lists_all_known_keys(): void
    {
        self::assertContains('mfa_scope', $this->catalog()->keys());
    }

    #[Test]
    public function it_throws_for_unknown_key(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->catalog()->get('does_not_exist');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php bin/phpunit tests/Service/PolicyParameter/PolicyParameterCatalogTest.php`
Expected: FAIL — class `PolicyParameterCatalog` not found.

- [ ] **Step 3: Write the loader**

`src/Service/PolicyParameter/PolicyParameterCatalog.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\PolicyParameter;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

/**
 * Loads + caches the policy-parameter catalog from
 * %kernel.project_dir%/config/policy_parameters/*.yaml.
 */
final class PolicyParameterCatalog
{
    /** @var array<string, ParameterDefinition>|null */
    private ?array $cache = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%/config/policy_parameters')]
        private readonly string $catalogDir,
    ) {
    }

    public function get(string $key): ParameterDefinition
    {
        $all = $this->all();
        if (!isset($all[$key])) {
            throw new \InvalidArgumentException(sprintf('Unknown policy parameter "%s".', $key));
        }

        return $all[$key];
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->all());
    }

    /** @return array<string, ParameterDefinition> */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $defs = [];
        foreach (glob($this->catalogDir . '/*.yaml') ?: [] as $file) {
            /** @var array<string, array<string, mixed>> $parsed */
            $parsed = Yaml::parseFile($file) ?? [];
            foreach ($parsed as $key => $data) {
                $defs[$key] = ParameterDefinition::fromArray($key, $data);
            }
        }

        return $this->cache = $defs;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php bin/phpunit tests/Service/PolicyParameter/PolicyParameterCatalogTest.php`
Expected: PASS (3 tests).

- [ ] **Step 5: Verify container wiring**

Run: `php bin/console lint:container`
Expected: no errors (autowired via `#[Autowire]`).

- [ ] **Step 6: Commit**

```bash
git add src/Service/PolicyParameter/PolicyParameterCatalog.php \
        tests/Service/PolicyParameter/PolicyParameterCatalogTest.php
git commit -m "feat(policy-params): PolicyParameterCatalog YAML loader"
```

---

### Task 3: OrganizationSecurityProfile entity + migration

**Files:**
- Create: `src/Entity/OrganizationSecurityProfile.php`
- Create: `src/Repository/OrganizationSecurityProfileRepository.php`
- Create: migration (generated)
- Test: `tests/Entity/OrganizationSecurityProfileTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Entity/OrganizationSecurityProfileTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\OrganizationSecurityProfile;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OrganizationSecurityProfileTest extends TestCase
{
    #[Test]
    public function it_stores_and_returns_parameter_values(): void
    {
        $profile = new OrganizationSecurityProfile();
        $profile->setValue('mfa_scope', 'all');

        self::assertSame('all', $profile->getValue('mfa_scope'));
        self::assertNull($profile->getValue('unset_param'));
        self::assertSame(['mfa_scope' => 'all'], $profile->getValues());
    }

    #[Test]
    public function it_stores_org_context_flags(): void
    {
        $profile = new OrganizationSecurityProfile();
        $profile->setFlag('has_works_council', true);

        self::assertTrue($profile->getFlag('has_works_council'));
        self::assertFalse($profile->getFlag('has_dpo')); // default false
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php bin/phpunit tests/Entity/OrganizationSecurityProfileTest.php`
Expected: FAIL — class `OrganizationSecurityProfile` not found.

- [ ] **Step 3: Write the entity**

`src/Entity/OrganizationSecurityProfile.php`:
```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\OrganizationSecurityProfileRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Per-tenant single source of truth for policy parameter values + org-context
 * flags. See design spec 2026-05-30. Values resolved via PolicyParameterResolver.
 */
#[ORM\Entity(repositoryClass: OrganizationSecurityProfileRepository::class)]
#[ORM\Table(name: 'organization_security_profile')]
#[ORM\UniqueConstraint(name: 'uniq_osp_tenant', columns: ['tenant_id'])]
class OrganizationSecurityProfile
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(nullable: true)]
    private ?int $tenantId = null;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $values = [];

    /** @var array<string, bool> */
    #[ORM\Column(type: Types::JSON)]
    private array $flags = [];

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $sectorKey = null;

    #[ORM\Version]
    #[ORM\Column]
    private int $lockVersion = 0;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenantId(): ?int
    {
        return $this->tenantId;
    }

    public function setTenantId(?int $tenantId): static
    {
        $this->tenantId = $tenantId;

        return $this;
    }

    public function getValue(string $key): mixed
    {
        return $this->values[$key] ?? null;
    }

    public function setValue(string $key, mixed $value): static
    {
        $this->values[$key] = $value;

        return $this;
    }

    /** @return array<string, mixed> */
    public function getValues(): array
    {
        return $this->values;
    }

    public function getFlag(string $key): bool
    {
        return (bool) ($this->flags[$key] ?? false);
    }

    public function setFlag(string $key, bool $value): static
    {
        $this->flags[$key] = $value;

        return $this;
    }

    public function getSectorKey(): ?string
    {
        return $this->sectorKey;
    }

    public function setSectorKey(?string $sectorKey): static
    {
        $this->sectorKey = $sectorKey;

        return $this;
    }
}
```

- [ ] **Step 4: Write the repository**

`src/Repository/OrganizationSecurityProfileRepository.php`:
```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\OrganizationSecurityProfile;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OrganizationSecurityProfile>
 */
class OrganizationSecurityProfileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OrganizationSecurityProfile::class);
    }

    public function findForTenant(int $tenantId): ?OrganizationSecurityProfile
    {
        return $this->findOneBy(['tenantId' => $tenantId]);
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php bin/phpunit tests/Entity/OrganizationSecurityProfileTest.php`
Expected: PASS (2 tests).

- [ ] **Step 6: Generate + harden the migration**

Run: `php bin/console doctrine:migrations:diff`

Then open the generated `migrations/VersionXXXX.php` and add the transactional override (required for DDL — see CLAUDE.md pitfall #6):
```php
    public function isTransactional(): bool
    {
        return false;
    }
```

- [ ] **Step 7: Verify schema + container**

Run: `php bin/console lint:container`
Expected: no errors.

- [ ] **Step 8: Commit**

```bash
git add src/Entity/OrganizationSecurityProfile.php \
        src/Repository/OrganizationSecurityProfileRepository.php \
        migrations/Version*.php \
        tests/Entity/OrganizationSecurityProfileTest.php
git commit -m "feat(policy-params): OrganizationSecurityProfile entity + migration"
```

---

### Task 4: PolicyParameterResolver (resolution chain)

**Files:**
- Create: `src/Service/PolicyParameter/PolicyParameterResolver.php`
- Test: `tests/Service/PolicyParameter/PolicyParameterResolverTest.php`

Resolution order (spec): `run-override ?? tenant-profile ?? baseline-preset ?? catalog-default`.
In Plan 1 the baseline layer is a passed-in array (Plan 2 supplies the real baseline service); here we prove the chain with an injectable baseline-values map.

- [ ] **Step 1: Write the failing test**

`tests/Service/PolicyParameter/PolicyParameterResolverTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyParameter;

use App\Entity\OrganizationSecurityProfile;
use App\Service\PolicyParameter\PolicyParameterCatalog;
use App\Service\PolicyParameter\PolicyParameterResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PolicyParameterResolverTest extends TestCase
{
    private function resolver(): PolicyParameterResolver
    {
        $catalog = new PolicyParameterCatalog(\dirname(__DIR__, 3) . '/config/policy_parameters');

        return new PolicyParameterResolver($catalog);
    }

    #[Test]
    public function it_falls_back_to_catalog_default(): void
    {
        $value = $this->resolver()->resolve('mfa_scope', profile: null, baseline: [], override: []);

        self::assertSame('privileged_external', $value); // catalog default
    }

    #[Test]
    public function baseline_beats_default(): void
    {
        $value = $this->resolver()->resolve('mfa_scope', profile: null, baseline: ['mfa_scope' => 'all'], override: []);

        self::assertSame('all', $value);
    }

    #[Test]
    public function profile_beats_baseline(): void
    {
        $profile = (new OrganizationSecurityProfile())->setValue('mfa_scope', 'privileged_only');

        $value = $this->resolver()->resolve('mfa_scope', profile: $profile, baseline: ['mfa_scope' => 'all'], override: []);

        self::assertSame('privileged_only', $value);
    }

    #[Test]
    public function override_beats_everything(): void
    {
        $profile = (new OrganizationSecurityProfile())->setValue('mfa_scope', 'privileged_only');

        $value = $this->resolver()->resolve('mfa_scope', profile: $profile, baseline: ['mfa_scope' => 'all'], override: ['mfa_scope' => 'none']);

        self::assertSame('none', $value);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php bin/phpunit tests/Service/PolicyParameter/PolicyParameterResolverTest.php`
Expected: FAIL — class `PolicyParameterResolver` not found.

- [ ] **Step 3: Write the resolver**

`src/Service/PolicyParameter/PolicyParameterResolver.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\PolicyParameter;

use App\Entity\OrganizationSecurityProfile;

/**
 * Resolves the effective value of a policy parameter through the layered chain
 * override → tenant-profile → industry-baseline → catalog-default.
 * Mirrors the existing *ConfigResolver pattern (see tests/Service/*ConfigResolverTest).
 */
final readonly class PolicyParameterResolver
{
    public function __construct(
        private PolicyParameterCatalog $catalog,
    ) {
    }

    /**
     * @param array<string, mixed> $baseline industry-baseline preset values (Plan 2 supplies these)
     * @param array<string, mixed> $override per-run WizardRun override values
     */
    public function resolve(
        string $key,
        ?OrganizationSecurityProfile $profile,
        array $baseline = [],
        array $override = [],
    ): mixed {
        if (array_key_exists($key, $override)) {
            return $override[$key];
        }

        $profileValue = $profile?->getValue($key);
        if ($profileValue !== null) {
            return $profileValue;
        }

        if (array_key_exists($key, $baseline)) {
            return $baseline[$key];
        }

        return $this->catalog->get($key)->default;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php bin/phpunit tests/Service/PolicyParameter/PolicyParameterResolverTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Service/PolicyParameter/PolicyParameterResolver.php \
        tests/Service/PolicyParameter/PolicyParameterResolverTest.php
git commit -m "feat(policy-params): PolicyParameterResolver resolution chain"
```

---

### Task 5: Foundation gate — full local checks

**Files:** none (verification only).

- [ ] **Step 1: Syntax check changed PHP**

Run: `find src/Service/PolicyParameter src/Entity/OrganizationSecurityProfile.php src/Repository/OrganizationSecurityProfileRepository.php -name "*.php" -print0 | xargs -0 -n1 php -l`
Expected: "No syntax errors" for each.

- [ ] **Step 2: Container lint**

Run: `php bin/console lint:container`
Expected: success.

- [ ] **Step 3: Run the Plan-1 test suite**

Run: `php bin/phpunit tests/Service/PolicyParameter tests/Entity/OrganizationSecurityProfileTest.php`
Expected: all PASS (11 tests).

- [ ] **Step 4: Commit (if any baseline files changed)**

```bash
git status   # expect clean working tree; nothing to commit if Tasks 1-4 already committed
```

---

## Self-Review

**Spec coverage (Plan 1 slice):**
- Catalog YAML + loader → Task 1+2 ✓
- Tenant profile (values + org-context flags + version) → Task 3 ✓
- Resolution chain (override→profile→baseline→default) → Task 4 ✓
- Migration with `isTransactional()=false` → Task 3 Step 6 ✓
- (Baselines, framework-constraints, wizard, generation, register → Plans 2-6, out of scope here.)

**Placeholder scan:** none — every step has concrete code/commands.

**Type consistency:** `ParameterDefinition` (Task 1) used by `PolicyParameterCatalog::get()` (Task 2) and `PolicyParameterResolver` (Task 4); `OrganizationSecurityProfile::getValue()` (Task 3) consumed by resolver (Task 4) — signatures match.

**Note:** `OrganizationSecurityProfile` carries `tenantId` as a plain int column (not a relation) to keep the foundation slice decoupled; if the codebase convention is a `ManyToOne` to `Tenant`, adapt in Task 3 Step 3 to match sibling entities.
