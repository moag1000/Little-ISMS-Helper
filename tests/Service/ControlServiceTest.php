<?php

namespace App\Tests\Service;

use App\Entity\Control;
use App\Entity\CorporateGovernance;
use App\Entity\Tenant;
use App\Enum\GovernanceModel;
use App\Repository\ControlRepository;
use App\Repository\CorporateGovernanceRepository;
use App\Service\ControlService;
use App\Service\CorporateStructureService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ControlServiceTest extends TestCase
{
    private MockObject $controlRepository;
    private MockObject $corporateStructureService;
    private MockObject $governanceRepository;
    private ControlService $service;

    protected function setUp(): void
    {
        $this->controlRepository = $this->createMock(ControlRepository::class);
        $this->corporateStructureService = $this->createMock(CorporateStructureService::class);
        $this->governanceRepository = $this->createMock(CorporateGovernanceRepository::class);

        $this->service = new ControlService(
            $this->controlRepository,
            $this->corporateStructureService,
            $this->governanceRepository
        );
    }

    public function testGetControlsForTenantWithoutParent(): void
    {
        $tenant = $this->createTenant(1, null);
        $controls = [$this->createMock(Control::class)];

        $this->controlRepository->method('findByTenant')
            ->with($tenant)
            ->willReturn($controls);

        $result = $this->service->getControlsForTenant($tenant);

        $this->assertSame($controls, $result);
    }

    public function testGetControlsForTenantWithHierarchicalGovernance(): void
    {
        $parent = $this->createTenant(1, null);
        $child = $this->createTenant(2, $parent);

        $inheritedControls = [
            $this->createMock(Control::class),
            $this->createMock(Control::class),
        ];

        $governance = $this->createGovernance('hierarchical');
        $this->governanceRepository->method('findGovernanceForScope')
            ->with($child, 'control')
            ->willReturn($governance);

        $this->controlRepository->method('findByTenantIncludingParent')
            ->with($child, $parent)
            ->willReturn($inheritedControls);

        $result = $this->service->getControlsForTenant($child);

        $this->assertSame($inheritedControls, $result);
        $this->assertCount(2, $result);
    }

    public function testGetControlsForTenantWithIndependentGovernance(): void
    {
        $parent = $this->createTenant(1, null);
        $child = $this->createTenant(2, $parent);
        $ownControls = [$this->createMock(Control::class)];

        $governance = $this->createGovernance('independent');
        $this->governanceRepository->method('findGovernanceForScope')
            ->with($child, 'control')
            ->willReturn($governance);

        $this->controlRepository->method('findByTenant')
            ->with($child)
            ->willReturn($ownControls);

        $result = $this->service->getControlsForTenant($child);

        $this->assertSame($ownControls, $result);
    }

    public function testGetControlsForTenantFallbackToDefaultGovernance(): void
    {
        $parent = $this->createTenant(1, null);
        $child = $this->createTenant(2, $parent);
        $ownControls = [$this->createMock(Control::class)];

        // No specific governance for 'control' scope
        $this->governanceRepository->method('findGovernanceForScope')
            ->with($child, 'control')
            ->willReturn(null);

        // Fall back to default governance (independent)
        $defaultGovernance = $this->createGovernance('shared');
        $this->governanceRepository->method('findDefaultGovernance')
            ->with($child)
            ->willReturn($defaultGovernance);

        $this->controlRepository->method('findByTenant')
            ->with($child)
            ->willReturn($ownControls);

        $result = $this->service->getControlsForTenant($child);

        $this->assertSame($ownControls, $result);
    }

    public function testGetControlInheritanceInfoWithoutParent(): void
    {
        $tenant = $this->createTenant(1, null);

        $info = $this->service->getControlInheritanceInfo($tenant);

        $this->assertFalse($info['hasParent']);
        $this->assertFalse($info['canInherit']);
        $this->assertNull($info['governanceModel']);
    }

    public function testGetControlInheritanceInfoWithHierarchicalParent(): void
    {
        $parent = $this->createTenant(1, null);
        $child = $this->createTenant(2, $parent);

        $governance = $this->createGovernance('hierarchical');
        $this->governanceRepository->method('findGovernanceForScope')
            ->with($child, 'control')
            ->willReturn($governance);

        $info = $this->service->getControlInheritanceInfo($child);

        $this->assertTrue($info['hasParent']);
        $this->assertTrue($info['canInherit']);
        $this->assertSame('hierarchical', $info['governanceModel']);
    }

    public function testGetControlInheritanceInfoWithIndependentParent(): void
    {
        $parent = $this->createTenant(1, null);
        $child = $this->createTenant(2, $parent);

        $governance = $this->createGovernance('independent');
        $this->governanceRepository->method('findGovernanceForScope')
            ->with($child, 'control')
            ->willReturn($governance);

        $info = $this->service->getControlInheritanceInfo($child);

        $this->assertTrue($info['hasParent']);
        $this->assertFalse($info['canInherit']);
        $this->assertSame('independent', $info['governanceModel']);
    }

    public function testIsInheritedControlTrue(): void
    {
        $parentTenant = $this->createTenant(1, null);
        $childTenant = $this->createTenant(2, $parentTenant);

        $control = $this->createMock(Control::class);
        $control->method('getTenant')->willReturn($parentTenant);

        $this->assertTrue($this->service->isInheritedControl($control, $childTenant));
    }

    public function testIsInheritedControlFalse(): void
    {
        $tenant = $this->createTenant(1, null);

        $control = $this->createMock(Control::class);
        $control->method('getTenant')->willReturn($tenant);

        $this->assertFalse($this->service->isInheritedControl($control, $tenant));
    }

    public function testIsInheritedControlWithNullTenant(): void
    {
        $tenant = $this->createTenant(1, null);

        $control = $this->createMock(Control::class);
        $control->method('getTenant')->willReturn(null);

        $this->assertFalse($this->service->isInheritedControl($control, $tenant));
    }

    public function testCanEditControlOwnControl(): void
    {
        $tenant = $this->createTenant(1, null);

        $control = $this->createMock(Control::class);
        $control->method('getTenant')->willReturn($tenant);

        $this->assertTrue($this->service->canEditControl($control, $tenant));
    }

    public function testCanEditControlInheritedControl(): void
    {
        $parentTenant = $this->createTenant(1, null);
        $childTenant = $this->createTenant(2, $parentTenant);

        $control = $this->createMock(Control::class);
        $control->method('getTenant')->willReturn($parentTenant);

        $this->assertFalse($this->service->canEditControl($control, $childTenant));
    }

    public function testServiceWorksWithoutOptionalDependencies(): void
    {
        // Service without corporate structure service
        $simpleService = new ControlService($this->controlRepository, null, null);

        $parent = $this->createTenant(1, null);
        $child = $this->createTenant(2, $parent);
        $ownControls = [$this->createMock(Control::class)];

        $this->controlRepository->method('findByTenant')
            ->with($child)
            ->willReturn($ownControls);

        // Should return own controls without considering governance
        $result = $simpleService->getControlsForTenant($child);

        $this->assertSame($ownControls, $result);
    }

    private function createTenant(int $id, ?Tenant $parent): MockObject
    {
        $tenant = $this->createMock(Tenant::class);
        $tenant->method('getId')->willReturn($id);
        $tenant->method('getParent')->willReturn($parent);
        return $tenant;
    }

    private function createGovernance(string $modelValue): MockObject
    {
        $model = match ($modelValue) {
            'hierarchical' => GovernanceModel::HIERARCHICAL,
            'shared' => GovernanceModel::SHARED,
            'independent' => GovernanceModel::INDEPENDENT,
            default => GovernanceModel::INDEPENDENT,
        };

        $governance = $this->createMock(CorporateGovernance::class);
        $governance->method('getGovernanceModel')->willReturn($model);
        return $governance;
    }
}
