<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyParameter;

use App\Entity\OrganizationSecurityProfile;
use App\Service\PolicyParameter\PolicyBaselineApplier;
use App\Service\PolicyParameter\PolicyBaselineCatalog;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PolicyBaselineApplierTest extends TestCase
{
    private function applier(): PolicyBaselineApplier
    {
        return new PolicyBaselineApplier(
            new PolicyBaselineCatalog(\dirname(__DIR__, 3) . '/config/policy_baselines')
        );
    }

    #[Test]
    public function it_prefills_profile_values_flags_and_sector(): void
    {
        $profile = new OrganizationSecurityProfile();

        $this->applier()->apply('finance_bafin', $profile);

        self::assertSame('finance_bafin', $profile->getSectorKey());
        self::assertSame('dual_signoff', $profile->getValue('approval_model'));
        self::assertSame('all', $profile->getValue('mfa_scope'));
        self::assertTrue($profile->getFlag('has_works_council'));
    }

    #[Test]
    public function it_does_not_overwrite_existing_explicit_values(): void
    {
        $profile = (new OrganizationSecurityProfile())->setValue('mfa_scope', 'privileged_only');

        $this->applier()->apply('finance_bafin', $profile);

        self::assertSame('privileged_only', $profile->getValue('mfa_scope'));
        self::assertSame('dual_signoff', $profile->getValue('approval_model'));
    }
}
