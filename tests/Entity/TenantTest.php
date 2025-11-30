<?php

namespace App\Tests\Entity;

use App\Entity\Tenant;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

class TenantTest extends TestCase
{
    public function testConstructor(): void
    {
        $tenant = new Tenant();

        $this->assertNotNull($tenant->getCreatedAt());
        $this->assertInstanceOf(\DateTimeImmutable::class, $tenant->getCreatedAt());
        $this->assertEquals(0, $tenant->getUsers()->count());
        $this->assertEquals(0, $tenant->getSubsidiaries()->count());
    }

    public function testGettersAndSetters(): void
    {
        $tenant = new Tenant();

        $tenant->setCode('TENANT01');
        $this->assertEquals('TENANT01', $tenant->getCode());

        $tenant->setName('Test Tenant');
        $tenant->setCode('test_tenant');
        $this->assertEquals('Test Tenant', $tenant->getName());

        $tenant->setDescription('A test tenant description');
        $this->assertEquals('A test tenant description', $tenant->getDescription());

        $tenant->setAzureTenantId('azure-123');
        $this->assertEquals('azure-123', $tenant->getAzureTenantId());

        $tenant->setLogoPath('/logos/tenant.png');
        $this->assertEquals('/logos/tenant.png', $tenant->getLogoPath());
    }

    public function testIsActiveDefault(): void
    {
        $tenant = new Tenant();

        $this->assertTrue($tenant->isActive());
    }

    public function testSetIsActive(): void
    {
        $tenant = new Tenant();
        $tenant->setIsActive(false);

        $this->assertFalse($tenant->isActive());
    }

    public function testSettings(): void
    {
        $tenant = new Tenant();

        $this->assertNull($tenant->getSettings());

        $settings = ['theme' => 'dark', 'language' => 'de'];
        $tenant->setSettings($settings);

        $this->assertEquals($settings, $tenant->getSettings());
    }

    public function testAddAndRemoveUser(): void
    {
        $tenant = new Tenant();
        $user = new User();

        $this->assertEquals(0, $tenant->getUsers()->count());

        $tenant->addUser($user);
        $this->assertEquals(1, $tenant->getUsers()->count());
        $this->assertTrue($tenant->getUsers()->contains($user));
        $this->assertSame($tenant, $user->getTenant());

        $tenant->removeUser($user);
        $this->assertEquals(0, $tenant->getUsers()->count());
        $this->assertNull($user->getTenant());
    }

    public function testSetUpdatedAtValue(): void
    {
        $tenant = new Tenant();

        $this->assertNull($tenant->getUpdatedAt());

        $tenant->setUpdatedAtValue();

        $this->assertNotNull($tenant->getUpdatedAt());
        $this->assertInstanceOf(\DateTimeImmutable::class, $tenant->getUpdatedAt());
    }

    public function testIsCorporateParentDefault(): void
    {
        $tenant = new Tenant();

        $this->assertFalse($tenant->isCorporateParent());
    }

    public function testSetIsCorporateParent(): void
    {
        $tenant = new Tenant();
        $tenant->setIsCorporateParent(true);

        $this->assertTrue($tenant->isCorporateParent());
    }

    public function testCorporateNotes(): void
    {
        $tenant = new Tenant();

        $this->assertNull($tenant->getCorporateNotes());

        $tenant->setCorporateNotes('Important corporate information');
        $this->assertEquals('Important corporate information', $tenant->getCorporateNotes());
    }

