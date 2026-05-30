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

        self::assertSame('privileged_external', $value);
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
