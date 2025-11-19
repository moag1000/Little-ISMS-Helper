<?php

namespace App\Tests\Entity;

use App\Entity\CorporateGovernance;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\GovernanceModel;
use PHPUnit\Framework\TestCase;

class CorporateGovernanceTest extends TestCase
{
    public function testConstructor(): void
    {
        $governance = new CorporateGovernance();

        $this->assertNotNull($governance->getCreatedAt());
        $this->assertInstanceOf(\DateTimeImmutable::class, $governance->getCreatedAt());
        $this->assertNull($governance->getUpdatedAt());
    }

    public function testTenantRelationship(): void
    {
        $governance = new CorporateGovernance();
        $tenant = new Tenant();

        $this->assertNull($governance->getTenant());

        $governance->setTenant($tenant);
        $this->assertSame($tenant, $governance->getTenant());
    }

    public function testParentRelationship(): void
    {
        $governance = new CorporateGovernance();
        $parent = new Tenant();

        $this->assertNull($governance->getParent());

        $governance->setParent($parent);
        $this->assertSame($parent, $governance->getParent());
    }

    public function testScope(): void
    {
        $governance = new CorporateGovernance();

        $this->assertNull($governance->getScope());

        $governance->setScope('control');
        $this->assertEquals('control', $governance->getScope());

        $governance->setScope('isms_context');
        $this->assertEquals('isms_context', $governance->getScope());

        $governance->setScope('risk');
        $this->assertEquals('risk', $governance->getScope());

        $governance->setScope('asset');
        $this->assertEquals('asset', $governance->getScope());

        $governance->setScope('process');
        $this->assertEquals('process', $governance->getScope());

        $governance->setScope('document');
        $this->assertEquals('document', $governance->getScope());

        $governance->setScope('default');
        $this->assertEquals('default', $governance->getScope());
    }

    public function testScopeId(): void
    {
        $governance = new CorporateGovernance();

        $this->assertNull($governance->getScopeId());

        $governance->setScopeId('A.5.1');
        $this->assertEquals('A.5.1', $governance->getScopeId());

        $governance->setScopeId(null);
        $this->assertNull($governance->getScopeId());
    }

    public function testGovernanceModel(): void
    {
        $governance = new CorporateGovernance();

        $this->assertNull($governance->getGovernanceModel());

        $governance->setGovernanceModel(GovernanceModel::HIERARCHICAL);
        $this->assertEquals(GovernanceModel::HIERARCHICAL, $governance->getGovernanceModel());

        $governance->setGovernanceModel(GovernanceModel::SHARED);
        $this->assertEquals(GovernanceModel::SHARED, $governance->getGovernanceModel());

        $governance->setGovernanceModel(GovernanceModel::INDEPENDENT);
        $this->assertEquals(GovernanceModel::INDEPENDENT, $governance->getGovernanceModel());
    }

    public function testNotes(): void
    {
        $governance = new CorporateGovernance();

        $this->assertNull($governance->getNotes());

        $governance->setNotes('This control is managed hierarchically');
        $this->assertEquals('This control is managed hierarchically', $governance->getNotes());

        $governance->setNotes(null);
        $this->assertNull($governance->getNotes());
    }

    public function testCreatedAt(): void
    {
        $governance = new CorporateGovernance();

        // Constructor sets createdAt
        $this->assertNotNull($governance->getCreatedAt());

        $newDate = new \DateTimeImmutable('2024-01-15');
        $governance->setCreatedAt($newDate);
        $this->assertEquals($newDate, $governance->getCreatedAt());
    }

    public function testUpdatedAt(): void
    {
        $governance = new CorporateGovernance();

        $this->assertNull($governance->getUpdatedAt());

        $updatedDate = new \DateTimeImmutable('2024-06-15');
        $governance->setUpdatedAt($updatedDate);
        $this->assertEquals($updatedDate, $governance->getUpdatedAt());

        $governance->setUpdatedAt(null);
        $this->assertNull($governance->getUpdatedAt());
    }

