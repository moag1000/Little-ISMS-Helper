<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\RiskIncidentLink;
use App\Entity\Tenant;
use App\Entity\User;
use App\Security\Voter\RiskIncidentLinkVoter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Unit tests for RiskIncidentLinkVoter.
 * Sprint 9B / F16.
 */
final class RiskIncidentLinkVoterTest extends TestCase
{
    private RiskIncidentLinkVoter $voter;

    protected function setUp(): void
    {
        $this->voter = new RiskIncidentLinkVoter();
    }

    private function createToken(User $user): UsernamePasswordToken
    {
        return new UsernamePasswordToken($user, 'main', $user->getRoles());
    }

    private function createUser(?Tenant $tenant, array $roles): User
    {
        $user = new User();
        $user->setTenant($tenant);
        $user->setRoles($roles);
        return $user;
    }

    private function makeLinkForTenant(Tenant $tenant): RiskIncidentLink
    {
        $link = new RiskIncidentLink();
        $link->setTenant($tenant);
        return $link;
    }

    #[Test]
    public function adminCanViewCreateDelete(): void
    {
        $tenant = new Tenant();
        $user   = $this->createUser($tenant, ['ROLE_ADMIN', 'ROLE_USER']);
        $link   = $this->makeLinkForTenant($tenant);
        $token  = $this->createToken($user);

        self::assertTrue($this->voter->vote($token, $link, [RiskIncidentLinkVoter::VIEW]) > 0);
        self::assertTrue($this->voter->vote($token, $link, [RiskIncidentLinkVoter::CREATE]) > 0);
        self::assertTrue($this->voter->vote($token, $link, [RiskIncidentLinkVoter::DELETE]) > 0);
    }

    #[Test]
    public function regularUserCanViewSameTenant(): void
    {
        $tenant = new Tenant();
        $user   = $this->createUser($tenant, ['ROLE_USER']);
        $link   = $this->makeLinkForTenant($tenant);
        $token  = $this->createToken($user);

        self::assertTrue($this->voter->vote($token, $link, [RiskIncidentLinkVoter::VIEW]) > 0);
    }

    #[Test]
    public function regularUserCannotCreateOrDelete(): void
    {
        $tenant = new Tenant();
        $user   = $this->createUser($tenant, ['ROLE_USER']);
        $link   = $this->makeLinkForTenant($tenant);
        $token  = $this->createToken($user);

        self::assertLessThanOrEqual(0, $this->voter->vote($token, $link, [RiskIncidentLinkVoter::CREATE]));
        self::assertLessThanOrEqual(0, $this->voter->vote($token, $link, [RiskIncidentLinkVoter::DELETE]));
    }

    #[Test]
    public function managerCanCreateAndDelete(): void
    {
        $tenant = new Tenant();
        $user   = $this->createUser($tenant, ['ROLE_MANAGER', 'ROLE_USER']);
        $link   = $this->makeLinkForTenant($tenant);
        $token  = $this->createToken($user);

        self::assertTrue($this->voter->vote($token, $link, [RiskIncidentLinkVoter::CREATE]) > 0);
        self::assertTrue($this->voter->vote($token, $link, [RiskIncidentLinkVoter::DELETE]) > 0);
    }

    #[Test]
    public function userFromDifferentTenantCannotView(): void
    {
        $tenantA = new Tenant();
        $tenantB = new Tenant();
        $user    = $this->createUser($tenantA, ['ROLE_USER']);
        $link    = $this->makeLinkForTenant($tenantB);
        $token   = $this->createToken($user);

        self::assertLessThanOrEqual(0, $this->voter->vote($token, $link, [RiskIncidentLinkVoter::VIEW]));
    }

    #[Test]
    public function unauthenticatedUserIsDenied(): void
    {
        $tenant  = new Tenant();
        $link    = $this->makeLinkForTenant($tenant);
        $anonToken = new \Symfony\Component\Security\Core\Authentication\Token\NullToken();

        self::assertLessThanOrEqual(0, $this->voter->vote($anonToken, $link, [RiskIncidentLinkVoter::VIEW]));
    }

    #[Test]
    public function voterAbstainsOnNonLinkSubject(): void
    {
        $user   = $this->createUser(new Tenant(), ['ROLE_USER']);
        $token  = $this->createToken($user);
        $other  = new \stdClass();

        self::assertSame(0, $this->voter->vote($token, $other, [RiskIncidentLinkVoter::VIEW]));
    }
}
