<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\AuditFinding;
use App\Entity\ManagementReview;
use App\Entity\PolicyAcknowledgement;
use App\Entity\Risk;
use App\Entity\Tenant;
use App\Repository\AuditFindingRepository;
use App\Repository\DocumentRepository;
use App\Repository\ManagementReviewRepository;
use App\Repository\PolicyAcknowledgementRepository;
use App\Repository\RiskRepository;
use App\Service\CertBundleReadinessService;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * V4-EF-8: Unit tests for CertBundleReadinessService.
 *
 * Tests:
 *   1. ready=true when all 5 checks pass
 *   2. blocked on open major findings
 *   3. warning on open minor findings (still ready=true for blockers)
 *   4. score calculation (100 → deductions per blocker/warning)
 *   5. framework mapping / multiple blockers lower score additively
 */
#[AllowMockObjectsWithoutExpectations]
class CertBundleReadinessServiceTest extends TestCase
{
    private MockObject $documentRepository;
    private MockObject $ackRepository;
    private MockObject $findingRepository;
    private MockObject $riskRepository;
    private MockObject $reviewRepository;
    private Tenant $tenant;
    private CertBundleReadinessService $service;

    protected function setUp(): void
    {
        $this->documentRepository = $this->createMock(DocumentRepository::class);
        $this->ackRepository      = $this->createMock(PolicyAcknowledgementRepository::class);
        $this->findingRepository  = $this->createMock(AuditFindingRepository::class);
        $this->riskRepository     = $this->createMock(RiskRepository::class);
        $this->reviewRepository   = $this->createMock(ManagementReviewRepository::class);
        $this->tenant             = $this->createMock(Tenant::class);

        $this->service = new CertBundleReadinessService(
            $this->documentRepository,
            $this->ackRepository,
            $this->findingRepository,
            $this->riskRepository,
            $this->reviewRepository,
        );
    }

    #[Test]
    public function readyWhenAllChecksPass(): void
    {
        // No documents pending
        $this->documentRepository->method('findByTenant')->willReturn([]);
        // No pending acks
        $this->ackRepository->method('findBy')->willReturn([]);
        // No open findings
        $this->findingRepository->method('findOpenByTenant')->willReturn([]);
        // Recent risk
        $risk = $this->createMock(Risk::class);
        $risk->method('getUpdatedAt')->willReturn(new DateTimeImmutable('-1 month'));
        $this->riskRepository->method('findByTenant')->willReturn([$risk]);
        // Recent management review
        $review = $this->createMock(ManagementReview::class);
        $review->method('getReviewDate')->willReturn(new DateTimeImmutable('-3 months'));
        $review->method('getStatus')->willReturn('completed');
        $this->reviewRepository->method('findBy')->willReturn([$review]);

        $result = $this->service->check($this->tenant, 'ISO27001');

        $this->assertTrue($result['ready']);
        $this->assertSame([], $result['blockers']);
        $this->assertSame([], $result['warnings']);
        $this->assertSame(100, $result['score']);
    }

    #[Test]
    public function blockedWhenOpenMajorFindingsExist(): void
    {
        $this->documentRepository->method('findByTenant')->willReturn([]);
        $this->ackRepository->method('findBy')->willReturn([]);

        $majorFinding = $this->createMock(AuditFinding::class);
        $majorFinding->method('getType')->willReturn(AuditFinding::TYPE_MAJOR_NC);
        $this->findingRepository->method('findOpenByTenant')->willReturn([$majorFinding]);

        $risk = $this->createMock(Risk::class);
        $risk->method('getUpdatedAt')->willReturn(new DateTimeImmutable('-1 month'));
        $this->riskRepository->method('findByTenant')->willReturn([$risk]);

        $review = $this->createMock(ManagementReview::class);
        $review->method('getReviewDate')->willReturn(new DateTimeImmutable('-3 months'));
        $review->method('getStatus')->willReturn('completed');
        $this->reviewRepository->method('findBy')->willReturn([$review]);

        $result = $this->service->check($this->tenant, 'ISO27001');

        $this->assertFalse($result['ready']);
        $this->assertNotEmpty($result['blockers']);
        $blockerTypes = array_column($result['blockers'], 'type');
        $this->assertContains('open_major_findings', $blockerTypes);
    }

