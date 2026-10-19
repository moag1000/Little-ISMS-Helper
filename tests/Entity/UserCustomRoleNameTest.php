<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Role;
use App\Entity\User;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Security hotfix: User::getRoles() merges custom Role names into the
 * effective Symfony roles. A custom role named like a reserved Symfony role
 * (ROLE_SUPER_ADMIN, ROLE_ALLOWED_TO_SWITCH, ROLE_ADMIN, ...) must not grant
 * that role; custom roles grant permissions via their permission set.
 */
final class UserCustomRoleNameTest extends TestCase
{
    private function userWith(string $roleName, bool $isSystemRole): User
    {
        $role = (new Role())->setName($roleName)->setIsSystemRole($isSystemRole);

        return (new User())->setRoles(['ROLE_USER'])->addCustomRole($role);
    }

    #[Test]
    public function nonSystemCustomRoleCannotGrantReservedSymfonyRoles(): void
    {
        foreach (['ROLE_SUPER_ADMIN', 'ROLE_ALLOWED_TO_SWITCH', 'ROLE_ADMIN', 'ROLE_GROUP_CISO'] as $name) {
            self::assertNotContains($name, $this->userWith($name, false)->getRoles(), $name);
        }
    }

    #[Test]
    public function systemCustomRoleNamedSuperAdminOrSwitchUserGrantsNothing(): void
    {
        self::assertNotContains('ROLE_SUPER_ADMIN', $this->userWith('ROLE_SUPER_ADMIN', true)->getRoles());
        self::assertNotContains('ROLE_ALLOWED_TO_SWITCH', $this->userWith('ROLE_ALLOWED_TO_SWITCH', true)->getRoles());
    }

    #[Test]
    public function seededSystemHoldingRoleStillMaps(): void
    {
        self::assertContains('ROLE_GROUP_CISO', $this->userWith('ROLE_GROUP_CISO', true)->getRoles());
        self::assertContains('ROLE_CISO', $this->userWith('ROLE_CISO', true)->getRoles());
    }

    #[Test]
    public function ordinaryCustomRoleNameIsStillListed(): void
    {
        self::assertContains('Editor', $this->userWith('Editor', false)->getRoles());
        self::assertContains('ROLE_USER', $this->userWith('Editor', false)->getRoles());
    }
}
