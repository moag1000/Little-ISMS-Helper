<?php

namespace App\Tests\Security\Voter;

use App\Entity\Incident;
use App\Entity\Tenant;
use App\Entity\User;
use App\Security\Voter\IncidentVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

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
}
