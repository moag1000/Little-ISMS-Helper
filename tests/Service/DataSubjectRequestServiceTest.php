<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\DataSubjectRequest;
use App\Entity\Tenant;
use App\Exception\BusinessRule\BusinessRuleException;
use App\Lifecycle\LifecycleTransitionInterface;
use App\Repository\DataSubjectRequestRepository;
use App\Service\AuditLogger;
use App\Service\DataSubjectRequestService;
use App\Service\TenantContext;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Focus: the GDPR Art. 12(6) identity-verification gate in complete().
 *
 * Completing an access/portability/erasure/rectification request for an
 * unverified subject would disclose or destroy personal data for a possibly
 * wrong person — itself a reportable breach. The gate must be a hard block,
 * and it must be scoped to data-releasing types only.
 */
#[AllowMockObjectsWithoutExpectations]
class DataSubjectRequestServiceTest extends TestCase
{
    private MockObject $entityManager;
    private MockObject $repository;
    private MockObject $tenantContext;
    private MockObject $auditLogger;
    private MockObject $logger;
    private MockObject $lifecycleService;
    private DataSubjectRequestService $service;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->repository = $this->createMock(DataSubjectRequestRepository::class);
        $this->tenantContext = $this->createMock(TenantContext::class);
        $this->auditLogger = $this->createMock(AuditLogger::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->lifecycleService = $this->createMock(LifecycleTransitionInterface::class);

        $this->tenantContext->method('getCurrentTenant')->willReturn($this->createMock(Tenant::class));

        $this->service = new DataSubjectRequestService(
            $this->entityManager,
            $this->repository,
            $this->tenantContext,
            $this->auditLogger,
            $this->logger,
            $this->lifecycleService,
        );
    }

    #[Test]
    public function testCompleteThrowsWhenIdentityNotVerifiedForAccessRequest(): void
    {
        $request = $this->createMock(DataSubjectRequest::class);
        $request->method('getStatus')->willReturn('in_progress');
        $request->method('getRequestType')->willReturn('access');
        $request->method('isIdentityVerified')->willReturn(false);

        // Nothing may be persisted or transitioned — the release is blocked.
        $this->entityManager->expects($this->never())->method('flush');
        $this->lifecycleService->expects($this->never())->method('transition');

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('Identity must be verified before completing this request type (Art. 12(6) GDPR)');

        $this->service->complete($request, 'Here is your data export');
    }

    #[Test]
    public function testCompleteSucceedsWhenIdentityVerifiedForAccessRequest(): void
    {
        $now = new DateTimeImmutable();

        $request = $this->createMock(DataSubjectRequest::class);
        $request->method('getId')->willReturn(1);
        $request->method('getStatus')->willReturn('in_progress');
        $request->method('getRequestType')->willReturn('access');
        $request->method('isIdentityVerified')->willReturn(true);
        $request->method('getReceivedAt')->willReturn($now);
        $request->method('getCompletedAt')->willReturn($now);

        $request->expects($this->once())->method('setCompletedAt');
        $request->expects($this->once())->method('setResponseDescription')->with('Here is your data export');
        $this->lifecycleService->expects($this->once())->method('transition')
            ->with($request, 'data_subject_request_lifecycle', 'complete');
        $this->entityManager->expects($this->once())->method('flush');

        $this->service->complete($request, 'Here is your data export');
    }

    #[Test]
    public function testCompleteDoesNotRequireIdentityForObjectionRequest(): void
    {
        // Scope proof: objection (Art. 21) acts on processing, not data release,
        // so it is intentionally NOT gated — it must complete unverified.
        $now = new DateTimeImmutable();

        $request = $this->createMock(DataSubjectRequest::class);
        $request->method('getId')->willReturn(2);
        $request->method('getStatus')->willReturn('in_progress');
        $request->method('getRequestType')->willReturn('objection');
        $request->method('isIdentityVerified')->willReturn(false);
        $request->method('getReceivedAt')->willReturn($now);
        $request->method('getCompletedAt')->willReturn($now);

        $this->lifecycleService->expects($this->once())->method('transition')
            ->with($request, 'data_subject_request_lifecycle', 'complete');
        $this->entityManager->expects($this->once())->method('flush');

        $this->service->complete($request, 'Objection upheld, processing stopped');
    }

    #[Test]
    public function testCompleteThrowsForRequestAlreadyInTerminalState(): void
    {
        $request = $this->createMock(DataSubjectRequest::class);
        $request->method('getStatus')->willReturn('completed');

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('Request is already in a terminal state');

        $this->service->complete($request, 'Late response');
    }
}
