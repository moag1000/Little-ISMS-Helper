<?php

namespace App\Tests\Service;

use App\Entity\ISMSContext;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\ISMSContextRepository;
use App\Service\CorporateStructureService;
use App\Service\ISMSContextService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

class ISMSContextServiceTest extends TestCase
{
    private MockObject $contextRepository;
    private MockObject $entityManager;
    private MockObject $corporateStructureService;
    private MockObject $security;
    private ISMSContextService $service;

    protected function setUp(): void
    {
        $this->contextRepository = $this->createMock(ISMSContextRepository::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->corporateStructureService = $this->createMock(CorporateStructureService::class);
        $this->security = $this->createMock(Security::class);

        $this->service = new ISMSContextService(
            $this->contextRepository,
            $this->entityManager,
            $this->corporateStructureService,
            $this->security
        );
    }

    public function testGetCurrentContextReturnsExistingContextForTenant(): void
    {
        $tenant = $this->createTenant(1, 'Test Corp');
        $user = $this->createUser($tenant);
        $context = $this->createContext(1, $tenant, 'Test Corp');

        $this->security->method('getUser')->willReturn($user);
        $this->contextRepository->method('getContextForTenant')
            ->with($tenant)
            ->willReturn($context);

        $result = $this->service->getCurrentContext();

        $this->assertSame($context, $result);
    }

    public function testGetCurrentContextCreatesNewContextForTenant(): void
    {
        $tenant = $this->createTenant(1, 'New Corp');
        $user = $this->createUser($tenant);

        $this->security->method('getUser')->willReturn($user);
        $this->contextRepository->method('getContextForTenant')
            ->with($tenant)
            ->willReturn(null);

        $this->entityManager->expects($this->once())
            ->method('persist')
            ->with($this->isInstanceOf(ISMSContext::class));

        $result = $this->service->getCurrentContext();

        $this->assertInstanceOf(ISMSContext::class, $result);
        $this->assertSame('New Corp', $result->getOrganizationName());
    }

    public function testGetCurrentContextWithoutUserTenant(): void
    {
        $context = new ISMSContext();
        $context->setOrganizationName('Default');

        $this->security->method('getUser')->willReturn(null);
        $this->contextRepository->method('getCurrentContext')->willReturn($context);

        $result = $this->service->getCurrentContext();

        $this->assertSame($context, $result);
    }

    public function testSaveContextSyncsOrganizationName(): void
    {
        $tenant = $this->createTenant(1, 'Updated Corp');
        $context = new ISMSContext();
        $context->setTenant($tenant);
        $context->setOrganizationName('Old Name');

        $this->entityManager->expects($this->once())->method('flush');

        $this->service->saveContext($context);

        $this->assertSame('Updated Corp', $context->getOrganizationName());
        $this->assertNotNull($context->getUpdatedAt());
    }

    public function testSaveContextPersistsNewContext(): void
    {
        $context = new ISMSContext();
        $context->setOrganizationName('New Context');

        $this->entityManager->expects($this->once())->method('persist')->with($context);
        $this->entityManager->expects($this->once())->method('flush');

        $this->service->saveContext($context);
    }

    public function testSyncOrganizationNameFromTenant(): void
    {
        $tenant = $this->createTenant(1, 'Synced Corp');
        $context = new ISMSContext();
        $context->setTenant($tenant);
        $context->setOrganizationName('Old Name');

        $this->service->syncOrganizationNameFromTenant($context);

        $this->assertSame('Synced Corp', $context->getOrganizationName());
    }

    public function testSyncOrganizationNameWithoutTenant(): void
    {
        $context = new ISMSContext();
        $context->setOrganizationName('Original Name');

        $this->service->syncOrganizationNameFromTenant($context);

        $this->assertSame('Original Name', $context->getOrganizationName());
    }

    public function testCalculateCompletenessAllFieldsFilled(): void
    {
        $context = $this->createFullContext();

        $completeness = $this->service->calculateCompleteness($context);

        $this->assertSame(100, $completeness);
    }

    public function testCalculateCompletenessNoFieldsFilled(): void
    {
        $context = new ISMSContext();

        $completeness = $this->service->calculateCompleteness($context);

        $this->assertSame(0, $completeness);
    }

    public function testCalculateCompletenessPartiallyFilled(): void
    {
        $context = new ISMSContext();
        $context->setOrganizationName('Test Corp');
        $context->setIsmsScope('Scope');
        $context->setIsmsPolicy('Policy');
        $context->setRolesAndResponsibilities('Roles');
        // 4 out of 11 fields filled = 36%

        $completeness = $this->service->calculateCompleteness($context);

        $this->assertSame(36, $completeness);
    }

    public function testIsReviewDueWithNullDate(): void
    {
        $context = new ISMSContext();
        $context->setNextReviewDate(null);

        $this->assertTrue($this->service->isReviewDue($context));
    }

    public function testIsReviewDueWithPastDate(): void
    {
        $context = new ISMSContext();
        $context->setNextReviewDate(new \DateTime('-1 day'));

        $this->assertTrue($this->service->isReviewDue($context));
    }

    public function testIsReviewDueWithFutureDate(): void
    {
        $context = new ISMSContext();
        $context->setNextReviewDate(new \DateTime('+30 days'));

        $this->assertFalse($this->service->isReviewDue($context));
    }

    public function testIsReviewDueWithTodayDate(): void
    {
        $context = new ISMSContext();
        $context->setNextReviewDate(new \DateTime('today'));

        $this->assertTrue($this->service->isReviewDue($context));
    }

    public function testGetDaysUntilReviewWithNullDate(): void
    {
        $context = new ISMSContext();
        $context->setNextReviewDate(null);

        $this->assertNull($this->service->getDaysUntilReview($context));
    }

    public function testGetDaysUntilReviewWithFutureDate(): void
    {
        $context = new ISMSContext();
        $context->setNextReviewDate(new \DateTime('+10 days'));

        $days = $this->service->getDaysUntilReview($context);

        $this->assertGreaterThanOrEqual(9, $days);
        $this->assertLessThanOrEqual(10, $days);
    }

    public function testGetDaysUntilReviewWithPastDate(): void
    {
        $context = new ISMSContext();
        $context->setNextReviewDate(new \DateTime('-5 days'));

        $days = $this->service->getDaysUntilReview($context);

        $this->assertLessThan(0, $days);
    }

    public function testScheduleNextReviewFromToday(): void
    {
        $context = new ISMSContext();

        $this->service->scheduleNextReview($context);

        $nextReview = $context->getNextReviewDate();
        $this->assertNotNull($nextReview);

        $expectedDate = (new \DateTime())->modify('+1 year');
        $this->assertSame(
            $expectedDate->format('Y-m-d'),
            $nextReview->format('Y-m-d')
        );
    }

    public function testScheduleNextReviewFromLastReview(): void
    {
        $context = new ISMSContext();
        $lastReview = new \DateTime('2024-01-01');
        $context->setLastReviewDate($lastReview);

        $this->service->scheduleNextReview($context);

        $nextReview = $context->getNextReviewDate();
        $this->assertSame('2025-01-01', $nextReview->format('Y-m-d'));
    }

    public function testScheduleNextReviewFromCustomDate(): void
    {
        $context = new ISMSContext();
        $customDate = new \DateTime('2024-06-15');

        $this->service->scheduleNextReview($context, $customDate);

        $nextReview = $context->getNextReviewDate();
        $this->assertSame('2025-06-15', $nextReview->format('Y-m-d'));
    }

    public function testValidateContextWithAllRequiredFields(): void
    {
        $context = new ISMSContext();
        $context->setOrganizationName('Test Corp');
        $context->setIsmsScope('Scope');
        $context->setIsmsPolicy('Policy');
        $context->setRolesAndResponsibilities('Roles');

        $errors = $this->service->validateContext($context);

        $this->assertEmpty($errors);
    }

    public function testValidateContextWithMissingRequiredFields(): void
    {
        $context = new ISMSContext();

        $errors = $this->service->validateContext($context);

        $this->assertCount(4, $errors);
        $this->assertStringContainsString('Organisationsname', $errors[0]);
        $this->assertStringContainsString('ISMS-Geltungsbereich', $errors[1]);
        $this->assertStringContainsString('ISMS-Richtlinie', $errors[2]);
        $this->assertStringContainsString('Rollen', $errors[3]);
    }

    public function testValidateContextWithPartialFields(): void
    {
        $context = new ISMSContext();
        $context->setOrganizationName('Test Corp');
        $context->setIsmsScope('Scope');

        $errors = $this->service->validateContext($context);

        $this->assertCount(2, $errors);
    }

    public function testGetEffectiveContextWithoutCorporateService(): void
    {
        $simpleService = new ISMSContextService(
            $this->contextRepository,
            $this->entityManager,
            null,
            $this->security
        );

        $context = new ISMSContext();
        $context->setOrganizationName('Test');

        $result = $simpleService->getEffectiveContext($context);

        $this->assertSame($context, $result);
    }

    public function testGetEffectiveContextWithoutTenant(): void
    {
        $context = new ISMSContext();
        $context->setOrganizationName('Test');

        $result = $this->service->getEffectiveContext($context);

        $this->assertSame($context, $result);
    }

    public function testGetEffectiveContextWithInheritedContext(): void
    {
        $tenant = $this->createTenant(1, 'Child Corp');
        $context = $this->createContext(1, $tenant, 'Child Corp');

        $parentContext = new ISMSContext();
        $parentContext->setOrganizationName('Parent Corp');

        $this->corporateStructureService->method('getEffectiveISMSContext')
            ->with($tenant)
            ->willReturn($parentContext);

        $result = $this->service->getEffectiveContext($context);

        $this->assertSame($parentContext, $result);
    }

    public function testCanEditContextOwnContext(): void
    {
        $tenant = $this->createTenant(1, 'My Corp');
        $user = $this->createUser($tenant);
        $context = $this->createContext(1, $tenant, 'My Corp');

        $this->security->method('getUser')->willReturn($user);
        $this->corporateStructureService->method('getEffectiveISMSContext')
            ->willReturn($context);

        $result = $this->service->canEditContext($context);

        $this->assertTrue($result);
    }

    public function testCanEditContextInheritedContext(): void
    {
        $parentTenant = $this->createTenant(1, 'Parent Corp');
        $childTenant = $this->createTenant(2, 'Child Corp');
        $user = $this->createUser($childTenant);

        $childContext = $this->createContext(2, $childTenant, 'Child Corp');
        $parentContext = $this->createContext(1, $parentTenant, 'Parent Corp');

        $this->security->method('getUser')->willReturn($user);
        $this->corporateStructureService->method('getEffectiveISMSContext')
            ->willReturn($parentContext);

        $result = $this->service->canEditContext($childContext);

        $this->assertFalse($result);
    }

    public function testCanEditContextWithoutSecurity(): void
    {
        $simpleService = new ISMSContextService(
            $this->contextRepository,
            $this->entityManager,
            $this->corporateStructureService,
            null
        );

        $context = new ISMSContext();

        $result = $simpleService->canEditContext($context);

        $this->assertTrue($result);
    }

    public function testGetContextInheritanceInfoOwnContext(): void
    {
        $tenant = $this->createTenant(1, 'My Corp');
        $context = $this->createContext(1, $tenant, 'My Corp');

        $this->corporateStructureService->method('getEffectiveISMSContext')
            ->willReturn($context);

        $info = $this->service->getContextInheritanceInfo($context);

        $this->assertFalse($info['isInherited']);
        $this->assertNull($info['inheritedFrom']);
        $this->assertSame($context, $info['effectiveContext']);
        $this->assertSame($context, $info['ownContext']);
    }

    public function testGetContextInheritanceInfoInheritedContext(): void
    {
        $parentTenant = $this->createTenant(1, 'Parent Corp');

        // Create child tenant with parent configured directly (can't override mock after creation)
        $childTenant = $this->createMock(Tenant::class);
        $childTenant->method('getId')->willReturn(2);
        $childTenant->method('getName')->willReturn('Child Corp');
        $childTenant->method('getParent')->willReturn($parentTenant);

        $childContext = $this->createContext(2, $childTenant, 'Child Corp');
        $parentContext = $this->createContext(1, $parentTenant, 'Parent Corp');

        $this->corporateStructureService->method('getEffectiveISMSContext')
            ->willReturn($parentContext);

        $info = $this->service->getContextInheritanceInfo($childContext);

        $this->assertTrue($info['isInherited']);
        $this->assertSame($parentTenant, $info['inheritedFrom']);
        $this->assertSame($parentContext, $info['effectiveContext']);
        $this->assertTrue($info['hasParent']);
    }

    private function createTenant(int $id, string $name): MockObject
    {
        $tenant = $this->createMock(Tenant::class);
        $tenant->method('getId')->willReturn($id);
        $tenant->method('getName')->willReturn($name);
        $tenant->method('getParent')->willReturn(null);
        return $tenant;
    }

    private function createUser(?Tenant $tenant): MockObject
    {
        $user = $this->createMock(User::class);
        $user->method('getTenant')->willReturn($tenant);
        return $user;
    }

    private function createContext(int $id, ?Tenant $tenant, string $orgName): ISMSContext
    {
        $context = new ISMSContext();

        $reflection = new \ReflectionClass($context);
        $idProperty = $reflection->getProperty('id');
        $idProperty->setValue($context, $id);

        $context->setTenant($tenant);
        $context->setOrganizationName($orgName);

        return $context;
    }

    private function createFullContext(): ISMSContext
    {
        $context = new ISMSContext();
        $context->setOrganizationName('Full Corp');
        $context->setIsmsScope('Global Scope');
        $context->setExternalIssues('External Issues');
        $context->setInternalIssues('Internal Issues');
        $context->setInterestedParties('Stakeholders');
        $context->setInterestedPartiesRequirements('Requirements');
        $context->setLegalRequirements('Legal');
        $context->setRegulatoryRequirements('Regulatory');
        $context->setContractualObligations('Contracts');
        $context->setIsmsPolicy('Policy');
        $context->setRolesAndResponsibilities('Roles');

        return $context;
    }
}