    #[Test]
    public function warningsOnlyWhenOpenMinorFindingsExist(): void
    {
        $this->documentRepository->method('findByTenant')->willReturn([]);
        $this->ackRepository->method('findBy')->willReturn([]);

        $minorFinding = $this->createMock(AuditFinding::class);
        $minorFinding->method('getType')->willReturn(AuditFinding::TYPE_MINOR_NC);
        $this->findingRepository->method('findOpenByTenant')->willReturn([$minorFinding]);

        $risk = $this->createMock(Risk::class);
        $risk->method('getUpdatedAt')->willReturn(new DateTimeImmutable('-1 month'));
        $this->riskRepository->method('findByTenant')->willReturn([$risk]);

        $review = $this->createMock(ManagementReview::class);
        $review->method('getReviewDate')->willReturn(new DateTimeImmutable('-3 months'));
        $review->method('getStatus')->willReturn('completed');
        $this->reviewRepository->method('findBy')->willReturn([$review]);

        $result = $this->service->check($this->tenant, 'ISO27001');

        // Minor findings do NOT block — ready = true (no blockers from minor NCs)
        $this->assertSame([], $result['blockers']);
        $this->assertNotEmpty($result['warnings']);
        $warningTypes = array_column($result['warnings'], 'type');
        $this->assertContains('open_minor_findings', $warningTypes);
        // Score reduced by 5 for one warning
        $this->assertSame(95, $result['score']);
    }

    #[Test]
    public function scoreCalculationDeductsPerBlockerAndWarning(): void
    {
        // Setup: 2 blockers expected (no risks + outdated management review)
        $this->documentRepository->method('findByTenant')->willReturn([]);
        $this->ackRepository->method('findBy')->willReturn([]);
        $this->findingRepository->method('findOpenByTenant')->willReturn([]);
        // No risks → blocker
        $this->riskRepository->method('findByTenant')->willReturn([]);
        // No management reviews → blocker
        $this->reviewRepository->method('findBy')->willReturn([]);

        $result = $this->service->check($this->tenant, 'ISO27001');

        // 2 blockers × 20 = -40 → score 60
        $this->assertFalse($result['ready']);
        $this->assertCount(2, $result['blockers']);
        $this->assertSame(60, $result['score']);
    }

    #[Test]
    public function scoreIsClampedToZeroWhenManyBlockers(): void
    {
        // 5 blockers would exceed 100 deduction: score must be >= 0
        $pendingDoc = $this->createMock(\App\Entity\Document::class);
        $pendingDoc->method('getStatus')->willReturn('pending_approval');
        $this->documentRepository->method('findByTenant')->willReturn([$pendingDoc]);

        $pendingAck = $this->createMock(PolicyAcknowledgement::class);
        $pendingAck->method('getStatus')->willReturn(PolicyAcknowledgement::STATUS_PENDING);
        $this->ackRepository->method('findBy')->willReturn([$pendingAck]);

        $majorFinding = $this->createMock(AuditFinding::class);
        $majorFinding->method('getType')->willReturn(AuditFinding::TYPE_MAJOR_NC);
        $this->findingRepository->method('findOpenByTenant')->willReturn([$majorFinding]);

        // No risks → blocker
        $this->riskRepository->method('findByTenant')->willReturn([]);
        // No management reviews → blocker
        $this->reviewRepository->method('findBy')->willReturn([]);

        $result = $this->service->check($this->tenant, 'ISO27001');

        // 5 blockers × 20 = 100 deduction → score 0 (clamped)
        $this->assertSame(0, $result['score']);
        $this->assertGreaterThanOrEqual(0, $result['score']);
        $this->assertCount(5, $result['blockers']);
    }
}
