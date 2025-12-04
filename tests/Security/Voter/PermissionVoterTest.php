<?php

namespace App\Tests\Security\Voter;

use App\Entity\Permission;
use App\Entity\Role;
use App\Entity\User;
use App\Security\Voter\PermissionVoter;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

class PermissionVoterTest extends TestCase
{
    private PermissionVoter $voter;

    protected function setUp(): void
    {
        $this->voter = new PermissionVoter();
    }

    private function createUser(array $roles = ['ROLE_USER'], array $permissions = []): User
    {
        $user = $this->createMock(User::class);
        $user->method('getRoles')->willReturn($roles);

        $customRoles = new ArrayCollection();
        if (!empty($permissions)) {
            $customRole = $this->createMock(Role::class);
            $permissionCollection = new ArrayCollection();

            foreach ($permissions as $permName) {
                $perm = $this->createMock(Permission::class);
                $perm->method('getName')->willReturn($permName);
                $permissionCollection->add($perm);
            }

            $customRole->method('getPermissions')->willReturn($permissionCollection);
            $customRoles->add($customRole);
        }

        $user->method('getCustomRoles')->willReturn($customRoles);
        return $user;
    }

    private function createToken(User $user): UsernamePasswordToken
    {
        return new UsernamePasswordToken($user, 'main', $user->getRoles());
    }

    public function testSuperAdminHasAllPermissions(): void
    {
        $user = $this->createUser(['ROLE_SUPER_ADMIN']);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, null, [PermissionVoter::ADMIN_ACCESS]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testSuperAdminCanBackupRestore(): void
    {
        $user = $this->createUser(['ROLE_SUPER_ADMIN']);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, null, [PermissionVoter::BACKUP_RESTORE]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testAdminHasAdminAccess(): void
    {
        $user = $this->createUser(['ROLE_ADMIN']);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, null, [PermissionVoter::ADMIN_ACCESS]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testAdminCannotBackupRestore(): void
    {
        $user = $this->createUser(['ROLE_ADMIN']);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, null, [PermissionVoter::BACKUP_RESTORE]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testUserWithPermissionCanAccess(): void
    {
        $user = $this->createUser(['ROLE_USER'], [PermissionVoter::USER_VIEW]);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, null, [PermissionVoter::USER_VIEW]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testUserWithoutPermissionCannotAccess(): void
    {
        $user = $this->createUser(['ROLE_USER'], []);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, null, [PermissionVoter::USER_VIEW]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testVoterAbstainsForUnsupportedAttribute(): void
    {
        $user = $this->createUser(['ROLE_USER']);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, null, ['UNSUPPORTED_PERMISSION']);

        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $result);
    }

    public function testGetAllPermissionsReturnsCategories(): void
    {
        $permissions = PermissionVoter::getAllPermissions();

        $this->assertArrayHasKey('admin', $permissions);
        $this->assertArrayHasKey('user', $permissions);
        $this->assertArrayHasKey('tenant', $permissions);
        $this->assertArrayHasKey('session', $permissions);
        $this->assertArrayHasKey('mfa', $permissions);
        $this->assertArrayHasKey('module', $permissions);
        $this->assertArrayHasKey('role', $permissions);
        $this->assertArrayHasKey('compliance', $permissions);
        $this->assertArrayHasKey('audit', $permissions);
        $this->assertArrayHasKey('monitoring', $permissions);
        $this->assertArrayHasKey('backup', $permissions);
    }

    public function testAdminPermissionsExist(): void
    {
        $permissions = PermissionVoter::getAllPermissions();

        $this->assertArrayHasKey(PermissionVoter::ADMIN_ACCESS, $permissions['admin']);
        $this->assertArrayHasKey(PermissionVoter::ADMIN_VIEW, $permissions['admin']);
        $this->assertArrayHasKey(PermissionVoter::ADMIN_EDIT, $permissions['admin']);
        $this->assertArrayHasKey(PermissionVoter::ADMIN_SETTINGS, $permissions['admin']);
    }
}
