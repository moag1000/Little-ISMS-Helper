<?php

namespace App\Tests\Entity;

use App\Entity\Role;
use App\Entity\Tenant;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

class UserTest extends TestCase
{
    public function testConstructor(): void
    {
        $user = new User();

        $this->assertNotNull($user->getCreatedAt());
        $this->assertInstanceOf(\DateTimeImmutable::class, $user->getCreatedAt());
        $this->assertEquals(0, $user->getCustomRoles()->count());
    }

    public function testGettersAndSetters(): void
    {
        $user = new User();

        $user->setEmail('test@example.com');
        $this->assertEquals('test@example.com', $user->getEmail());

        $user->setFirstName('John');
        $this->assertEquals('John', $user->getFirstName());

        $user->setLastName('Doe');
        $this->assertEquals('Doe', $user->getLastName());

        $user->setPassword('hashed_password');
        $this->assertEquals('hashed_password', $user->getPassword());
    }

    public function testGetUserIdentifier(): void
    {
        $user = new User();
        $user->setEmail('identifier@example.com');

        $this->assertEquals('identifier@example.com', $user->getUserIdentifier());
    }

    public function testGetFullName(): void
    {
        $user = new User();
        $user->setFirstName('Jane');
        $user->setLastName('Smith');

        $this->assertEquals('Jane Smith', $user->getFullName());
    }

    public function testGetRolesIncludesDefaultRoleUser(): void
    {
        $user = new User();

        $roles = $user->getRoles();

        $this->assertContains('ROLE_USER', $roles);
    }

    public function testSetAndGetRoles(): void
    {
        $user = new User();
        $user->setRoles(['ROLE_ADMIN', 'ROLE_MANAGER']);

        $roles = $user->getRoles();

        $this->assertContains('ROLE_USER', $roles); // Always includes ROLE_USER
        $this->assertContains('ROLE_ADMIN', $roles);
        $this->assertContains('ROLE_MANAGER', $roles);
    }

    public function testGetStoredRoles(): void
    {
        $user = new User();
        $user->setRoles(['ROLE_ADMIN']);

        $storedRoles = $user->getStoredRoles();

        // getStoredRoles returns only the stored array, not including automatic ROLE_USER
        $this->assertEquals(['ROLE_ADMIN'], $storedRoles);
        $this->assertNotContains('ROLE_USER', $storedRoles);
    }

    public function testAddAndRemoveCustomRole(): void
    {
        $user = new User();
        $role = new Role();
        $role->setName('ROLE_CUSTOM');

        $this->assertEquals(0, $user->getCustomRoles()->count());

        $user->addCustomRole($role);
        $this->assertEquals(1, $user->getCustomRoles()->count());
        $this->assertTrue($user->getCustomRoles()->contains($role));

        // getRoles() should include custom roles
        $this->assertContains('ROLE_CUSTOM', $user->getRoles());

        $user->removeCustomRole($role);
        $this->assertEquals(0, $user->getCustomRoles()->count());
    }

    public function testIsActiveDefault(): void
    {
        $user = new User();

        $this->assertTrue($user->isActive());
    }

    public function testSetIsActive(): void
    {
        $user = new User();
        $user->setIsActive(false);

        $this->assertFalse($user->isActive());
    }

    public function testIsVerifiedDefault(): void
    {
        $user = new User();

        $this->assertFalse($user->isVerified());
    }

    public function testSetIsVerified(): void
    {
        $user = new User();
        $user->setIsVerified(true);

        $this->assertTrue($user->isVerified());
    }

    public function testAuthProvider(): void
    {
        $user = new User();

        $this->assertNull($user->getAuthProvider());

        $user->setAuthProvider('azure_oauth');
        $this->assertEquals('azure_oauth', $user->getAuthProvider());
    }

    public function testAzureFields(): void
    {
        $user = new User();

        $user->setAzureObjectId('azure-object-123');
        $this->assertEquals('azure-object-123', $user->getAzureObjectId());

        $user->setAzureTenantId('azure-tenant-456');
        $this->assertEquals('azure-tenant-456', $user->getAzureTenantId());

        $metadata = ['upn' => 'user@domain.com', 'department' => 'IT'];
        $user->setAzureMetadata($metadata);
        $this->assertEquals($metadata, $user->getAzureMetadata());
    }

    public function testTenantRelationship(): void
    {
        $user = new User();
        $tenant = new Tenant();
        $tenant->setName('Test Tenant');
        $tenant->setCode('test_tenant');
        $tenant->setCode('test_tenant');

        $this->assertNull($user->getTenant());

        $user->setTenant($tenant);
        $this->assertSame($tenant, $user->getTenant());
    }

    public function testProfileFields(): void
    {
        $user = new User();

        $user->setDepartment('Security');
        $this->assertEquals('Security', $user->getDepartment());

        $user->setJobTitle('CISO');
        $this->assertEquals('CISO', $user->getJobTitle());

        $user->setPhoneNumber('+49 123 456789');
        $this->assertEquals('+49 123 456789', $user->getPhoneNumber());

        $user->setLanguage('en');
        $this->assertEquals('en', $user->getLanguage());

        $user->setTimezone('UTC');
        $this->assertEquals('UTC', $user->getTimezone());
    }

    public function testLastLoginAt(): void
    {
        $user = new User();

        $this->assertNull($user->getLastLoginAt());

        $now = new \DateTimeImmutable();
        $user->setLastLoginAt($now);

        $this->assertEquals($now, $user->getLastLoginAt());
    }

    public function testUpdatedAt(): void
    {
        $user = new User();

        $this->assertNull($user->getUpdatedAt());

        $now = new \DateTimeImmutable();
        $user->setUpdatedAt($now);

        $this->assertEquals($now, $user->getUpdatedAt());
    }

    public function testEraseCredentials(): void
    {
        $user = new User();

        // Should not throw any exception
        $user->eraseCredentials();

        // This is a no-op method for UserInterface, just test it exists
        $this->assertTrue(true);
    }

    public function testRolesAreUnique(): void
    {
        $user = new User();
        $user->setRoles(['ROLE_ADMIN', 'ROLE_ADMIN', 'ROLE_MANAGER']);

        $roles = $user->getRoles();

        // Roles should be unique (duplicates removed)
        $this->assertEquals(count($roles), count(array_unique($roles)));
        $this->assertContains('ROLE_ADMIN', $roles);
        $this->assertContains('ROLE_MANAGER', $roles);
        $this->assertContains('ROLE_USER', $roles);
    }
}
