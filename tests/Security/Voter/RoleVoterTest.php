<?php

namespace App\Tests\Security\Voter;

use App\Entity\Role;
use App\Entity\User;
use App\Security\Voter\RoleVoter;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use PHPUnit\Framework\Attributes\Test;

#[AllowMockObjectsWithoutExpectations]
class RoleVoterTest extends TestCase
{
    private RoleVoter $voter;

    protected function setUp(): void
    {
        $this->voter = new RoleVoter();
    }

    private function createUser(array $roles = ['ROLE_USER'], bool $isActive = true, array $permissions = []): User
    {
        $user = $this->createMock(User::class);
        $user->method('getRoles')->willReturn($roles);
        $user->method('isActive')->willReturn($isActive);
        $user->method('hasPermission')->willReturnCallback(function ($perm) use ($permissions) {
            return in_array($perm, $permissions);
        });
        return $user;
    }

    private function createRole(bool $isSystemRole = false): Role
    {
        $role = $this->createMock(Role::class);
        $role->method('isSystemRole')->willReturn($isSystemRole);
        return $role;
    }

    private function createToken(User $user): UsernamePasswordToken
    {
        return new UsernamePasswordToken($user, 'main', $user->getRoles());
    }

    #[Test]
    public function testAdminCanViewRole(): void
    {
        $user = $this->createUser(['ROLE_ADMIN']);
        $role = $this->createRole();
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $role, [RoleVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    #[Test]
    public function testAdminCanCreateRole(): void
    {
        $user = $this->createUser(['ROLE_ADMIN']);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, null, [RoleVoter::CREATE]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    #[Test]
    public function testAdminCanEditCustomRole(): void
    {
        $user = $this->createUser(['ROLE_ADMIN']);
        $role = $this->createRole(false);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $role, [RoleVoter::EDIT]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    #[Test]
    public function testAdminCanDeleteCustomRole(): void
    {
        $user = $this->createUser(['ROLE_ADMIN']);
        $role = $this->createRole(false);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $role, [RoleVoter::DELETE]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    #[Test]
    public function testUserWithPermissionCanViewRole(): void
    {
        $user = $this->createUser(['ROLE_USER'], true, ['role.view']);
        $role = $this->createRole();
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $role, [RoleVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    #[Test]
    public function testUserWithoutPermissionCannotViewRole(): void
    {
        $user = $this->createUser(['ROLE_USER'], true, []);
        $role = $this->createRole();
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $role, [RoleVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    #[Test]
    public function testUserCannotEditSystemRole(): void
    {
        $user = $this->createUser(['ROLE_USER'], true, ['role.edit']);
        $role = $this->createRole(true);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $role, [RoleVoter::EDIT]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    #[Test]
    public function testUserCannotDeleteSystemRole(): void
    {
        $user = $this->createUser(['ROLE_USER'], true, ['role.delete']);
        $role = $this->createRole(true);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $role, [RoleVoter::DELETE]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    #[Test]
    public function testInactiveUserCannotPerformActions(): void
    {
        $user = $this->createUser(['ROLE_USER'], false, ['role.view']);
        $role = $this->createRole();
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $role, [RoleVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }
}
