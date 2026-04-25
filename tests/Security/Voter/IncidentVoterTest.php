<?php

namespace App\Tests\Security\Voter;

use App\Entity\Incident;
use App\Entity\Tenant;
use App\Entity\User;
use App\Security\Voter\IncidentVoter;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

#[AllowMockObjectsWithoutExpectations]
class IncidentVoterTest extends TestCase
{
    private IncidentVoter $voter;
    private Tenant $tenant;
    private Tenant $otherTenant;

    protected function setUp(): void
    {
        $this->voter = new IncidentVoter();
        $this->tenant = $this->createMock(Tenant::class);
        $this->otherTenant = $this->createMock(Tenant::class);
    }

    private function createUser(array $roles = ['ROLE_USER'], ?Tenant $tenant = null): User
    {
        $user = $this->createMock(User::class);
        $user->method('getRoles')->willReturn($roles);
        $user->method('getTenant')->willReturn($tenant ?? $this->tenant);
        return $user;
    }

    private function createIncident(?Tenant $tenant = null): Incident
    {
        $incident = $this->createMock(Incident::class);
        $incident->method('getTenant')->willReturn($tenant ?? $this->tenant);
        return $incident;
    }

    private function createToken(User $user): UsernamePasswordToken
    {
        return new UsernamePasswordToken($user, 'main', $user->getRoles());
    }

    public function testAdminCanViewAnyIncident(): void
    {
        $user = $this->createUser(['ROLE_ADMIN']);
        $incident = $this->createIncident($this->otherTenant);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $incident, [IncidentVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testAdminCanEditAnyIncident(): void
    {
        $user = $this->createUser(['ROLE_ADMIN']);
        $incident = $this->createIncident($this->otherTenant);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $incident, [IncidentVoter::EDIT]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testAdminCanDeleteAnyIncident(): void
    {
        $user = $this->createUser(['ROLE_ADMIN']);
        $incident = $this->createIncident($this->otherTenant);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $incident, [IncidentVoter::DELETE]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testUserCanViewOwnTenantIncident(): void
    {
        $user = $this->createUser(['ROLE_USER'], $this->tenant);
        $incident = $this->createIncident($this->tenant);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $incident, [IncidentVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testUserCannotViewOtherTenantIncident(): void
    {
        $user = $this->createUser(['ROLE_USER'], $this->tenant);
        $incident = $this->createIncident($this->otherTenant);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $incident, [IncidentVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testUserCannotDeleteIncident(): void
    {
        $user = $this->createUser(['ROLE_USER'], $this->tenant);
        $incident = $this->createIncident($this->tenant);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $incident, [IncidentVoter::DELETE]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testVoterAbstainsForNonIncidentSubject(): void
    {
        $user = $this->createUser(['ROLE_USER']);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, new \stdClass(), [IncidentVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $result);
    }

    public function testGroupCisoCanViewVisibleCrossTenantIncident(): void
    {
        // Phase 9.P2.3: default is visibleToHolding=true, group-CISO sees it.
        $holding = (new Tenant())->setCode('holding');
        $sub = (new Tenant())->setCode('sub');
        $holding->addSubsidiary($sub);

        $user = $this->createMock(User::class);
        $user->method('getRoles')->willReturn(['ROLE_USER', 'ROLE_GROUP_CISO']);
        $user->method('getTenant')->willReturn($holding);

        $incident = $this->createMock(Incident::class);
        $incident->method('getTenant')->willReturn($sub);
        $incident->method('isVisibleToHolding')->willReturn(true);

        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());
        $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->voter->vote($token, $incident, [IncidentVoter::VIEW]));
    }

    public function testGroupCisoCannotViewOptedOutIncident(): void
    {
        // Phase 9.P2.3 opt-out: Tochter flipped visibleToHolding to false
        // (confidential HR case), so even the group-CISO must be denied.
        $holding = (new Tenant())->setCode('holding');
        $sub = (new Tenant())->setCode('sub');
        $holding->addSubsidiary($sub);

        $user = $this->createMock(User::class);
        $user->method('getRoles')->willReturn(['ROLE_USER', 'ROLE_GROUP_CISO']);
        $user->method('getTenant')->willReturn($holding);

        $incident = $this->createMock(Incident::class);
        $incident->method('getTenant')->willReturn($sub);
        $incident->method('isVisibleToHolding')->willReturn(false);

        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());
        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($token, $incident, [IncidentVoter::VIEW]));
    }

    public function testOwnTenantSeesIncidentEvenWhenNotVisibleToHolding(): void
    {
        // The opt-out flag only fires on the cross-tenant path. A user
        // on the same tenant as the incident must always be able to see
        // their own confidential rows.
        $tenant = $this->createMock(Tenant::class);
        $tenant->method('getId')->willReturn(42);

        $user = $this->createMock(User::class);
        $user->method('getRoles')->willReturn(['ROLE_USER']);
        $user->method('getTenant')->willReturn($tenant);

        $incident = $this->createMock(Incident::class);
        $incident->method('getTenant')->willReturn($tenant);
        $incident->method('isVisibleToHolding')->willReturn(false);

        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());
        $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->voter->vote($token, $incident, [IncidentVoter::VIEW]));
    }
}
