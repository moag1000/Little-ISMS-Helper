<?php

namespace App\Tests\Security\Voter;

use App\Entity\Risk;
use App\Entity\Tenant;
use App\Entity\User;
use App\Security\Voter\RiskVoter;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

#[AllowMockObjectsWithoutExpectations]
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

    public function testGroupCisoCanViewSubsidiaryRisk(): void
    {
        // Phase 9.P1.6: Group-CISO/Konzern-ISB reads across the holding tree.
        // Must use real Tenant objects (not mocks) so isChildOf() works.
        $holding = (new Tenant())->setCode('holding');
        $sub = (new Tenant())->setCode('sub');
        $holding->addSubsidiary($sub);

        $user = $this->createMock(User::class);
        $user->method('getRoles')->willReturn(['ROLE_USER', 'ROLE_GROUP_CISO']);
        $user->method('getTenant')->willReturn($holding);

        $risk = $this->createMock(Risk::class);
        $risk->method('getTenant')->willReturn($sub);

        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->voter->vote($token, $risk, [RiskVoter::VIEW]));
    }

    public function testGroupCisoCannotEditSubsidiaryRisk(): void
    {
        $holding = (new Tenant())->setCode('holding');
        $sub = (new Tenant())->setCode('sub');
        $holding->addSubsidiary($sub);

        $user = $this->createMock(User::class);
        $user->method('getRoles')->willReturn(['ROLE_USER', 'ROLE_MANAGER', 'ROLE_GROUP_CISO']);
        $user->method('getTenant')->willReturn($holding);

        $risk = $this->createMock(Risk::class);
        $risk->method('getTenant')->willReturn($sub);

        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());

        // GROUP_CISO is read-only across the tree — EDIT must still be denied.
        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($token, $risk, [RiskVoter::EDIT]));
    }

    public function testGroupCisoCannotViewSiblingTenantRisk(): void
    {
        // Siblings of the user's tenant are NOT in the subtree, so even a
        // GROUP_CISO must be denied — read-across is strictly downward.
        $holding = (new Tenant())->setCode('holding');
        $sub1 = (new Tenant())->setCode('sub1');
        $sub2 = (new Tenant())->setCode('sub2');
        $holding->addSubsidiary($sub1);
        $holding->addSubsidiary($sub2);

        $user = $this->createMock(User::class);
        $user->method('getRoles')->willReturn(['ROLE_USER', 'ROLE_GROUP_CISO']);
        $user->method('getTenant')->willReturn($sub1);

        $risk = $this->createMock(Risk::class);
        $risk->method('getTenant')->willReturn($sub2);

        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());

        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($token, $risk, [RiskVoter::VIEW]));
    }

    public function testUserWithoutGroupCisoCannotViewSubsidiaryRisk(): void
    {
        // ROLE_MANAGER alone does not grant read-across. GROUP_CISO is required.
        $holding = (new Tenant())->setCode('holding');
        $sub = (new Tenant())->setCode('sub');
        $holding->addSubsidiary($sub);

        $user = $this->createMock(User::class);
        $user->method('getRoles')->willReturn(['ROLE_USER', 'ROLE_MANAGER']);
        $user->method('getTenant')->willReturn($holding);

        $risk = $this->createMock(Risk::class);
        $risk->method('getTenant')->willReturn($sub);

        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());

        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($token, $risk, [RiskVoter::VIEW]));
    }
}
