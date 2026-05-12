<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\IdentityProvider;
use App\Entity\Tenant;
use App\Entity\User;
use App\Security\Voter\IdentityProviderVoter;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

#[AllowMockObjectsWithoutExpectations]
final class IdentityProviderVoterTest extends TestCase
{
    private IdentityProviderVoter $voter;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->voter  = new IdentityProviderVoter();
        $this->tenant = $this->createMock(Tenant::class);
        $this->tenant->method('getId')->willReturn(1);
    }

    private function makeUser(array $roles, ?Tenant $tenant = null): User
    {
        $u = $this->createMock(User::class);
        $u->method('getRoles')->willReturn($roles);
        $u->method('getTenant')->willReturn($tenant ?? $this->tenant);
        return $u;
    }

    private function makeIdp(?Tenant $tenant = null): IdentityProvider
    {
        $idp = $this->createMock(IdentityProvider::class);
        $idp->method('getTenant')->willReturn($tenant ?? $this->tenant);
        return $idp;
    }

    private function token(User $u): UsernamePasswordToken
    {
        return new UsernamePasswordToken($u, 'main', $u->getRoles());
    }

    #[Test]
    public function superAdminCanViewGlobalIdp(): void
    {
        $u = $this->makeUser(['ROLE_SUPER_ADMIN']);
        $result = $this->voter->vote($this->token($u), $this->makeIdp(), [IdentityProviderVoter::VIEW]);
        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    #[Test]
    public function adminCanViewSameTenantIdp(): void
    {
        $u = $this->makeUser(['ROLE_ADMIN'], $this->tenant);
        $result = $this->voter->vote($this->token($u), $this->makeIdp($this->tenant), [IdentityProviderVoter::VIEW]);
        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    #[Test]
    public function userCannotViewOtherTenantIdp(): void
    {
        $otherTenant = $this->createMock(Tenant::class);
        $otherTenant->method('getId')->willReturn(99);
        $u = $this->makeUser(['ROLE_USER'], $this->tenant);
        $result = $this->voter->vote($this->token($u), $this->makeIdp($otherTenant), [IdentityProviderVoter::VIEW]);
        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    #[Test]
    public function onlyAdminCanDeleteIdp(): void
    {
        $manager = $this->makeUser(['ROLE_MANAGER'], $this->tenant);
        $result  = $this->voter->vote($this->token($manager), $this->makeIdp($this->tenant), [IdentityProviderVoter::DELETE]);
        self::assertSame(VoterInterface::ACCESS_DENIED, $result);

        $admin   = $this->makeUser(['ROLE_ADMIN'], $this->tenant);
        $result2 = $this->voter->vote($this->token($admin), $this->makeIdp($this->tenant), [IdentityProviderVoter::DELETE]);
        self::assertSame(VoterInterface::ACCESS_GRANTED, $result2);
    }

    #[Test]
    public function unsupportedSubjectIsAbstained(): void
    {
        $u = $this->makeUser(['ROLE_ADMIN']);
        $result = $this->voter->vote($this->token($u), new \stdClass(), [IdentityProviderVoter::VIEW]);
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $result);
    }

    #[Test]
    public function unauthenticatedUserIsDenied(): void
    {
        // Non-User token principal → voter returns ACCESS_DENIED
        $nonUser = $this->createMock(\Symfony\Component\Security\Core\User\UserInterface::class);
        // getRoles returns no matching User instance check
        $token  = new UsernamePasswordToken($nonUser, 'main', []);
        // The voter checks instanceof User (App\Entity\User), nonUser is not that
        $result = $this->voter->vote($token, $this->makeIdp(), [IdentityProviderVoter::VIEW]);
        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    #[Test]
    public function globalIdpDeniedToNonSuperAdmin(): void
    {
        $globalIdp = $this->createMock(IdentityProvider::class);
        $globalIdp->method('getTenant')->willReturn(null); // global = no tenant
        $u = $this->makeUser(['ROLE_ADMIN'], $this->tenant);
        $result = $this->voter->vote($this->token($u), $globalIdp, [IdentityProviderVoter::VIEW]);
        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }
}
