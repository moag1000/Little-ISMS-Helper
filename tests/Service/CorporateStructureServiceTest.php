<?php

namespace App\Tests\Service;

use App\Entity\CorporateGovernance;
use App\Entity\ISMSContext;
use App\Entity\Tenant;
use App\Enum\GovernanceModel;
use App\Repository\CorporateGovernanceRepository;
use App\Repository\ISMSContextRepository;
use App\Service\CorporateStructureService;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CorporateStructureServiceTest extends TestCase
{
    private MockObject $entityManager;
    private MockObject $ismsContextRepository;
    private MockObject $governanceRepository;
    private CorporateStructureService $service;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->ismsContextRepository = $this->createMock(ISMSContextRepository::class);
        $this->governanceRepository = $this->createMock(CorporateGovernanceRepository::class);

        $this->service = new CorporateStructureService(
            $this->entityManager,
            $this->ismsContextRepository,
            $this->governanceRepository
        );
    }

    public function testIsParentOfWithDirectParent(): void
    {
        $parent = $this->createTenant(1, 'Parent');
        $child = $this->createTenant(2, 'Child', $parent);

        $this->assertTrue($this->service->isParentOf($parent, $child));
        $this->assertFalse($this->service->isParentOf($child, $parent));
    }

    public function testIsParentOfWithIndirectParent(): void
    {
        $grandparent = $this->createTenant(1, 'Grandparent');
        $parent = $this->createTenant(2, 'Parent', $grandparent);
        $child = $this->createTenant(3, 'Child', $parent);

        $this->assertTrue($this->service->isParentOf($grandparent, $child));
        $this->assertTrue($this->service->isParentOf($parent, $child));
        $this->assertFalse($this->service->isParentOf($child, $grandparent));
    }

    public function testIsParentOfWithNoRelationship(): void
    {
        $tenant1 = $this->createTenant(1, 'Tenant 1');
        $tenant2 = $this->createTenant(2, 'Tenant 2');

        $this->assertFalse($this->service->isParentOf($tenant1, $tenant2));
        $this->assertFalse($this->service->isParentOf($tenant2, $tenant1));
    }

    public function testIsInSameCorporateGroupWithSameRoot(): void
    {
        $root = $this->createTenant(1, 'Root');
        $subsidiary1 = $this->createTenant(2, 'Sub 1', $root);
        $subsidiary2 = $this->createTenant(3, 'Sub 2', $root);

        // Configure getRootParent to return root for all
        $root->method('getRootParent')->willReturn($root);
        $subsidiary1->method('getRootParent')->willReturn($root);
        $subsidiary2->method('getRootParent')->willReturn($root);

        $this->assertTrue($this->service->isInSameCorporateGroup($subsidiary1, $subsidiary2));
        $this->assertTrue($this->service->isInSameCorporateGroup($root, $subsidiary1));
    }

    public function testIsInSameCorporateGroupWithDifferentRoots(): void
    {
        $root1 = $this->createTenant(1, 'Root 1');
        $root2 = $this->createTenant(2, 'Root 2');
        $subsidiary = $this->createTenant(3, 'Sub', $root1);

        $root1->method('getRootParent')->willReturn($root1);
        $root2->method('getRootParent')->willReturn($root2);
        $subsidiary->method('getRootParent')->willReturn($root1);

        $this->assertFalse($this->service->isInSameCorporateGroup($subsidiary, $root2));
    }

    public function testCanAccessTenantSameTenant(): void
    {
        $tenant = $this->createTenant(1, 'Same Tenant');

        $this->assertTrue($this->service->canAccessTenant($tenant, $tenant));
    }

    public function testCanAccessTenantParentAccessesChild(): void
    {
        $parent = $this->createTenant(1, 'Parent');
        $child = $this->createTenant(2, 'Child', $parent);

        $this->assertTrue($this->service->canAccessTenant($parent, $child));
    }

    public function testCanAccessTenantChildCannotAccessParent(): void
    {
        $parent = $this->createTenant(1, 'Parent');
        $child = $this->createTenant(2, 'Child', $parent);

        // Child is not parent of parent
        // Child and parent are in same corporate group (check SHARED governance)
        $parent->method('getRootParent')->willReturn($parent);
        $child->method('getRootParent')->willReturn($parent);

        // Parent doesn't have SHARED governance
        $governance = $this->createMock(CorporateGovernance::class);
        $governance->method('getGovernanceModel')->willReturn(GovernanceModel::INDEPENDENT);
        $this->governanceRepository->method('findDefaultGovernance')
            ->with($parent)
            ->willReturn($governance);

        $this->assertFalse($this->service->canAccessTenant($child, $parent));
    }

    public function testCanAccessTenantSharedGovernanceInSameGroup(): void
    {
        $root = $this->createTenant(1, 'Root');
        $subsidiary1 = $this->createTenant(2, 'Sub 1', $root);
        $subsidiary2 = $this->createTenant(3, 'Sub 2', $root);

        // Configure same corporate group
        $root->method('getRootParent')->willReturn($root);
        $subsidiary1->method('getRootParent')->willReturn($root);
        $subsidiary2->method('getRootParent')->willReturn($root);

        // Target has SHARED governance
        $sharedGovernance = $this->createMock(CorporateGovernance::class);
        $sharedGovernance->method('getGovernanceModel')->willReturn(GovernanceModel::SHARED);
        $this->governanceRepository->method('findDefaultGovernance')
            ->with($subsidiary2)
            ->willReturn($sharedGovernance);

        $this->assertTrue($this->service->canAccessTenant($subsidiary1, $subsidiary2));
    }

    public function testGetEffectiveISMSContextNoParent(): void
    {
        $tenant = $this->createTenant(1, 'Standalone', null);
        $context = $this->createMock(ISMSContext::class);

        $this->ismsContextRepository->method('findOneBy')
            ->with(['tenant' => $tenant])
            ->willReturn($context);

        $result = $this->service->getEffectiveISMSContext($tenant);

        $this->assertSame($context, $result);
    }

    public function testGetEffectiveISMSContextHierarchicalModel(): void
    {
        $parent = $this->createTenant(1, 'Parent', null);
        $child = $this->createTenant(2, 'Child', $parent);
        $parentContext = $this->createMock(ISMSContext::class);

        // Child has HIERARCHICAL governance
        $governance = $this->createMock(CorporateGovernance::class);
        $governance->method('getGovernanceModel')->willReturn(GovernanceModel::HIERARCHICAL);

        $this->governanceRepository->method('findGovernanceForScope')
            ->with($child, 'isms_context')
            ->willReturn($governance);

        // Parent's context is returned (recursive call)
        $this->ismsContextRepository->method('findOneBy')
            ->willReturnMap([
                [['tenant' => $parent], $parentContext],
                [['tenant' => $child], null],
            ]);

        $result = $this->service->getEffectiveISMSContext($child);

        $this->assertSame($parentContext, $result);
    }

    public function testGetEffectiveISMSContextIndependentModel(): void
    {
        $parent = $this->createTenant(1, 'Parent', null);
        $child = $this->createTenant(2, 'Child', $parent);
        $childContext = $this->createMock(ISMSContext::class);

        // Child has INDEPENDENT governance
        $governance = $this->createMock(CorporateGovernance::class);
        $governance->method('getGovernanceModel')->willReturn(GovernanceModel::INDEPENDENT);

        $this->governanceRepository->method('findGovernanceForScope')
            ->with($child, 'isms_context')
            ->willReturn($governance);

        $this->ismsContextRepository->method('findOneBy')
            ->with(['tenant' => $child])
            ->willReturn($childContext);

        $result = $this->service->getEffectiveISMSContext($child);

        $this->assertSame($childContext, $result);
    }

    public function testValidateStructureValidTenant(): void
    {
        $tenant = $this->createTenant(1, 'Valid', null);
        $tenant->method('getSubsidiaries')->willReturn(new ArrayCollection());

        $errors = $this->service->validateStructure($tenant);

        $this->assertEmpty($errors);
    }

    public function testValidateStructureSubsidiaryWithoutGovernance(): void
    {
        $parent = $this->createTenant(1, 'Parent', null);
        $child = $this->createTenant(2, 'Child', $parent);
        $child->method('getSubsidiaries')->willReturn(new ArrayCollection());

        $this->governanceRepository->method('findDefaultGovernance')
            ->with($child)
            ->willReturn(null);

        $errors = $this->service->validateStructure($child);

        $this->assertContains('Subsidiaries must have a default governance model defined', $errors);
    }

    public function testValidateStructureCorporateParentFlag(): void
    {
        $tenant = $this->createTenant(1, 'Parent', null);
        $subsidiary = $this->createMock(Tenant::class);

        $tenant->method('getSubsidiaries')->willReturn(new ArrayCollection([$subsidiary]));
        $tenant->method('isCorporateParent')->willReturn(false);

        $errors = $this->service->validateStructure($tenant);

        $this->assertContains('Tenant with subsidiaries should be marked as corporate parent', $errors);
    }

    public function testGetCorporateGroup(): void
    {
        $root = $this->createTenant(1, 'Root', null);
        $sub1 = $this->createMock(Tenant::class);
        $sub2 = $this->createMock(Tenant::class);

        $root->method('getRootParent')->willReturn($root);
        $root->method('getAllSubsidiaries')->willReturn([$sub1, $sub2]);

        $group = $this->service->getCorporateGroup($root);

        $this->assertCount(3, $group);
        $this->assertSame($root, $group[0]);
        $this->assertSame($sub1, $group[1]);
        $this->assertSame($sub2, $group[2]);
    }

    /**
     * Create a mock Tenant with configurable properties
     */
    private function createTenant(int $id, string $name, ?Tenant $parent = null): MockObject
    {
        $tenant = $this->createMock(Tenant::class);
        $tenant->method('getId')->willReturn($id);
        $tenant->method('getName')->willReturn($name);
        $tenant->method('getParent')->willReturn($parent);

        return $tenant;
    }
}
