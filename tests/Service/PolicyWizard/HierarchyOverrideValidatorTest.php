<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyWizard;

use App\Entity\Tenant;
use App\Entity\WizardRun;
use App\Service\PolicyWizard\HierarchyOverrideValidator;
use App\Service\PolicyWizard\WizardStepKeys;
use App\Service\TenantSettingResolver\OverrideMode;
use App\Service\TenantSettingResolver\SettingProviderInterface;
use App\Service\TenantSettingResolver\TenantSettingResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Policy-Wizard W2-A — HierarchyOverrideValidator behaviour matrix.
 *
 * Each test exercises one of the five override-modes from architecture
 * §7.3 plus the no-conflict happy paths and the "no parent value at
 * all" pass-through.
 */
final class HierarchyOverrideValidatorTest extends TestCase
{
    private function makeRun(Tenant $tenant, array $inputs): WizardRun
    {
        $run = new WizardRun();
        $run->setTenant($tenant);
        $run->setMode(WizardStepKeys::MODE_FULL);
        $run->setInputs($inputs);
        return $run;
    }

    private function makeTenantStub(int $id, array $ancestors = [])
    {
        $stub = $this->createStub(Tenant::class);
        $stub->method('getId')->willReturn($id);
        $stub->method('getAllAncestors')->willReturn($ancestors);
        return $stub;
    }

    /**
     * @param array<string, OverrideMode>      $modes
     * @param array<int, array<string, mixed>> $stored
     */
    private function makeResolver(array $modes, array $stored, array $defaults = []): TenantSettingResolver
    {
        $provider = new class ($modes, $stored, $defaults) implements SettingProviderInterface {
            public function __construct(
                private readonly array $modes,
                private readonly array $stored,
                private readonly array $defaults = [],
            ) {
            }

            public function getOverrideMode(string $key): OverrideMode
            {
                return $this->modes[$key] ?? OverrideMode::ForbiddenToRelax;
            }

            public function getStoredValue(Tenant $tenant, string $key): mixed
            {
                $tid = $tenant->getId();
                if ($tid === null) {
                    return null;
                }
                return $this->stored[$tid][$key] ?? null;
            }

            public function getGlobalDefault(string $key, mixed $default): mixed
            {
                return $this->defaults[$key] ?? $default;
            }
        };
        return new TenantSettingResolver($provider);
    }

    #[Test]
    public function noConflictWhenChildValueWithinCeiling(): void
    {
        $parent = $this->makeTenantStub(1);
        $child = $this->makeTenantStub(2, [$parent]);

        $resolver = $this->makeResolver(
            modes: ['risk.appetite_tier' => OverrideMode::CeilingOnly],
            stored: [1 => ['risk.appetite_tier' => 3]],
        );
        $validator = new HierarchyOverrideValidator($resolver);

        $run = $this->makeRun($child, [
            WizardStepKeys::STEP_RISK_CLASSIFICATION => ['risk_appetite_tier' => 2],
        ]);

        self::assertSame([], $validator->validate($run));
    }

    #[Test]
    public function ceilingOnlyConflictWhenChildExceedsParent(): void
    {
        $parent = $this->makeTenantStub(1);
        $child = $this->makeTenantStub(2, [$parent]);

        $resolver = $this->makeResolver(
            modes: ['risk.appetite_tier' => OverrideMode::CeilingOnly],
            stored: [1 => ['risk.appetite_tier' => 3]],
        );
        $validator = new HierarchyOverrideValidator($resolver);

        $run = $this->makeRun($child, [
            WizardStepKeys::STEP_RISK_CLASSIFICATION => ['risk_appetite_tier' => 5],
        ]);

        $conflicts = $validator->validate($run);
        self::assertCount(1, $conflicts, 'Child tier 5 must conflict against parent ceiling 3.');
        self::assertSame('risk.appetite_tier', $conflicts[0]['key']);
        self::assertSame(3, $conflicts[0]['parent_value']);
        self::assertSame(5, $conflicts[0]['child_value']);
        self::assertSame(OverrideMode::CeilingOnly, $conflicts[0]['mode']);
    }