    public function testCreatedByRelationship(): void
    {
        $governance = new CorporateGovernance();
        $user = new User();

        $this->assertNull($governance->getCreatedBy());

        $governance->setCreatedBy($user);
        $this->assertSame($user, $governance->getCreatedBy());

        $governance->setCreatedBy(null);
        $this->assertNull($governance->getCreatedBy());
    }

    public function testSetUpdatedAtValue(): void
    {
        $governance = new CorporateGovernance();

        $this->assertNull($governance->getUpdatedAt());

        // Call the PreUpdate lifecycle callback
        $governance->setUpdatedAtValue();

        $this->assertNotNull($governance->getUpdatedAt());
        $this->assertInstanceOf(\DateTimeImmutable::class, $governance->getUpdatedAt());
    }

    public function testGetScopeLabelControl(): void
    {
        $governance = new CorporateGovernance();
        $governance->setScope('control');

        $this->assertEquals('ISO Control', $governance->getScopeLabel());
    }

    public function testGetScopeLabelIsmsContext(): void
    {
        $governance = new CorporateGovernance();
        $governance->setScope('isms_context');

        $this->assertEquals('ISMS Kontext', $governance->getScopeLabel());
    }

    public function testGetScopeLabelRisk(): void
    {
        $governance = new CorporateGovernance();
        $governance->setScope('risk');

        $this->assertEquals('Risikomanagement', $governance->getScopeLabel());
    }

    public function testGetScopeLabelAsset(): void
    {
        $governance = new CorporateGovernance();
        $governance->setScope('asset');

        $this->assertEquals('Asset Management', $governance->getScopeLabel());
    }

    public function testGetScopeLabelProcess(): void
    {
        $governance = new CorporateGovernance();
        $governance->setScope('process');

        $this->assertEquals('Prozess', $governance->getScopeLabel());
    }

    public function testGetScopeLabelDocument(): void
    {
        $governance = new CorporateGovernance();
        $governance->setScope('document');

        $this->assertEquals('Dokument', $governance->getScopeLabel());
    }

    public function testGetScopeLabelDefault(): void
    {
        $governance = new CorporateGovernance();
        $governance->setScope('default');

        $this->assertEquals('Standard (Alle)', $governance->getScopeLabel());
    }

    public function testGetScopeLabelUnknown(): void
    {
        $governance = new CorporateGovernance();
        $governance->setScope('unknown_scope');

        $this->assertEquals('unknown_scope', $governance->getScopeLabel());
    }

    public function testGetScopeDescriptionWithScopeId(): void
    {
        $governance = new CorporateGovernance();
        $governance->setScope('control');
        $governance->setScopeId('A.5.1');

        $this->assertEquals('ISO Control: A.5.1', $governance->getScopeDescription());
    }

    public function testGetScopeDescriptionWithoutScopeId(): void
    {
        $governance = new CorporateGovernance();
        $governance->setScope('control');

        $this->assertEquals('ISO Control (Alle)', $governance->getScopeDescription());
    }

    public function testFluentSetters(): void
    {
        $governance = new CorporateGovernance();
        $tenant = new Tenant();
        $parent = new Tenant();
        $user = new User();

        $result = $governance
            ->setTenant($tenant)
            ->setParent($parent)
            ->setScope('control')
            ->setScopeId('A.5.1')
            ->setGovernanceModel(GovernanceModel::HIERARCHICAL)
            ->setNotes('Test notes')
            ->setCreatedBy($user);

        $this->assertSame($governance, $result);
        $this->assertSame($tenant, $governance->getTenant());
        $this->assertSame($parent, $governance->getParent());
        $this->assertEquals('control', $governance->getScope());
        $this->assertEquals('A.5.1', $governance->getScopeId());
        $this->assertEquals(GovernanceModel::HIERARCHICAL, $governance->getGovernanceModel());
        $this->assertEquals('Test notes', $governance->getNotes());
        $this->assertSame($user, $governance->getCreatedBy());
    }
}
