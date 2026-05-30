<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyParameter;

use App\Entity\OrganizationSecurityProfile;
use App\Service\PolicyParameter\FrameworkConstraintChecker;
use App\Service\PolicyParameter\FrameworkCoverageEvaluator;
use App\Service\PolicyParameter\PolicyBaselineApplier;
use App\Service\PolicyParameter\PolicyBaselineCatalog;
use App\Service\PolicyParameter\PolicyParameterCatalog;
use App\Service\PolicyParameter\PolicyParameterResolver;
use App\Service\PolicyParameter\PolicyProfileManager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PolicyProfileManagerTest extends TestCase
{
    private function manager(): PolicyProfileManager
    {
        $params = new PolicyParameterCatalog(\dirname(__DIR__, 3) . '/config/policy_parameters');
        $baselines = new PolicyBaselineCatalog(\dirname(__DIR__, 3) . '/config/policy_baselines');

        return new PolicyProfileManager(
            $params,
            $baselines,
            new PolicyParameterResolver($params),
            new PolicyBaselineApplier($baselines),
            new FrameworkCoverageEvaluator($params, new FrameworkConstraintChecker()),
        );
    }

    #[Test]
    public function apply_sector_then_resolve_all_uses_baseline_values(): void
    {
        $profile = new OrganizationSecurityProfile();
        $m = $this->manager();

        $m->applySector($profile, 'finance_bafin');
        $resolved = $m->resolveAll($profile);

        self::assertSame('finance_bafin', $profile->getSectorKey());
        self::assertSame('all', $resolved['mfa_scope']);
        self::assertSame('dual_signoff', $resolved['approval_model']);
        self::assertArrayHasKey('log_retention_days', $resolved);
    }

    #[Test]
    public function override_beats_baseline_in_resolve_all(): void
    {
        $profile = new OrganizationSecurityProfile();
        $m = $this->manager();
        $m->applySector($profile, 'finance_bafin');

        $resolved = $m->resolveAll($profile, ['mfa_scope' => 'privileged_only']);

        self::assertSame('privileged_only', $resolved['mfa_scope']);
    }

    #[Test]
    public function resolve_all_without_sector_falls_back_to_catalog_defaults(): void
    {
        $resolved = $this->manager()->resolveAll(new OrganizationSecurityProfile());

        self::assertSame('privileged_external', $resolved['mfa_scope']);
    }
}