    #[Test]
    public function reviewIntervalCeilingFlagsChildAboveParent(): void
    {
        $parent = $this->makeTenantStub(1);
        $child = $this->makeTenantStub(2, [$parent]);

        $resolver = $this->makeResolver(
            modes: ['policy.review_interval_months' => OverrideMode::CeilingOnly],
            stored: [1 => ['policy.review_interval_months' => 12]],
        );
        $validator = new HierarchyOverrideValidator($resolver);

        $run = $this->makeRun($child, [
            WizardStepKeys::STEP_LIFECYCLE => ['default_review_interval_months' => 24],
        ]);

        $conflicts = $validator->validate($run);
        self::assertCount(1, $conflicts);
        self::assertSame('policy.review_interval_months', $conflicts[0]['key']);
    }

    #[Test]
    public function backupRpoCeilingPassesWhenChildIsStricter(): void
    {
        $parent = $this->makeTenantStub(1);
        $child = $this->makeTenantStub(2, [$parent]);

        $resolver = $this->makeResolver(
            modes: ['backup.rpo_hours' => OverrideMode::CeilingOnly],
            stored: [1 => ['backup.rpo_hours' => 24]],
        );
        $validator = new HierarchyOverrideValidator($resolver);

        $run = $this->makeRun($child, [
            WizardStepKeys::STEP_OPERATIONAL_BASELINES => ['backup_rpo_hours' => 12],
        ]);

        self::assertSame([], $validator->validate($run));
    }

    #[Test]
    public function noConflictsWhenParentHasNoStoredValue(): void
    {
        $parent = $this->makeTenantStub(1);
        $child = $this->makeTenantStub(2, [$parent]);

        // No stored values, no defaults — resolver returns null parent_value.
        $resolver = $this->makeResolver(
            modes: ['risk.appetite_tier' => OverrideMode::CeilingOnly],
            stored: [],
        );
        $validator = new HierarchyOverrideValidator($resolver);

        $run = $this->makeRun($child, [
            WizardStepKeys::STEP_RISK_CLASSIFICATION => ['risk_appetite_tier' => 5],
        ]);

        self::assertSame([], $validator->validate($run));
    }

    #[Test]
    public function tenantWithoutAncestorsHasNoConflicts(): void
    {
        $standalone = $this->makeTenantStub(1);

        $resolver = $this->makeResolver(modes: [], stored: []);
        $validator = new HierarchyOverrideValidator($resolver);

        $run = $this->makeRun($standalone, [
            WizardStepKeys::STEP_RISK_CLASSIFICATION => ['risk_appetite_tier' => 5],
        ]);

        self::assertSame([], $validator->validate($run));
    }

    #[Test]
    public function inputsWithoutMappedFieldsAreSkipped(): void
    {
        $parent = $this->makeTenantStub(1);
        $child = $this->makeTenantStub(2, [$parent]);

        $resolver = $this->makeResolver(
            modes: ['risk.appetite_tier' => OverrideMode::CeilingOnly],
            stored: [1 => ['risk.appetite_tier' => 1]],
        );
        $validator = new HierarchyOverrideValidator($resolver);

        // Inputs only carry org-scope; risk-classification step has not run.
        $run = $this->makeRun($child, [
            WizardStepKeys::STEP_ORG_SCOPE => ['legal_name' => 'X GmbH'],
        ]);

        self::assertSame([], $validator->validate($run));
    }

    #[Test]
    public function multipleConflictsReportedTogether(): void
    {
        $parent = $this->makeTenantStub(1);
        $child = $this->makeTenantStub(2, [$parent]);

        $resolver = $this->makeResolver(
            modes: [
                'risk.appetite_tier' => OverrideMode::CeilingOnly,
                'policy.review_interval_months' => OverrideMode::CeilingOnly,
                'backup.rpo_hours' => OverrideMode::CeilingOnly,
            ],
            stored: [
                1 => [
                    'risk.appetite_tier' => 2,
                    'policy.review_interval_months' => 12,
                    'backup.rpo_hours' => 6,
                ],
            ],
        );
        $validator = new HierarchyOverrideValidator($resolver);

        $run = $this->makeRun($child, [
            WizardStepKeys::STEP_RISK_CLASSIFICATION => [
                'risk_appetite_tier' => 4,
                'review_interval_months' => 18,
            ],
            WizardStepKeys::STEP_OPERATIONAL_BASELINES => ['backup_rpo_hours' => 24],
            WizardStepKeys::STEP_LIFECYCLE => ['default_review_interval_months' => 18],
        ]);

        $conflicts = $validator->validate($run);
        $keys = array_column($conflicts, 'key');
        sort($keys);

        self::assertSame([
            'backup.rpo_hours',
            'policy.review_interval_months',
            'policy.review_interval_months',
            'risk.appetite_tier',
        ], $keys);
    }
}
