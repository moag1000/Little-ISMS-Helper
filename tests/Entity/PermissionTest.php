<?php

namespace App\Tests\Entity;

use App\Entity\Permission;
use App\Entity\Role;
use PHPUnit\Framework\TestCase;

class PermissionTest extends TestCase
{
    public function testConstructor(): void
    {
        $permission = new Permission();

        $this->assertNotNull($permission->getCreatedAt());
        $this->assertInstanceOf(\DateTimeImmutable::class, $permission->getCreatedAt());
        $this->assertEquals(0, $permission->getRoles()->count());
        $this->assertFalse($permission->isSystemPermission()); // Default
    }

    public function testGettersAndSetters(): void
    {
        $permission = new Permission();

        $permission->setName('edit_risks');
        $this->assertEquals('edit_risks', $permission->getName());

        $permission->setDescription('Permission to edit risk entries');
        $this->assertEquals('Permission to edit risk entries', $permission->getDescription());

        $permission->setCategory('risk');
        $this->assertEquals('risk', $permission->getCategory());

        $permission->setAction('edit');
        $this->assertEquals('edit', $permission->getAction());
    }

    public function testIsSystemPermission(): void
    {
        $permission = new Permission();

        $this->assertFalse($permission->isSystemPermission()); // Default

        $permission->setIsSystemPermission(true);
        $this->assertTrue($permission->isSystemPermission());
    }

    public function testCreatedAt(): void
    {
        $permission = new Permission();

        // createdAt set in constructor
        $this->assertNotNull($permission->getCreatedAt());

        $now = new \DateTimeImmutable();
        $permission->setCreatedAt($now);
        $this->assertEquals($now, $permission->getCreatedAt());
    }

    public function testAddAndRemoveRole(): void
    {
        $permission = new Permission();
        $permission->setName('view_assets');

        $role = new Role();
        $role->setName('ROLE_VIEWER');

        $this->assertEquals(0, $permission->getRoles()->count());

        $permission->addRole($role);
        $this->assertEquals(1, $permission->getRoles()->count());
        $this->assertTrue($permission->getRoles()->contains($role));

        $permission->removeRole($role);
        $this->assertEquals(0, $permission->getRoles()->count());
    }

    public function testToString(): void
    {
        $permission = new Permission();
        $permission->setName('delete_incidents');

        $this->assertEquals('delete_incidents', (string)$permission);
    }

    public function testToStringWhenNameIsNull(): void
    {
        $permission = new Permission();

        $this->assertEquals('', (string)$permission);
    }
}
