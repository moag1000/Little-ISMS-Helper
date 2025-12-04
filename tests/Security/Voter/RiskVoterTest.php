<?php

namespace App\Tests\Security\Voter;

use App\Entity\Risk;
use App\Entity\Tenant;
use App\Entity\User;
use App\Security\Voter\RiskVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

class RiskVoterTest extends TestCase
{
    private RiskVoter $voter;
    private Tenant $tenant;
    private Tenant $otherTenant;

    protected function setUp(): void
    {
        $this->voter = new RiskVoter();
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

    private function createRisk(?Tenant $tenant = null): Risk
    {
        $risk = $this->createMock(Risk::class);
        $risk->method('getTenant')->willReturn($tenant ?? $this->tenant);
        return $risk;
    }

    private function createToken(User $user): UsernamePasswordToken
    {
        return new UsernamePasswordToken($user, 'main', $user->getRoles());
    }

    public function testAdminCanViewAnyRisk(): void
    {
        $user = $this->createUser(['ROLE_ADMIN']);
        $risk = $this->createRisk($this->otherTenant);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $risk, [RiskVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testAdminCanEditAnyRisk(): void
    {
        $user = $this->createUser(['ROLE_ADMIN']);
        $risk = $this->createRisk($this->otherTenant);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $risk, [RiskVoter::EDIT]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testAdminCanDeleteAnyRisk(): void
    {
        $user = $this->createUser(['ROLE_ADMIN']);
        $risk = $this->createRisk($this->otherTenant);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $risk, [RiskVoter::DELETE]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testUserCanViewOwnTenantRisk(): void
    {
        $user = $this->createUser(['ROLE_USER'], $this->tenant);
        $risk = $this->createRisk($this->tenant);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $risk, [RiskVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testUserCannotViewOtherTenantRisk(): void
    {
        $user = $this->createUser(['ROLE_USER'], $this->tenant);
        $risk = $this->createRisk($this->otherTenant);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $risk, [RiskVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testUserCannotDeleteRisk(): void
    {
        $user = $this->createUser(['ROLE_USER'], $this->tenant);
        $risk = $this->createRisk($this->tenant);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $risk, [RiskVoter::DELETE]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testVoterAbstainsForNonRiskSubject(): void
    {
        $user = $this->createUser(['ROLE_USER']);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, new \stdClass(), [RiskVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $result);
    }
}
