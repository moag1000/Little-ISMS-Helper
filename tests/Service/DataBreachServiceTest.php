<?php

namespace App\Tests\Service;

use App\Entity\DataBreach;
use App\Entity\Incident;
use App\Entity\ProcessingActivity;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\DataBreachRepository;
use App\Service\AuditLogger;
use App\Service\DataBreachService;
use App\Service\TenantContext;
use App\Service\WorkflowAutoProgressionService;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class DataBreachServiceTest extends TestCase
{
    private MockObject $entityManager;
    private MockObject $dataBreachRepository;
    private MockObject $tenantContext;
    private MockObject $auditLogger;
    private MockObject $logger;
    private MockObject $workflowAutoProgressionService;
    private DataBreachService $service;
    private MockObject $tenant;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->dataBreachRepository = $this->createMock(DataBreachRepository::class);
        $this->tenantContext = $this->createMock(TenantContext::class);
        $this->auditLogger = $this->createMock(AuditLogger::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->workflowAutoProgressionService = $this->createMock(WorkflowAutoProgressionService::class);

        $this->tenant = $this->createMock(Tenant::class);
        $this->tenant->method('getId')->willReturn(1);

        $this->service = new DataBreachService(
            $this->entityManager,
            $this->dataBreachRepository,
            $this->tenantContext,
            $this->auditLogger,
            $this->logger,
            $this->workflowAutoProgressionService
        );
    }

    public function testPrepareNewBreachThrowsExceptionWithoutTenant(): void
    {
        $this->tenantContext->method('getCurrentTenant')->willReturn(null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No tenant context available');

        $this->service->prepareNewBreach();
    }

    public function testPrepareNewBreachCreatesBreachWithDefaults(): void
    {
        $this->tenantContext->method('getCurrentTenant')->willReturn($this->tenant);
        $this->dataBreachRepository->method('getNextReferenceNumber')
            ->with($this->tenant)
            ->willReturn('DB-2025-001');

        $dataBreach = $this->service->prepareNewBreach();

        $this->assertInstanceOf(DataBreach::class, $dataBreach);
        $this->assertSame($this->tenant, $dataBreach->getTenant());
        $this->assertSame('DB-2025-001', $dataBreach->getReferenceNumber());
        $this->assertSame('draft', $dataBreach->getStatus());
        $this->assertFalse($dataBreach->getRequiresAuthorityNotification());
        $this->assertFalse($dataBreach->getRequiresSubjectNotification());
    }

    public function testCreateFromIncidentThrowsExceptionWithoutTenant(): void
    {
        $this->tenantContext->method('getCurrentTenant')->willReturn(null);
        $incident = $this->createMock(Incident::class);
        $user = $this->createMock(User::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No tenant context available');

        $this->service->createFromIncident($incident, $user);
    }

    public function testCreateFromIncidentCreatesBreachWithCorrectData(): void
    {
        $this->tenantContext->method('getCurrentTenant')->willReturn($this->tenant);
        $this->dataBreachRepository->method('getNextReferenceNumber')
            ->with($this->tenant)
            ->willReturn('DB-2025-002');

        $incident = $this->createMock(Incident::class);
        $incident->method('getId')->willReturn(42);
        $incident->method('getTitle')->willReturn('Security Incident');
        $incident->method('getSeverity')->willReturn('high');

        $user = $this->createMock(User::class);
        $user->method('getEmail')->willReturn('user@example.com');

        $this->entityManager->expects($this->once())->method('persist');
        $this->entityManager->expects($this->once())->method('flush');

        $dataBreach = $this->service->createFromIncident($incident, $user);

        $this->assertSame($this->tenant, $dataBreach->getTenant());
        $this->assertSame('DB-2025-002', $dataBreach->getReferenceNumber());
        $this->assertSame($incident, $dataBreach->getIncident());
        $this->assertSame($user, $dataBreach->getCreatedBy());
        $this->assertStringContainsString('Security Incident', $dataBreach->getTitle());
        $this->assertSame('high', $dataBreach->getSeverity());
        $this->assertTrue($dataBreach->getRequiresAuthorityNotification());
        $this->assertFalse($dataBreach->getRequiresSubjectNotification());
        $this->assertSame('draft', $dataBreach->getStatus());
    }

    public function testCreateFromIncidentWithProcessingActivity(): void
    {
        $this->tenantContext->method('getCurrentTenant')->willReturn($this->tenant);
        $this->dataBreachRepository->method('getNextReferenceNumber')->willReturn('DB-2025-003');

        $incident = $this->createMock(Incident::class);
        $incident->method('getId')->willReturn(1);
        $incident->method('getTitle')->willReturn('Test');
        $incident->method('getSeverity')->willReturn('medium');

        $user = $this->createMock(User::class);
        $user->method('getEmail')->willReturn('user@example.com');

        $processingActivity = $this->createMock(ProcessingActivity::class);

        $this->entityManager->expects($this->once())->method('persist');
        $this->entityManager->expects($this->once())->method('flush');

        $dataBreach = $this->service->createFromIncident($incident, $user, $processingActivity);

        $this->assertSame($processingActivity, $dataBreach->getProcessingActivity());
    }

    public function testCreateStandaloneThrowsExceptionWithoutTenant(): void
    {
        $this->tenantContext->method('getCurrentTenant')->willReturn(null);
        $user = $this->createMock(User::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No tenant context available');

        $this->service->createStandalone($user, new DateTime());
    }

    public function testCreateStandaloneCreatesBreachWithoutIncident(): void
    {
        $this->tenantContext->method('getCurrentTenant')->willReturn($this->tenant);
        $this->dataBreachRepository->method('getNextReferenceNumber')->willReturn('DB-2025-004');

        $user = $this->createMock(User::class);
        $detectedAt = new DateTime();

        $dataBreach = $this->service->createStandalone($user, $detectedAt);

        $this->assertNull($dataBreach->getIncident());
        $this->assertSame($detectedAt, $dataBreach->getDetectedAt());
        $this->assertSame($user, $dataBreach->getCreatedBy());
        $this->assertSame('draft', $dataBreach->getStatus());
    }

    public function testUpdateExistingBreach(): void
    {
        $dataBreach = $this->createMock(DataBreach::class);
        $dataBreach->method('getId')->willReturn(1);
        $dataBreach->method('getReferenceNumber')->willReturn('DB-2025-001');

        $user = $this->createMock(User::class);
        $user->method('getEmail')->willReturn('updater@example.com');

        $dataBreach->expects($this->once())->method('setUpdatedBy')->with($user);

        $this->entityManager->expects($this->never())->method('persist');
        $this->entityManager->expects($this->once())->method('flush');

        $result = $this->service->update($dataBreach, $user);

        $this->assertSame($dataBreach, $result);
    }

    public function testUpdateNewBreach(): void
    {
        $dataBreach = $this->createMock(DataBreach::class);
        $dataBreach->method('getId')->willReturn(null); // New entity
        $dataBreach->method('getReferenceNumber')->willReturn('DB-2025-001');

        $user = $this->createMock(User::class);
        $user->method('getEmail')->willReturn('creator@example.com');

        $dataBreach->expects($this->once())->method('setUpdatedBy')->with($user);

        $this->entityManager->expects($this->once())->method('persist')->with($dataBreach);
        $this->entityManager->expects($this->once())->method('flush');

        $result = $this->service->update($dataBreach, $user);

        $this->assertSame($dataBreach, $result);
    }

    public function testDelete(): void
    {
        $dataBreach = $this->createMock(DataBreach::class);
        $dataBreach->method('getId')->willReturn(1);
        $dataBreach->method('getReferenceNumber')->willReturn('DB-2025-001');

        $this->entityManager->expects($this->once())->method('remove')->with($dataBreach);
        $this->entityManager->expects($this->once())->method('flush');

        $this->service->delete($dataBreach);
    }

    public function testSubmitForAssessmentThrowsExceptionForNonDraftStatus(): void
    {
        $dataBreach = $this->createMock(DataBreach::class);
        $dataBreach->method('getStatus')->willReturn('under_assessment');

        $user = $this->createMock(User::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Only draft data breaches can be submitted for assessment');

        $this->service->submitForAssessment($dataBreach, $user);
    }

    public function testSubmitForAssessmentThrowsExceptionForIncompleteData(): void
    {
        $dataBreach = $this->createMock(DataBreach::class);
        $dataBreach->method('getStatus')->willReturn('draft');
        $dataBreach->method('isComplete')->willReturn(false);
        $dataBreach->method('getCompletenessPercentage')->willReturn(50);

        $user = $this->createMock(User::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Data breach must be complete before assessment');

        $this->service->submitForAssessment($dataBreach, $user);
    }

    public function testNotifySupervisoryAuthorityThrowsExceptionWhenNotRequired(): void
    {
        $dataBreach = $this->createMock(DataBreach::class);
        $dataBreach->method('getRequiresAuthorityNotification')->willReturn(false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not require supervisory authority notification');

        $this->service->notifySupervisoryAuthority($dataBreach, 'Authority', 'email');
    }

    public function testNotifySupervisoryAuthorityThrowsExceptionWhenAlreadyNotified(): void
    {
        $dataBreach = $this->createMock(DataBreach::class);
        $dataBreach->method('getRequiresAuthorityNotification')->willReturn(true);
        $dataBreach->method('getSupervisoryAuthorityNotifiedAt')->willReturn(new DateTime());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Supervisory authority has already been notified');

        $this->service->notifySupervisoryAuthority($dataBreach, 'Authority', 'email');
    }

    public function testNotifyDataSubjectsThrowsExceptionWhenNotRequired(): void
    {
        $dataBreach = $this->createMock(DataBreach::class);
        $dataBreach->method('getRequiresSubjectNotification')->willReturn(false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not require data subject notification');

        $this->service->notifyDataSubjects($dataBreach, 'email', 100);
    }

    public function testNotifyDataSubjectsThrowsExceptionWhenAlreadyNotified(): void
    {
        $dataBreach = $this->createMock(DataBreach::class);
        $dataBreach->method('getRequiresSubjectNotification')->willReturn(true);
        $dataBreach->method('getDataSubjectsNotifiedAt')->willReturn(new DateTime());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Data subjects have already been notified');

        $this->service->notifyDataSubjects($dataBreach, 'email', 100);
    }

    public function testCloseThrowsExceptionForInvalidStatus(): void
    {
        $dataBreach = $this->createMock(DataBreach::class);
        $dataBreach->method('getStatus')->willReturn('draft');

        $user = $this->createMock(User::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must be in authority_notified or subjects_notified status');

        $this->service->close($dataBreach, $user);
    }

    public function testCloseThrowsExceptionWhenAuthorityNotificationRequired(): void
    {
        $dataBreach = $this->createMock(DataBreach::class);
        $dataBreach->method('getStatus')->willReturn('authority_notified');
        $dataBreach->method('getRequiresAuthorityNotification')->willReturn(true);
        $dataBreach->method('getSupervisoryAuthorityNotifiedAt')->willReturn(null);

        $user = $this->createMock(User::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Supervisory authority notification required before closing');

        $this->service->close($dataBreach, $user);
    }

    public function testReopenThrowsExceptionForNonClosedStatus(): void
    {
        $dataBreach = $this->createMock(DataBreach::class);
        $dataBreach->method('getStatus')->willReturn('draft');

        $user = $this->createMock(User::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Only closed data breaches can be reopened');

        $this->service->reopen($dataBreach, $user, 'New information received');
    }

    public function testRecordNotificationDelayThrowsExceptionWhenNotOverdue(): void
    {
        $dataBreach = $this->createMock(DataBreach::class);
        $dataBreach->method('isAuthorityNotificationOverdue')->willReturn(false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Notification is not overdue');

        $this->service->recordNotificationDelay($dataBreach, 'Some reason');
    }

    public function testFindAllReturnsEmptyArrayWithoutTenant(): void
    {
        $this->tenantContext->method('getCurrentTenant')->willReturn(null);

        $result = $this->service->findAll();

        $this->assertSame([], $result);
    }

    public function testFindAllReturnsTenantBreaches(): void
    {
        $this->tenantContext->method('getCurrentTenant')->willReturn($this->tenant);

        $breaches = [
            $this->createMock(DataBreach::class),
            $this->createMock(DataBreach::class),
        ];

        $this->dataBreachRepository->method('findByTenant')
            ->with($this->tenant)
            ->willReturn($breaches);

        $result = $this->service->findAll();

        $this->assertCount(2, $result);
        $this->assertSame($breaches, $result);
    }

    public function testGetDashboardStatisticsReturnsEmptyWithoutTenant(): void
    {
        $this->tenantContext->method('getCurrentTenant')->willReturn(null);

        $result = $this->service->getDashboardStatistics();

        $this->assertSame(0, $result['total']);
        $this->assertSame(0, $result['draft']);
    }

    public function testCalculateComplianceScoreReturns100ForNoBreaches(): void
    {
        $this->tenantContext->method('getCurrentTenant')->willReturn($this->tenant);

        $this->dataBreachRepository->method('getDashboardStatistics')
            ->with($this->tenant)
            ->willReturn([
                'requires_authority_notification' => 0,
                'authority_notified' => 0,
                'completeness_rate' => 100,
            ]);
        $this->dataBreachRepository->method('findByTenant')
            ->with($this->tenant)
            ->willReturn([]);
        $this->dataBreachRepository->method('findAuthorityNotificationOverdue')
            ->with($this->tenant)
            ->willReturn([]);

        $result = $this->service->calculateComplianceScore();

        $this->assertSame(100, $result['overall_score']);
        $this->assertSame(0, $result['overdue_notifications']);
    }

    public function testGetActionItemsReturnsEmptyWithoutTenant(): void
    {
        $this->tenantContext->method('getCurrentTenant')->willReturn(null);

        $result = $this->service->getActionItems();

        $this->assertSame([], $result);
    }
}
