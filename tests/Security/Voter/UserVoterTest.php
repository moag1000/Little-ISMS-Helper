<?php

namespace App\Tests\Security\Voter;

use App\Entity\User;
use App\Security\Voter\UserVoter;
use App\Service\InitialAdminService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

class UserVoterTest extends TestCase
{
    private UserVoter $voter;
    private InitialAdminService $initialAdminService;

    protected function setUp(): void
    {
        $this->initialAdminService = $this->createMock(InitialAdminService::class);
        $this->voter = new UserVoter($this->initialAdminService);
    }

    private function createUser(int $id = 1, array $roles = ['ROLE_USER'], bool $isActive = true, array $permissions = []): User
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn($id);
        $user->method('getRoles')->willReturn($roles);
        $user->method('isActive')->willReturn($isActive);
        $user->method('hasPermission')->willReturnCallback(function ($perm) use ($permissions) {
            return in_array($perm, $permissions);
        });
        return $user;
    }

    private function createToken(User $user): UsernamePasswordToken
    {
        return new UsernamePasswordToken($user, 'main', $user->getRoles());
    }

    public function testAdminCanViewAnyUser(): void
    {
        $currentUser = $this->createUser(1, ['ROLE_ADMIN']);
        $targetUser = $this->createUser(2);
        $token = $this->createToken($currentUser);

        $result = $this->voter->vote($token, $targetUser, [UserVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testAdminCanEditAnyUser(): void
    {
        $currentUser = $this->createUser(1, ['ROLE_ADMIN']);
        $targetUser = $this->createUser(2);
        $token = $this->createToken($currentUser);

        $result = $this->voter->vote($token, $targetUser, [UserVoter::EDIT]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testUserCanViewThemselves(): void
    {
        $user = $this->createUser(1, ['ROLE_USER'], true, []);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $user, [UserVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testUserCanEditThemselves(): void
    {
        $user = $this->createUser(1, ['ROLE_USER'], true, []);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $user, [UserVoter::EDIT]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testUserCannotDeleteThemselves(): void
    {
        $user = $this->createUser(1, ['ROLE_USER'], true, ['user.delete']);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $user, [UserVoter::DELETE]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testUserWithPermissionCanViewOthers(): void
    {
        $currentUser = $this->createUser(1, ['ROLE_USER'], true, ['user.view']);
        $targetUser = $this->createUser(2);
        $token = $this->createToken($currentUser);

        $result = $this->voter->vote($token, $targetUser, [UserVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testUserWithoutPermissionCannotViewOthers(): void
    {
        $currentUser = $this->createUser(1, ['ROLE_USER'], true, []);
        $targetUser = $this->createUser(2);
        $token = $this->createToken($currentUser);

        $result = $this->voter->vote($token, $targetUser, [UserVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testCannotDeleteInitialAdmin(): void
    {
        $currentUser = $this->createUser(1, ['ROLE_USER'], true, ['user.delete']);
        $targetUser = $this->createUser(2);
        $token = $this->createToken($currentUser);

        $this->initialAdminService->method('isInitialAdmin')->willReturn(true);

        $result = $this->voter->vote($token, $targetUser, [UserVoter::DELETE]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testInactiveUserCannotPerformActions(): void
    {
        $currentUser = $this->createUser(1, ['ROLE_USER'], false, ['user.view']);
        $targetUser = $this->createUser(2);
        $token = $this->createToken($currentUser);

        $result = $this->voter->vote($token, $targetUser, [UserVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testUserWithPermissionCanCreate(): void
    {
        $user = $this->createUser(1, ['ROLE_USER'], true, ['user.create']);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, null, [UserVoter::CREATE]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testUserWithPermissionCanManageRoles(): void
    {
        $user = $this->createUser(1, ['ROLE_USER'], true, ['user.manage_roles']);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, null, [UserVoter::MANAGE_ROLES]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }
}