    public function testParentSubsidiaryRelationship(): void
    {
        $parent = new Tenant();
        $parent->setName('Parent Corp');
        $parent->setCode('parent_corp');
        $parent->setCode('parent_corp');

        $subsidiary = new Tenant();
        $subsidiary->setName('Subsidiary Inc');
        $subsidiary->setCode('subsidiary_inc');
        $subsidiary->setCode('subsidiary_inc');

        $this->assertNull($subsidiary->getParent());
        $this->assertEquals(0, $parent->getSubsidiaries()->count());

        $parent->addSubsidiary($subsidiary);

        $this->assertEquals(1, $parent->getSubsidiaries()->count());
        $this->assertTrue($parent->getSubsidiaries()->contains($subsidiary));
        $this->assertSame($parent, $subsidiary->getParent());

        $parent->removeSubsidiary($subsidiary);

        $this->assertEquals(0, $parent->getSubsidiaries()->count());
        $this->assertNull($subsidiary->getParent());
    }

    public function testIsPartOfCorporateStructure(): void
    {
        $standalone = new Tenant();
        $this->assertFalse($standalone->isPartOfCorporateStructure());

        $parent = new Tenant();
        $subsidiary = new Tenant();

        $parent->addSubsidiary($subsidiary);

        $this->assertTrue($parent->isPartOfCorporateStructure()); // Has subsidiaries
        $this->assertTrue($subsidiary->isPartOfCorporateStructure()); // Has parent
    }

    public function testGetRootParent(): void
    {
        $root = new Tenant();
        $root->setName('Root');
        $root->setCode('root');
        $root->setCode('root');

        $child = new Tenant();
        $child->setName('Child');
        $child->setCode('child');
        $child->setCode('child');

        $grandchild = new Tenant();
        $grandchild->setName('Grandchild');
        $grandchild->setCode('grandchild');
        $grandchild->setCode('grandchild');

        $root->addSubsidiary($child);
        $child->addSubsidiary($grandchild);

        $this->assertSame($root, $root->getRootParent());
        $this->assertSame($root, $child->getRootParent());
        $this->assertSame($root, $grandchild->getRootParent());
    }

    public function testGetAllSubsidiaries(): void
    {
        $parent = new Tenant();
        $child1 = new Tenant();
        $child2 = new Tenant();
        $grandchild = new Tenant();

        $parent->addSubsidiary($child1);
        $parent->addSubsidiary($child2);
        $child1->addSubsidiary($grandchild);

        $allSubsidiaries = $parent->getAllSubsidiaries();

        $this->assertCount(3, $allSubsidiaries); // child1, child2, grandchild
        $this->assertContains($child1, $allSubsidiaries);
        $this->assertContains($child2, $allSubsidiaries);
        $this->assertContains($grandchild, $allSubsidiaries);
    }

    public function testGetHierarchyDepth(): void
    {
        $root = new Tenant();
        $level1 = new Tenant();
        $level2 = new Tenant();

        $root->addSubsidiary($level1);
        $level1->addSubsidiary($level2);

        $this->assertEquals(0, $root->getHierarchyDepth());
        $this->assertEquals(1, $level1->getHierarchyDepth());
        $this->assertEquals(2, $level2->getHierarchyDepth());
    }

    public function testGetAllAncestors(): void
    {
        $root = new Tenant();
        $root->setName('Root');
        $root->setCode('root');
        $root->setCode('root');

        $middle = new Tenant();
        $middle->setName('Middle');
        $middle->setCode('middle');
        $middle->setCode('middle');

        $leaf = new Tenant();
        $leaf->setName('Leaf');
        $leaf->setCode('leaf');
        $leaf->setCode('leaf');

        $root->addSubsidiary($middle);
        $middle->addSubsidiary($leaf);

        $rootAncestors = $root->getAllAncestors();
        $this->assertEmpty($rootAncestors);

        $middleAncestors = $middle->getAllAncestors();
        $this->assertCount(1, $middleAncestors);
        $this->assertSame($root, $middleAncestors[0]);

        $leafAncestors = $leaf->getAllAncestors();
        $this->assertCount(2, $leafAncestors);
        $this->assertSame($middle, $leafAncestors[0]); // Immediate parent first
        $this->assertSame($root, $leafAncestors[1]); // Root parent last
    }
}
