<?php

namespace App\Tests\Entity;

use App\Entity\Permission;
use App\Entity\Role;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

class RoleTest extends TestCase
{
    public function testConstructor(): void
    {
        $role = new Role();

        $this->assertNotNull($role->getCreatedAt());
        $this->assertInstanceOf(\DateTimeImmutable::class, $role->getCreatedAt());
        $this->assertEquals(0, $role->getUsers()->count());
        $this->assertEquals(0, $role->getPermissions()->count());
        $this->assertFalse($role->isSystemRole()); // Default
    }

    public function testGettersAndSetters(): void
    {
        $role = new Role();

        $role->setName('ROLE_MANAGER');
        $this->assertEquals('ROLE_MANAGER', $role->getName());

        $role->setDescription('Manager role with elevated permissions');
        $this->assertEquals('Manager role with elevated permissions', $role->getDescription());
    }

    public function testIsSystemRole(): void
    {
        $role = new Role();

        $this->assertFalse($role->isSystemRole()); // Default

        $role->setIsSystemRole(true);
        $this->assertTrue($role->isSystemRole());
    }

    public function testTimestamps(): void
    {
        $role = new Role();

        // createdAt set in constructor
        $this->assertNotNull($role->getCreatedAt());

        // updatedAt initially null
        $this->assertNull($role->getUpdatedAt());

        $now = new \DateTimeImmutable();
        $role->setUpdatedAt($now);
        $this->assertEquals($now, $role->getUpdatedAt());
    }

    public function testAddAndRemoveUser(): void
    {
        $role = new Role();
        $role->setName('ROLE_AUDITOR');

        $user = new User();

        $this->assertEquals(0, $role->getUsers()->count());

        $role->addUser($user);
        $this->assertEquals(1, $role->getUsers()->count());
        $this->assertTrue($role->getUsers()->contains($user));

        $role->removeUser($user);
        $this->assertEquals(0, $role->getUsers()->count());
    }

    public function testAddAndRemovePermission(): void
    {
        $role = new Role();
        $permission = new Permission();
        $permission->setName('view_risks');

        $this->assertEquals(0, $role->getPermissions()->count());

        $role->addPermission($permission);
        $this->assertEquals(1, $role->getPermissions()->count());
        $this->assertTrue($role->getPermissions()->contains($permission));

        $role->removePermission($permission);
        $this->assertEquals(0, $role->getPermissions()->count());
    }

    public function testHasPermission(): void
    {
        $role = new Role();

        $permission1 = new Permission();
        $permission1->setName('view_risks');

        $permission2 = new Permission();
        $permission2->setName('edit_risks');

        $role->addPermission($permission1);

        $this->assertTrue($role->hasPermission('view_risks'));
        $this->assertFalse($role->hasPermission('edit_risks'));

        $role->addPermission($permission2);
        $this->assertTrue($role->hasPermission('edit_risks'));
    }

    public function testToString(): void
    {
        $role = new Role();
        $role->setName('ROLE_ADMIN');

        $this->assertEquals('ROLE_ADMIN', (string)$role);
    }

    public function testToStringWhenNameIsNull(): void
    {
        $role = new Role();

        $this->assertEquals('', (string)$role);
    }
}
