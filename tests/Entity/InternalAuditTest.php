<?php

namespace App\Tests\Entity;

use App\Entity\Asset;
use App\Entity\ComplianceFramework;
use App\Entity\InternalAudit;
use PHPUnit\Framework\TestCase;

class InternalAuditTest extends TestCase
{
    public function testGetScopeDescriptionWithFullIsms(): void
    {
        $audit = new InternalAudit();
        $audit->setScopeType('full_isms');

        $this->assertEquals('Vollständiges ISMS Audit', $audit->getScopeDescription());
    }

    public function testGetScopeDescriptionWithComplianceFramework(): void
    {
        $audit = new InternalAudit();
        $audit->setScopeType('compliance_framework');

        $framework = new ComplianceFramework();
        $framework->setName('ISO 27001');

        $audit->setScopedFramework($framework);

        $this->assertEquals('Compliance Audit: ISO 27001', $audit->getScopeDescription());
    }

    public function testGetScopeDescriptionWithComplianceFrameworkNoFramework(): void
    {
        $audit = new InternalAudit();
        $audit->setScopeType('compliance_framework');
        // No framework set

        $this->assertEquals('Compliance Framework Audit', $audit->getScopeDescription());
    }

    public function testGetScopeDescriptionWithAssets(): void
    {
        $audit = new InternalAudit();
        $audit->setScopeType('asset');

        $asset1 = new Asset();
        $asset2 = new Asset();
        $asset3 = new Asset();

        $audit->addScopedAsset($asset1);
        $audit->addScopedAsset($asset2);
        $audit->addScopedAsset($asset3);

        $this->assertEquals('Asset Audit (3 Assets)', $audit->getScopeDescription());
    }

    public function testGetScopeDescriptionWithAssetType(): void
    {
        $audit = new InternalAudit();
        $audit->setScopeType('asset_type');
        $audit->setScopeDetails(['type' => 'Server']);

        $this->assertEquals('Asset-Typ Audit: Server', $audit->getScopeDescription());
    }

    public function testGetScopeDescriptionWithAssetTypeNoDetails(): void
    {
        $audit = new InternalAudit();
        $audit->setScopeType('asset_type');

        $this->assertEquals('Asset-Typ Audit: N/A', $audit->getScopeDescription());
    }

    public function testGetScopeDescriptionWithAssetGroup(): void
    {
        $audit = new InternalAudit();
        $audit->setScopeType('asset_group');
        $audit->setScopeDetails(['group' => 'Production Servers']);

        $this->assertEquals('Asset-Gruppe Audit: Production Servers', $audit->getScopeDescription());
    }

    public function testGetScopeDescriptionWithLocation(): void
    {
        $audit = new InternalAudit();
        $audit->setScopeType('location');
        $audit->setScopeDetails(['location' => 'Munich Office']);

        $this->assertEquals('Standort Audit: Munich Office', $audit->getScopeDescription());
    }

    public function testGetScopeDescriptionWithDepartment(): void
    {
        $audit = new InternalAudit();
        $audit->setScopeType('department');
        $audit->setScopeDetails(['department' => 'IT Security']);

        $this->assertEquals('Abteilungs Audit: IT Security', $audit->getScopeDescription());
    }

    public function testGetScopeDescriptionWithUnknownType(): void
    {
        $audit = new InternalAudit();
        $audit->setScopeType('unknown_type');

        $this->assertEquals('Unbekannter Scope', $audit->getScopeDescription());
    }

    public function testHasAssetScopeWithAssetType(): void
    {
        $audit = new InternalAudit();
        $audit->setScopeType('asset');

        $this->assertTrue($audit->hasAssetScope());
    }

    public function testHasAssetScopeWithAssetTypeType(): void
    {
        $audit = new InternalAudit();
        $audit->setScopeType('asset_type');

        $this->assertTrue($audit->hasAssetScope());
    }

