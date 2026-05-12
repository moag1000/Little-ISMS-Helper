<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\IdentityProvider;
use App\Entity\Tenant;
use App\Entity\User;
use App\Security\Voter\SsoConfigVoter;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

#[AllowMockObjectsWithoutExpectations]
final class SsoConfigVoterTest extends TestCase
{
    private SsoConfigVoter $voter;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->voter  = new SsoConfigVoter();
        $this->tenant = $this->createMock(Tenant::class);
        $this->tenant->method('getId')->willReturn(1);
    }

    private function makeUser(array $roles, ?Tenant $t = null): User
    {
        $u = $this->createMock(User::class);
        $u->method('getRoles')->willReturn($roles);
        $u->method('getTenant')->willReturn($t ?? $this->tenant);
        return $u;
    }

    private function makeIdp(?Tenant $t = null): IdentityProvider
    {
        $idp = $this->createMock(IdentityProvider::class);
        $idp->method('getTenant')->willReturn($t ?? $this->tenant);
        return $idp;
    }

    private function token(User $u): UsernamePasswordToken
    {
        return new UsernamePasswordToken($u, 'main', $u->getRoles());
    }

    #[Test]
    public function superAdminCanConfigureAnything(): void
    {
        $u = $this->makeUser(['ROLE_SUPER_ADMIN']);
        $r = $this->voter->vote($this->token($u), $this->makeIdp(), [SsoConfigVoter::CONFIGURE]);
        self::assertSame(VoterInterface::ACCESS_GRANTED, $r);
    }

    #[Test]
    public function tenantAdminCanConfigureOwnTenantIdp(): void
    {
        $u   = $this->makeUser(['ROLE_ADMIN'], $this->tenant);
        $idp = $this->makeIdp($this->tenant);
        $r   = $this->voter->vote($this->token($u), $idp, [SsoConfigVoter::CONFIGURE]);
        self::assertSame(VoterInterface::ACCESS_GRANTED, $r);
    }

    #[Test]
    public function tenantAdminCannotConfigureOtherTenantIdp(): void
    {
        $other = $this->createMock(Tenant::class);
        $other->method('getId')->willReturn(99);
        $u   = $this->makeUser(['ROLE_ADMIN'], $this->tenant);
        $idp = $this->makeIdp($other);
        $r   = $this->voter->vote($this->token($u), $idp, [SsoConfigVoter::CONFIGURE]);
        self::assertSame(VoterInterface::ACCESS_DENIED, $r);
    }

    #[Test]
    public function managerCannotConfigure(): void
    {
        $u = $this->makeUser(['ROLE_MANAGER'], $this->tenant);
        $r = $this->voter->vote($this->token($u), $this->makeIdp($this->tenant), [SsoConfigVoter::CONFIGURE]);
        self::assertSame(VoterInterface::ACCESS_DENIED, $r);
    }

    #[Test]
    public function globalIdpDeniedToTenantAdmin(): void
    {
        $globalIdp = $this->createMock(IdentityProvider::class);
        $globalIdp->method('getTenant')->willReturn(null);
        $u = $this->makeUser(['ROLE_ADMIN'], $this->tenant);
        $r = $this->voter->vote($this->token($u), $globalIdp, [SsoConfigVoter::CONFIGURE]);
        self::assertSame(VoterInterface::ACCESS_DENIED, $r);
    }

    #[Test]
    public function unsupportedAttributeIsAbstained(): void
    {
        $u = $this->makeUser(['ROLE_ADMIN']);
        $r = $this->voter->vote($this->token($u), $this->makeIdp(), ['view']); // wrong attribute
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $r);
    }
}
