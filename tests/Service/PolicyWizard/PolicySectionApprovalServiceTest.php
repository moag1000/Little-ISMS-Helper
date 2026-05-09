<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyWizard;

use App\Entity\Document;
use App\Entity\DocumentSection;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\DocumentSectionRepository;
use App\Service\AuditLogger;
use App\Service\PolicyWizard\PolicySectionApprovalService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PolicySectionApprovalService — Phase 4-C / W3-C.
 *
 * Mocks the EntityManager + DocumentSectionRepository + AuditLogger so
 * we can assert the state-machine transitions without booting the
 * Symfony kernel. Host-workflow advancement is exercised via the
 * `findOneBy` lookup.
 */
#[AllowMockObjectsWithoutExpectations]
class PolicySectionApprovalServiceTest extends TestCase
{
    private EntityManagerInterface $em;
    private DocumentSectionRepository $sectionRepo;
    private AuditLogger $auditLogger;
    private PolicySectionApprovalService $service;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->sectionRepo = $this->createMock(DocumentSectionRepository::class);
        $this->auditLogger = $this->createMock(AuditLogger::class);

        // Default: no host WorkflowInstance exists — the
        // maybeAdvanceHostWorkflow() path silently no-ops.
        $hostRepo = $this->createMock(EntityRepository::class);
        $hostRepo->method('findOneBy')->willReturn(null);
        $this->em->method('getRepository')->willReturn($hostRepo);

        $this->service = new PolicySectionApprovalService(
            $this->em,
            $this->sectionRepo,
            $this->auditLogger,
        );
    }

    private function makeSection(
        string $sectionKey = 'privacy_addendum',
        string $status = DocumentSection::STATUS_DRAFT,
    ): DocumentSection {
        $tenant = $this->createMock(Tenant::class);
        $tenant->method('getId')->willReturn(1);

        $document = $this->createMock(Document::class);
        $document->method('getId')->willReturn(99);

        $section = new DocumentSection();
        $section->setSectionKey($sectionKey);
        $section->setStatus($status);
        $section->setTenant($tenant);
        $section->setDocument($document);
        return $section;
    }

    private function makeUser(int $id = 7): User
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn($id);
        return $user;
    }

    #[Test]
    public function approveTransitionsSectionToApproved(): void
    {
        $section = $this->makeSection();
        $approver = $this->makeUser();

        $this->auditLogger->expects(self::once())->method('logCustom');
        $this->em->expects(self::atLeastOnce())->method('persist')->with($section);
        $this->em->expects(self::atLeastOnce())->method('flush');
        $this->sectionRepo->method('allSectionsApproved')->willReturn(false);

        $this->service->approve($section, $approver);

        self::assertSame(DocumentSection::STATUS_APPROVED, $section->getStatus());
        self::assertSame($approver, $section->getApprovedByUser());
        self::assertNotNull($section->getApprovedAt());
    }

    #[Test]
    public function approveOnAlreadyApprovedIsIdempotent(): void
    {
        $section = $this->makeSection(status: DocumentSection::STATUS_APPROVED);
        $approver = $this->makeUser();

        $this->auditLogger->expects(self::never())->method('logCustom');
        $this->em->expects(self::never())->method('persist');

        $this->service->approve($section, $approver);

        self::assertSame(DocumentSection::STATUS_APPROVED, $section->getStatus());
    }

    #[Test]
    public function approveOnRejectedSectionThrows(): void
    {
        $section = $this->makeSection(status: DocumentSection::STATUS_REJECTED);
        $approver = $this->makeUser();

        $this->expectException(InvalidArgumentException::class);
        $this->service->approve($section, $approver);
    }

    #[Test]
    public function rejectRequiresRationale(): void
    {
        $section = $this->makeSection();
        $approver = $this->makeUser();

        $this->expectException(InvalidArgumentException::class);
        $this->service->reject($section, $approver, '   '); // whitespace only
    }

    #[Test]
    public function rejectTransitionsSectionToRejected(): void
    {
        $section = $this->makeSection();
        $approver = $this->makeUser();

        // W3 Gap-B: rejection now also emits a `policy_wizard.rejection_notification`
        // audit-log event (notify-target trail). Two logCustom calls total.
        $this->auditLogger->expects(self::atLeastOnce())->method('logCustom');
        $this->em->expects(self::atLeastOnce())->method('persist')->with($section);
        $this->em->expects(self::atLeastOnce())->method('flush');

        $this->service->reject($section, $approver, 'Privacy impact under-assessed: missing data-subject category.');

        self::assertSame(DocumentSection::STATUS_REJECTED, $section->getStatus());
        self::assertSame($approver, $section->getRejectedByUser());
        self::assertNotNull($section->getRejectedAt());
        self::assertNotEmpty($section->getRejectionReason());
        // Approval-side fields cleared so audit trail reflects current state.
        self::assertNull($section->getApprovedAt());
        self::assertNull($section->getApprovedByUser());
    }

    #[Test]
    public function rejectStripsLeadingTrailingWhitespaceFromReason(): void
    {
        $section = $this->makeSection();
        $approver = $this->makeUser();

        $this->service->reject($section, $approver, "   Reason A   \n");

        self::assertSame('Reason A', $section->getRejectionReason());
    }
}
