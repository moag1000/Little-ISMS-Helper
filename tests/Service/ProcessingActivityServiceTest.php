<?php

namespace App\Tests\Service;

use App\Entity\ProcessingActivity;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\ProcessingActivityRepository;
use App\Service\AuditLogger;
use App\Service\ProcessingActivityService;
use App\Service\TenantContext;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Bundle\SecurityBundle\Security;

class ProcessingActivityServiceTest extends TestCase
{
    private MockObject $processingActivityRepository;
    private MockObject $entityManager;
    private MockObject $tenantContext;
    private MockObject $security;
    private MockObject $auditLogger;
    private ProcessingActivityService $service;
    private MockObject $tenant;
    private MockObject $user;

    protected function setUp(): void
    {
        $this->processingActivityRepository = $this->createMock(ProcessingActivityRepository::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->tenantContext = $this->createMock(TenantContext::class);
        $this->security = $this->createMock(Security::class);
        $this->auditLogger = $this->createMock(AuditLogger::class);

        $this->tenant = $this->createMock(Tenant::class);
        $this->tenant->method('getId')->willReturn(1);
        $this->tenant->method('getName')->willReturn('Test Tenant');
        $this->tenant->method('getCode')->willReturn('TEST');

        $this->user = $this->createMock(User::class);
        $this->user->method('getUserIdentifier')->willReturn('test@example.com');

        $this->tenantContext->method('getCurrentTenant')->willReturn($this->tenant);
        $this->security->method('getUser')->willReturn($this->user);

        $this->service = new ProcessingActivityService(
            $this->processingActivityRepository,
            $this->entityManager,
            $this->tenantContext,
            $this->security,
            $this->auditLogger
        );
    }

    public function testCreateSetsRequiredFields(): void
    {
        $processingActivity = $this->createMock(ProcessingActivity::class);
        $processingActivity->method('getId')->willReturn(1);
        $processingActivity->method('getName')->willReturn('Test Activity');

        $processingActivity->expects($this->once())->method('setTenant')->with($this->tenant);
        $processingActivity->expects($this->once())->method('setCreatedBy')->with($this->user);
        $processingActivity->expects($this->once())->method('setUpdatedBy')->with($this->user);

        $this->entityManager->expects($this->once())->method('persist')->with($processingActivity);
        $this->entityManager->expects($this->once())->method('flush');

        $result = $this->service->create($processingActivity);

        $this->assertSame($processingActivity, $result);
    }

    public function testUpdateSetsUpdatedBy(): void
    {
        $processingActivity = $this->createMock(ProcessingActivity::class);
        $processingActivity->method('getId')->willReturn(1);
        $processingActivity->method('getName')->willReturn('Test');
        $processingActivity->method('getCompletenessPercentage')->willReturn(80);

        $processingActivity->expects($this->once())->method('setUpdatedBy')->with($this->user);

        $this->entityManager->expects($this->once())->method('flush');

        $result = $this->service->update($processingActivity);

        $this->assertSame($processingActivity, $result);
    }

    public function testDeleteRemovesAndFlushes(): void
    {
        $processingActivity = $this->createMock(ProcessingActivity::class);
        $processingActivity->method('getId')->willReturn(1);
        $processingActivity->method('getName')->willReturn('To Delete');

        $this->entityManager->expects($this->once())->method('remove')->with($processingActivity);
        $this->entityManager->expects($this->once())->method('flush');

        $this->service->delete($processingActivity);
    }

    public function testFindAllReturnsTenantActivities(): void
    {
        $activities = [
            $this->createMock(ProcessingActivity::class),
            $this->createMock(ProcessingActivity::class),
        ];

        $this->processingActivityRepository->method('findByTenant')
            ->with($this->tenant)
            ->willReturn($activities);

        $result = $this->service->findAll();

        $this->assertCount(2, $result);
        $this->assertSame($activities, $result);
    }

    public function testFindActiveReturnsTenantActiveActivities(): void
    {
        $activities = [$this->createMock(ProcessingActivity::class)];

        $this->processingActivityRepository->method('findActiveByTenant')
            ->with($this->tenant)
            ->willReturn($activities);

        $result = $this->service->findActive();

        $this->assertCount(1, $result);
    }

    public function testValidateReturnsErrorsForMissingRequiredFields(): void
    {
        $processingActivity = $this->createMock(ProcessingActivity::class);
        $processingActivity->method('getName')->willReturn('');
        $processingActivity->method('getPurposes')->willReturn([]);
        $processingActivity->method('getDataSubjectCategories')->willReturn([]);
        $processingActivity->method('getPersonalDataCategories')->willReturn([]);
        $processingActivity->method('getLegalBasis')->willReturn(null);
        $processingActivity->method('getRetentionPeriod')->willReturn(null);
        $processingActivity->method('getTechnicalOrganizationalMeasures')->willReturn(null);
        $processingActivity->method('getProcessesSpecialCategories')->willReturn(false);
        $processingActivity->method('getHasThirdCountryTransfer')->willReturn(false);
        $processingActivity->method('getInvolvesProcessors')->willReturn(false);
        $processingActivity->method('getIsJointController')->willReturn(false);
        $processingActivity->method('getHasAutomatedDecisionMaking')->willReturn(false);
        $processingActivity->method('requiresDPIA')->willReturn(false);

        $errors = $this->service->validate($processingActivity);

        $this->assertNotEmpty($errors);
        $this->assertContains('Name of processing activity is required (Art. 30(1)(a))', $errors);
        $this->assertContains('Purpose(s) of processing are required (Art. 30(1)(a))', $errors);
        $this->assertContains('Legal basis for processing is required (Art. 6 GDPR)', $errors);
    }

    public function testValidateReturnsEmptyForCompleteActivity(): void
    {
        $processingActivity = $this->createMock(ProcessingActivity::class);
        $processingActivity->method('getName')->willReturn('Complete Activity');
        $processingActivity->method('getPurposes')->willReturn(['Marketing']);
        $processingActivity->method('getDataSubjectCategories')->willReturn(['Customers']);
        $processingActivity->method('getPersonalDataCategories')->willReturn(['Email']);
        $processingActivity->method('getLegalBasis')->willReturn('consent');
        $processingActivity->method('getLegalBasisDetails')->willReturn('User consent');
        $processingActivity->method('getRetentionPeriod')->willReturn('5 years');
        $processingActivity->method('getTechnicalOrganizationalMeasures')->willReturn('Encryption');
        $processingActivity->method('getProcessesSpecialCategories')->willReturn(false);
        $processingActivity->method('getHasThirdCountryTransfer')->willReturn(false);
        $processingActivity->method('getInvolvesProcessors')->willReturn(false);
        $processingActivity->method('getIsJointController')->willReturn(false);
        $processingActivity->method('getHasAutomatedDecisionMaking')->willReturn(false);
        $processingActivity->method('requiresDPIA')->willReturn(false);

        $errors = $this->service->validate($processingActivity);

        $this->assertEmpty($errors);
    }

    public function testValidateRequiresLegitimateInterestsDetails(): void
    {
        $processingActivity = $this->createMock(ProcessingActivity::class);
        $processingActivity->method('getName')->willReturn('Test');
        $processingActivity->method('getPurposes')->willReturn(['Test']);
        $processingActivity->method('getDataSubjectCategories')->willReturn(['Test']);
        $processingActivity->method('getPersonalDataCategories')->willReturn(['Test']);
        $processingActivity->method('getLegalBasis')->willReturn('legitimate_interests');
        $processingActivity->method('getLegalBasisDetails')->willReturn(null);
        $processingActivity->method('getRetentionPeriod')->willReturn('1 year');
        $processingActivity->method('getTechnicalOrganizationalMeasures')->willReturn('TOM');
        $processingActivity->method('getProcessesSpecialCategories')->willReturn(false);
        $processingActivity->method('getHasThirdCountryTransfer')->willReturn(false);
        $processingActivity->method('getInvolvesProcessors')->willReturn(false);
        $processingActivity->method('getIsJointController')->willReturn(false);
        $processingActivity->method('getHasAutomatedDecisionMaking')->willReturn(false);
        $processingActivity->method('requiresDPIA')->willReturn(false);

        $errors = $this->service->validate($processingActivity);

        $this->assertContains('Legitimate interests must be detailed and documented (Art. 6(1)(f))', $errors);
    }

    public function testValidateRequiresDPIAWhenRequired(): void
    {
        $processingActivity = $this->createMock(ProcessingActivity::class);
        $processingActivity->method('getName')->willReturn('High Risk Activity');
        $processingActivity->method('getPurposes')->willReturn(['Profiling']);
        $processingActivity->method('getDataSubjectCategories')->willReturn(['Users']);
        $processingActivity->method('getPersonalDataCategories')->willReturn(['Health']);
        $processingActivity->method('getLegalBasis')->willReturn('consent');
        $processingActivity->method('getRetentionPeriod')->willReturn('10 years');
        $processingActivity->method('getTechnicalOrganizationalMeasures')->willReturn('Encryption');
        $processingActivity->method('getProcessesSpecialCategories')->willReturn(false);
        $processingActivity->method('getHasThirdCountryTransfer')->willReturn(false);
        $processingActivity->method('getInvolvesProcessors')->willReturn(false);
        $processingActivity->method('getIsJointController')->willReturn(false);
        $processingActivity->method('getHasAutomatedDecisionMaking')->willReturn(false);
        $processingActivity->method('requiresDPIA')->willReturn(true);
        $processingActivity->method('getDpiaCompleted')->willReturn(false);

        $errors = $this->service->validate($processingActivity);

        $this->assertContains('Data Protection Impact Assessment (DPIA) is required for this processing activity (Art. 35)', $errors);
    }

    public function testIsCompliantReturnsTrueForValidActivity(): void
    {
        $processingActivity = $this->createMock(ProcessingActivity::class);
        $processingActivity->method('getName')->willReturn('Valid');
        $processingActivity->method('getPurposes')->willReturn(['Purpose']);
        $processingActivity->method('getDataSubjectCategories')->willReturn(['Category']);
        $processingActivity->method('getPersonalDataCategories')->willReturn(['Data']);
        $processingActivity->method('getLegalBasis')->willReturn('consent');
        $processingActivity->method('getRetentionPeriod')->willReturn('1 year');
        $processingActivity->method('getTechnicalOrganizationalMeasures')->willReturn('TOM');
        $processingActivity->method('getProcessesSpecialCategories')->willReturn(false);
        $processingActivity->method('getHasThirdCountryTransfer')->willReturn(false);
        $processingActivity->method('getInvolvesProcessors')->willReturn(false);
        $processingActivity->method('getIsJointController')->willReturn(false);
        $processingActivity->method('getHasAutomatedDecisionMaking')->willReturn(false);
        $processingActivity->method('requiresDPIA')->willReturn(false);

        $result = $this->service->isCompliant($processingActivity);

        $this->assertTrue($result);
    }

    public function testGenerateComplianceReportReturnsCorrectStructure(): void
    {
        $processingActivity = $this->createMock(ProcessingActivity::class);
        $processingActivity->method('getId')->willReturn(1);
        $processingActivity->method('getName')->willReturn('Test Activity');
        $processingActivity->method('getStatus')->willReturn('active');
        $processingActivity->method('getCompletenessPercentage')->willReturn(85);
        $processingActivity->method('getPurposes')->willReturn(['Purpose']);
        $processingActivity->method('getDataSubjectCategories')->willReturn(['Category']);
        $processingActivity->method('getPersonalDataCategories')->willReturn(['Data']);
        $processingActivity->method('getLegalBasis')->willReturn('consent');
        $processingActivity->method('getRetentionPeriod')->willReturn('1 year');
        $processingActivity->method('getTechnicalOrganizationalMeasures')->willReturn('TOM');
        $processingActivity->method('getProcessesSpecialCategories')->willReturn(false);
        $processingActivity->method('getHasThirdCountryTransfer')->willReturn(false);
        $processingActivity->method('getInvolvesProcessors')->willReturn(false);
        $processingActivity->method('getIsJointController')->willReturn(false);
        $processingActivity->method('getHasAutomatedDecisionMaking')->willReturn(false);
        $processingActivity->method('requiresDPIA')->willReturn(false);
        $processingActivity->method('getDpiaCompleted')->willReturn(false);
        $processingActivity->method('getIsHighRisk')->willReturn(false);
        $processingActivity->method('getRiskLevel')->willReturn('low');
        $processingActivity->method('getProcessesCriminalData')->willReturn(false);
        $processingActivity->method('getLegalBasisSpecialCategories')->willReturn(null);
        $processingActivity->method('getTransferSafeguards')->willReturn(null);

        $report = $this->service->generateComplianceReport($processingActivity);

        $this->assertArrayHasKey('processing_activity_id', $report);
        $this->assertArrayHasKey('name', $report);
        $this->assertArrayHasKey('status', $report);
        $this->assertArrayHasKey('completeness_percentage', $report);
        $this->assertArrayHasKey('is_compliant', $report);
        $this->assertArrayHasKey('validation_errors', $report);
        $this->assertArrayHasKey('compliance_checks', $report);
        $this->assertArrayHasKey('risk_assessment', $report);

        $this->assertSame(1, $report['processing_activity_id']);
        $this->assertSame('Test Activity', $report['name']);
        $this->assertSame(85, $report['completeness_percentage']);
    }

    public function testActivateThrowsExceptionForInvalidActivity(): void
    {
        $processingActivity = $this->createMock(ProcessingActivity::class);
        $processingActivity->method('getName')->willReturn('');
        $processingActivity->method('getPurposes')->willReturn([]);
        $processingActivity->method('getDataSubjectCategories')->willReturn([]);
        $processingActivity->method('getPersonalDataCategories')->willReturn([]);
        $processingActivity->method('getLegalBasis')->willReturn(null);
        $processingActivity->method('getRetentionPeriod')->willReturn(null);
        $processingActivity->method('getTechnicalOrganizationalMeasures')->willReturn(null);
        $processingActivity->method('getProcessesSpecialCategories')->willReturn(false);
        $processingActivity->method('getHasThirdCountryTransfer')->willReturn(false);
        $processingActivity->method('getInvolvesProcessors')->willReturn(false);
        $processingActivity->method('getIsJointController')->willReturn(false);
        $processingActivity->method('getHasAutomatedDecisionMaking')->willReturn(false);
        $processingActivity->method('requiresDPIA')->willReturn(false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot activate processing activity with validation errors');

        $this->service->activate($processingActivity);
    }

    public function testMarkForReviewSetsNextReviewDate(): void
    {
        $processingActivity = $this->createMock(ProcessingActivity::class);
        $processingActivity->method('getId')->willReturn(1);

        $reviewDate = new DateTime('+6 months');

        $processingActivity->expects($this->once())
            ->method('setNextReviewDate')
            ->with($reviewDate);

        $this->entityManager->expects($this->once())->method('flush');

        $this->service->markForReview($processingActivity, $reviewDate);
    }

    public function testMarkForReviewDefaultsTo12Months(): void
    {
        $processingActivity = $this->createMock(ProcessingActivity::class);
        $processingActivity->method('getId')->willReturn(1);

        $processingActivity->expects($this->once())
            ->method('setNextReviewDate')
            ->with($this->callback(function ($date) {
                $expected = new DateTime('+12 months');
                return abs($date->getTimestamp() - $expected->getTimestamp()) < 86400;
            }));

        $this->entityManager->expects($this->once())->method('flush');

        $this->service->markForReview($processingActivity);
    }

    public function testCompleteReviewUpdatesReviewDates(): void
    {
        $processingActivity = $this->createMock(ProcessingActivity::class);
        $processingActivity->method('getId')->willReturn(1);

        $processingActivity->expects($this->once())->method('setLastReviewDate');
        $processingActivity->expects($this->once())->method('setNextReviewDate');

        $this->entityManager->expects($this->once())->method('flush');

        $this->service->completeReview($processingActivity);
    }

    public function testArchiveSetsStatusAndEndDate(): void
    {
        $processingActivity = $this->createMock(ProcessingActivity::class);
        $processingActivity->method('getId')->willReturn(1);
        $processingActivity->method('getName')->willReturn('Archived');

        $processingActivity->expects($this->once())->method('setStatus')->with('archived');
        $processingActivity->expects($this->once())->method('setEndDate');

        $this->entityManager->expects($this->once())->method('flush');

        $this->service->archive($processingActivity);
    }

    public function testCalculateComplianceScoreReturnsCorrectStructure(): void
    {
        $this->processingActivityRepository->method('findByTenant')
            ->with($this->tenant)
            ->willReturn([]);

        $result = $this->service->calculateComplianceScore();

        $this->assertArrayHasKey('overall_score', $result);
        $this->assertArrayHasKey('total_activities', $result);
        $this->assertArrayHasKey('complete_activities', $result);
        $this->assertArrayHasKey('incomplete_activities', $result);
        $this->assertArrayHasKey('dpia_required', $result);
        $this->assertArrayHasKey('dpia_completed', $result);
        $this->assertArrayHasKey('average_completeness', $result);

        $this->assertSame(0, $result['overall_score']);
        $this->assertSame(0, $result['total_activities']);
    }

    public function testCalculateComplianceScoreWithActivities(): void
    {
        $activity1 = $this->createMock(ProcessingActivity::class);
        $activity1->method('isComplete')->willReturn(true);
        $activity1->method('requiresDPIA')->willReturn(false);
        $activity1->method('getCompletenessPercentage')->willReturn(100);

        $activity2 = $this->createMock(ProcessingActivity::class);
        $activity2->method('isComplete')->willReturn(false);
        $activity2->method('requiresDPIA')->willReturn(true);
        $activity2->method('getDpiaCompleted')->willReturn(true);
        $activity2->method('getCompletenessPercentage')->willReturn(80);

        $this->processingActivityRepository->method('findByTenant')
            ->with($this->tenant)
            ->willReturn([$activity1, $activity2]);

        $result = $this->service->calculateComplianceScore();

        $this->assertSame(2, $result['total_activities']);
        $this->assertSame(1, $result['complete_activities']);
        $this->assertSame(1, $result['incomplete_activities']);
        $this->assertSame(1, $result['dpia_required']);
        $this->assertSame(1, $result['dpia_completed']);
        $this->assertSame(90.0, $result['average_completeness']); // (100 + 80) / 2
    }
}
