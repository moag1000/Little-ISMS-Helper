<?php

declare(strict_types=1);

namespace App\Tests\Service\Sso;

use App\Entity\IdentityProvider;
use App\Entity\IdentityProviderRoleMapping;
use App\Repository\IdentityProviderRoleMappingRepository;
use App\Service\Sso\ClaimToRoleResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ClaimToRoleResolverTest extends TestCase
{
    private IdentityProviderRoleMappingRepository&MockObject $repo;
    private ClaimToRoleResolver $resolver;

    protected function setUp(): void
    {
        $this->repo     = $this->createMock(IdentityProviderRoleMappingRepository::class);
        $this->resolver = new ClaimToRoleResolver($this->repo);
    }

    private function makeProvider(string $fallback = 'ROLE_USER'): IdentityProvider
    {
        $idp = new IdentityProvider();
        $idp->setSlug('test');
        $idp->setName('Test');
        $idp->setClientId('cid');
        $idp->setDefaultFallbackRole($fallback);
        return $idp;
    }

    private function makeMapping(string $key, string $expr, string $role, int $priority = 0): IdentityProviderRoleMapping
    {
        $m = new IdentityProviderRoleMapping();
        $m->setClaimKey($key);
        $m->setClaimValueExpression($expr);
        $m->setAssignedRole($role);
        $m->setPriority($priority);
        $m->setIsActive(true);
        return $m;
    }

    #[Test]
    public function fallsBackToDefaultFallbackRoleWhenNoMappings(): void
    {
        $provider = $this->makeProvider('ROLE_USER');
        $this->repo->method('findActiveByProvider')->willReturn([]);

        $result = $this->resolver->resolve($provider, ['email' => 'user@acme.com', 'groups' => []]);

        self::assertSame('ROLE_USER', $result->role);
        self::assertFalse($result->matched);
        self::assertStringContainsString('fallback', $result->trace);
    }

    #[Test]
    public function firstPriorityMatchWins(): void
    {
        $provider = $this->makeProvider('ROLE_USER');
        $m1 = $this->makeMapping('groups', 'isms-admin', 'ROLE_ADMIN', 10);
        $m2 = $this->makeMapping('groups', 'isms-manager', 'ROLE_MANAGER', 20);
        $this->repo->method('findActiveByProvider')->willReturn([$m1, $m2]);

        $result = $this->resolver->resolve($provider, ['groups' => ['isms-admin', 'isms-manager']]);

        self::assertSame('ROLE_ADMIN', $result->role);
        self::assertTrue($result->matched);
    }

    #[Test]
    public function higherPriorityNumberLosesToLowerNumber(): void
    {
        $provider = $this->makeProvider('ROLE_USER');
        $m1 = $this->makeMapping('department', 'security', 'ROLE_MANAGER', 5);
        $m2 = $this->makeMapping('department', 'security', 'ROLE_ADMIN', 20);
        $this->repo->method('findActiveByProvider')->willReturn([$m1, $m2]);

        $result = $this->resolver->resolve($provider, ['department' => 'security']);

        self::assertSame('ROLE_MANAGER', $result->role);
    }

    #[Test]
    public function globExpressionMatchesWildcard(): void
    {
        $provider = $this->makeProvider('ROLE_USER');
        $m = $this->makeMapping('groups', 'isms-*', 'ROLE_MANAGER', 0);
        $this->repo->method('findActiveByProvider')->willReturn([$m]);

        $result = $this->resolver->resolve($provider, ['groups' => ['isms-auditor']]);

        self::assertSame('ROLE_MANAGER', $result->role);
        self::assertTrue($result->matched);
    }

    #[Test]
    public function noMatchReturnsFallback(): void
    {
        $provider = $this->makeProvider('ROLE_AUDITOR');
        $m = $this->makeMapping('groups', 'isms-admin', 'ROLE_ADMIN', 0);
        $this->repo->method('findActiveByProvider')->willReturn([$m]);

        $result = $this->resolver->resolve($provider, ['groups' => ['some-other-group']]);

        self::assertSame('ROLE_AUDITOR', $result->role);
        self::assertFalse($result->matched);
    }

    #[Test]
    public function inactiveMappingIsSkippedByRepo(): void
    {
        // The repo only returns active mappings.
        // If the repo returns empty (simulating no active mappings), fallback applies.
        $provider = $this->makeProvider('ROLE_USER');
        $this->repo->method('findActiveByProvider')->willReturn([]);

        $result = $this->resolver->resolve($provider, ['groups' => ['isms-admin']]);

        self::assertSame('ROLE_USER', $result->role);
        self::assertFalse($result->matched);
    }

    #[Test]
    public function assignedPermissionsAreReturnedWithMatch(): void
    {
        $provider = $this->makeProvider('ROLE_USER');
        $m = $this->makeMapping('groups', 'isms-admin', 'ROLE_ADMIN', 0);
        $m->setAssignedPermissions(['perm_risk_write', 'perm_audit_read']);
        $this->repo->method('findActiveByProvider')->willReturn([$m]);

        $result = $this->resolver->resolve($provider, ['groups' => 'isms-admin']);

        self::assertSame(['perm_risk_write', 'perm_audit_read'], $result->assignedPermissions);
    }
}