    public function testHasAssetScopeWithAssetGroup(): void
    {
        $audit = new InternalAudit();
        $audit->setScopeType('asset_group');

        $this->assertTrue($audit->hasAssetScope());
    }

    public function testHasAssetScopeWithNonAssetTypes(): void
    {
        $audit = new InternalAudit();

        $nonAssetTypes = ['full_isms', 'compliance_framework', 'location', 'department'];

        foreach ($nonAssetTypes as $type) {
            $audit->setScopeType($type);
            $this->assertFalse($audit->hasAssetScope(), "hasAssetScope() should be false for type: $type");
        }
    }

    public function testIsComplianceAuditWithFramework(): void
    {
        $audit = new InternalAudit();
        $audit->setScopeType('compliance_framework');

        $framework = new ComplianceFramework();
        $framework->setName('TISAX');

        $audit->setScopedFramework($framework);

        $this->assertTrue($audit->isComplianceAudit());
    }

    public function testIsComplianceAuditWithoutFramework(): void
    {
        $audit = new InternalAudit();
        $audit->setScopeType('compliance_framework');
        // No framework set

        $this->assertFalse($audit->isComplianceAudit());
    }

    public function testIsComplianceAuditWithWrongScopeType(): void
    {
        $audit = new InternalAudit();
        $audit->setScopeType('full_isms');

        $framework = new ComplianceFramework();
        $framework->setName('ISO 27001');

        $audit->setScopedFramework($framework);

        // Has framework but wrong scope type
        $this->assertFalse($audit->isComplianceAudit());
    }

    public function testAddAndRemoveScopedAsset(): void
    {
        $audit = new InternalAudit();
        $asset = new Asset();

        $this->assertEquals(0, $audit->getScopedAssets()->count());

        $audit->addScopedAsset($asset);
        $this->assertEquals(1, $audit->getScopedAssets()->count());
        $this->assertTrue($audit->getScopedAssets()->contains($asset));

        $audit->removeScopedAsset($asset);
        $this->assertEquals(0, $audit->getScopedAssets()->count());
        $this->assertFalse($audit->getScopedAssets()->contains($asset));
    }

    public function testStatusChoices(): void
    {
        $audit = new InternalAudit();

        // Test valid status values
        $validStatuses = ['planned', 'in_progress', 'completed', 'cancelled'];

        foreach ($validStatuses as $status) {
            $audit->setStatus($status);
            $this->assertEquals($status, $audit->getStatus());
        }
    }

    public function testScopeTypeChoices(): void
    {
        $audit = new InternalAudit();

        // Test valid scope types
        $validScopeTypes = [
            'full_isms',
            'compliance_framework',
            'asset',
            'asset_type',
            'asset_group',
            'location',
            'department'
        ];

        foreach ($validScopeTypes as $scopeType) {
            $audit->setScopeType($scopeType);
            $this->assertEquals($scopeType, $audit->getScopeType());
        }
    }

    public function testDatesHandling(): void
    {
        $audit = new InternalAudit();

        $plannedDate = new \DateTime('2024-05-01');
        $actualDate = new \DateTime('2024-06-01');
        $reportDate = new \DateTime('2024-06-15');

        $audit->setPlannedDate($plannedDate);
        $audit->setActualDate($actualDate);
        $audit->setReportDate($reportDate);

        $this->assertEquals($plannedDate, $audit->getPlannedDate());
        $this->assertEquals($actualDate, $audit->getActualDate());
        $this->assertEquals($reportDate, $audit->getReportDate());
    }

    public function testScopeDetailsArrayHandling(): void
    {
        $audit = new InternalAudit();

        $details = [
            'type' => 'Server',
            'location' => 'Munich',
            'department' => 'IT'
        ];

        $audit->setScopeDetails($details);
        $this->assertEquals($details, $audit->getScopeDetails());

        // Test with null
        $audit->setScopeDetails(null);
        $this->assertNull($audit->getScopeDetails());
    }
}
