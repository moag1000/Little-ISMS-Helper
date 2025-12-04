<?php

namespace App\Tests\Security\Voter;

use App\Entity\Control;
use App\Entity\Tenant;
use App\Entity\User;
use App\Security\Voter\ControlVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

class ControlVoterTest extends TestCase
{
    private ControlVoter $voter;
    private Tenant $tenant;
    private Tenant $otherTenant;

    protected function setUp(): void
    {
        $this->voter = new ControlVoter();
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

    private function createControl(?Tenant $tenant = null): Control
    {
        $control = $this->createMock(Control::class);
        $control->method('getTenant')->willReturn($tenant ?? $this->tenant);
        return $control;
    }

    private function createToken(User $user): UsernamePasswordToken
    {
        return new UsernamePasswordToken($user, 'main', $user->getRoles());
    }

    public function testAdminCanViewAnyControl(): void
    {
        $user = $this->createUser(['ROLE_ADMIN']);
        $control = $this->createControl($this->otherTenant);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $control, [ControlVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testAdminCanEditAnyControl(): void
    {
        $user = $this->createUser(['ROLE_ADMIN']);
        $control = $this->createControl($this->otherTenant);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $control, [ControlVoter::EDIT]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testAdminCanDeleteAnyControl(): void
    {
        $user = $this->createUser(['ROLE_ADMIN']);
        $control = $this->createControl($this->otherTenant);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $control, [ControlVoter::DELETE]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testUserCanViewOwnTenantControl(): void
    {
        $user = $this->createUser(['ROLE_USER'], $this->tenant);
        $control = $this->createControl($this->tenant);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $control, [ControlVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testUserCannotViewOtherTenantControl(): void
    {
        $user = $this->createUser(['ROLE_USER'], $this->tenant);
        $control = $this->createControl($this->otherTenant);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $control, [ControlVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testUserCannotDeleteControl(): void
    {
        $user = $this->createUser(['ROLE_USER'], $this->tenant);
        $control = $this->createControl($this->tenant);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $control, [ControlVoter::DELETE]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testVoterAbstainsForNonControlSubject(): void
    {
        $user = $this->createUser(['ROLE_USER']);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, new \stdClass(), [ControlVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $result);
    }
}
