<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\Rule\Global;

use App\AlvaHint\Rule\Global\MissingSsoForEnterpriseRule;
use App\Entity\IdentityProvider;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\IdentityProviderRepository;
use App\Repository\UserRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for MissingSsoForEnterpriseRule.
 *
 * Covers: threshold trigger, existing IdP suppresses hint,
 * below-threshold suppression, module/page/role metadata.
 */
#[AllowMockObjectsWithoutExpectations]
final class MissingSsoForEnterpriseRuleTest extends TestCase
{
    private Tenant $tenant;
    private User $user;

    protected function setUp(): void
    {
        $this->tenant = new Tenant();
        $this->user = new User();
    }

    #[Test]
    public function returnsHintWhenUserCountExceedsThresholdAndNoIdpConfigured(): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('countActiveByTenant')->willReturn(21);

        $idpRepo = $this->createMock(IdentityProviderRepository::class);
        $idpRepo->method('findEnabledForTenant')->willReturn([]);

        $rule = new MissingSsoForEnterpriseRule($userRepo, $idpRepo);
        $hint = $rule->evaluate($this->tenant, $this->user);

        self::assertNotNull($hint);
        self::assertSame('global.missing_sso_for_enterprise', $hint->key);
        self::assertSame('warning', $hint->variant);
        self::assertSame(2, $hint->priorityTier);
        self::assertSame('admin_sso_index', $hint->actionRoute);
        self::assertSame('GET', $hint->actionMethod);
        self::assertSame(['ROLE_ADMIN'], $hint->requiredRoles);
        self::assertTrue($hint->dismissible);
    }

    #[Test]
    public function returnsNullWhenActiveIdpExists(): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('countActiveByTenant')->willReturn(50);

        $idp = new IdentityProvider();
        $idpRepo = $this->createMock(IdentityProviderRepository::class);
        $idpRepo->method('findEnabledForTenant')->willReturn([$idp]);

        $rule = new MissingSsoForEnterpriseRule($userRepo, $idpRepo);
        self::assertNull($rule->evaluate($this->tenant, $this->user));
    }

    #[Test]
    public function returnsNullWhenUserCountAtThreshold(): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('countActiveByTenant')->willReturn(20);

        $idpRepo = $this->createMock(IdentityProviderRepository::class);
        $idpRepo->method('findEnabledForTenant')->willReturn([]);

        $rule = new MissingSsoForEnterpriseRule($userRepo, $idpRepo);
        self::assertNull($rule->evaluate($this->tenant, $this->user));
    }

    #[Test]
    public function returnsNullWhenUserCountBelowThreshold(): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('countActiveByTenant')->willReturn(5);

        $idpRepo = $this->createMock(IdentityProviderRepository::class);
        $idpRepo->method('findEnabledForTenant')->willReturn([]);

        $rule = new MissingSsoForEnterpriseRule($userRepo, $idpRepo);
        self::assertNull($rule->evaluate($this->tenant, $this->user));
    }

    #[Test]
    public function hintBodyContainsCountParameter(): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('countActiveByTenant')->willReturn(42);

        $idpRepo = $this->createMock(IdentityProviderRepository::class);
        $idpRepo->method('findEnabledForTenant')->willReturn([]);

        $rule = new MissingSsoForEnterpriseRule($userRepo, $idpRepo);
        $hint = $rule->evaluate($this->tenant, $this->user);

        self::assertNotNull($hint);
        self::assertSame(['%count%' => '42'], $hint->bodyTranslationParams);
    }

    #[Test]
    public function requiresAuthenticationModule(): void
    {
        $rule = new MissingSsoForEnterpriseRule(
            $this->createMock(UserRepository::class),
            $this->createMock(IdentityProviderRepository::class),
        );
        self::assertSame(['authentication'], $rule->requiredModules());
    }

    #[Test]
    public function appliesToSsoIndexAndUserManagementPages(): void
    {
        $rule = new MissingSsoForEnterpriseRule(
            $this->createMock(UserRepository::class),
            $this->createMock(IdentityProviderRepository::class),
        );
        self::assertContains('admin_sso_index', $rule->appliesToPages());
        self::assertContains('user_management_index', $rule->appliesToPages());
    }

    #[Test]
    public function priorityTierIsTwo(): void
    {
        $rule = new MissingSsoForEnterpriseRule(
            $this->createMock(UserRepository::class),
            $this->createMock(IdentityProviderRepository::class),
        );
        self::assertSame(2, $rule->priorityTier());
    }
}
