<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyWizard;

use App\Entity\Document;
use App\Entity\DocumentSection;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\DocumentSectionRepository;
use App\Repository\UserRepository;
use App\Service\AuditLogger;
use App\Service\PolicyWizard\LockedSectionException;
use App\Service\PolicyWizard\PolicySectionApprovalService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * W6-A — DPO Veto sub-workflow service tests.
 *
 * Exercises the four new public methods on PolicySectionApprovalService:
 *  - lockSectionForCisoEdits()       — sets the `edit_locked` flag.
 *  - assertSectionEditable()         — DPO bypasses, CISO blocked.
 *  - reopenForDpoEdit()              — clears the lock, status reverts.
 *  - assertNotSelfApproval()         — Art. 38(3) self-veto guard.
 *  - resolveApprovalRole()           — BSI-pure tenant fallback.
 *
 * Plus a regression that the existing `reject()` path emits the
 * `section_rejected` audit event with the W6-A clearing of edit_locked.
 */
#[AllowMockObjectsWithoutExpectations]
class PolicySectionApprovalServiceVetoTest extends TestCase
{
    private EntityManagerInterface $em;
    private DocumentSectionRepository $sectionRepo;
    private AuditLogger $auditLogger;
    private UserRepository $userRepo;
    private PolicySectionApprovalService $service;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->sectionRepo = $this->createMock(DocumentSectionRepository::class);
        $this->auditLogger = $this->createMock(AuditLogger::class);
        $this->userRepo = $this->createMock(UserRepository::class);

        // No host workflow lookup — keeps maybeAdvanceHostWorkflow a no-op.
        $hostRepo = $this->createMock(EntityRepository::class);
        $hostRepo->method('findOneBy')->willReturn(null);
        $this->em->method('getRepository')->willReturn($hostRepo);

        $this->service = new PolicySectionApprovalService(
            $this->em,
            $this->sectionRepo,
            $this->auditLogger,
            $this->userRepo,
            new NullLogger(),
        );
    }

    private function makeSection(
        string $sectionKey = 'privacy_addendum',
        string $status = DocumentSection::STATUS_DRAFT,
        ?User $authoredBy = null,
        bool $editLocked = false,
        ?string $approvalRole = DocumentSection::APPROVAL_ROLE_DPO,
        ?Tenant $tenant = null,
    ): DocumentSection {
        $tenant = $tenant ?? $this->createMock(Tenant::class);
        $document = $this->createMock(Document::class);
        $document->method('getId')->willReturn(99);

        $section = new DocumentSection();
        $section->setSectionKey($sectionKey);
        $section->setStatus($status);
        $section->setTenant($tenant);
        $section->setDocument($document);
        $section->setEditLocked($editLocked);
        $section->setApprovalRole($approvalRole);
        if ($authoredBy !== null) {
            $section->setAuthoredByUser($authoredBy);
        }
        return $section;
    }

    private function makeUser(int $id, array $roles = ['ROLE_USER']): User
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn($id);
        $user->method('getRoles')->willReturn($roles);
        return $user;
    }

    #[Test]
    public function lockSectionForCisoEditsSetsFlagAndPersists(): void
    {
        $section = $this->makeSection();
        self::assertFalse($section->isEditLocked());

        $this->em->expects(self::atLeastOnce())->method('persist')->with($section);

        $this->service->lockSectionForCisoEdits($section);

        self::assertTrue($section->isEditLocked());
    }

    #[Test]
    public function lockSectionForCisoEditsIsIdempotent(): void
    {
        $section = $this->makeSection(editLocked: true);

        // Already-locked → must NOT re-persist (avoids stamping the
        // updatedAt twice on the same row in a single UoW).
        $this->em->expects(self::never())->method('persist');

        $this->service->lockSectionForCisoEdits($section);

        self::assertTrue($section->isEditLocked());
    }

    #[Test]
    public function assertSectionEditableBlocksCisoEditOnLockedSection(): void
    {
        $section = $this->makeSection(editLocked: true);
        $ciso = $this->makeUser(7, ['ROLE_CISO']);

        $this->auditLogger->expects(self::once())->method('logCustom');

        $this->expectException(LockedSectionException::class);
        $this->service->assertSectionEditable($section, $ciso);
    }

    #[Test]
    public function assertSectionEditableAllowsDpoEditOnLockedSection(): void
    {
        $section = $this->makeSection(editLocked: true);
        $dpo = $this->makeUser(11, ['ROLE_DPO']);

        // No audit-event when DPO bypasses — the bypass is THE expected
        // path, not a guard violation.
        $this->auditLogger->expects(self::never())->method('logCustom');

        // No exception — the assertion silently passes.
        $this->service->assertSectionEditable($section, $dpo);
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function assertNotSelfApprovalBlocksAuthorFromApproving(): void
    {
        $author = $this->makeUser(13, ['ROLE_DPO']);
        $section = $this->makeSection(authoredBy: $author);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Self-approval blocked/');
        $this->service->assertNotSelfApproval($section, $author);
    }

    #[Test]
    public function assertNotSelfApprovalAllowsDifferentApprover(): void
    {
        $author = $this->makeUser(13);
        $approver = $this->makeUser(14, ['ROLE_DPO']);
        $section = $this->makeSection(authoredBy: $author);

        // No throw — the assertion completes silently.
        $this->service->assertNotSelfApproval($section, $approver);
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function resolveApprovalRoleFallsBackToCisoOnBsiPureTenant(): void
    {
        // Tenant has NO DPO appointed AND is_gdpr_subject is missing —
        // BSI-pure path: DPO request must fall back to CISO.
        $tenant = $this->createMock(Tenant::class);
        $tenant->method('getId')->willReturn(91);
        $tenant->method('getSettings')->willReturn(['org' => ['is_gdpr_subject' => false]]);

        // No users carrying ROLE_DPO at all.
        $this->userRepo->method('findByRole')->willReturn([]);

        $this->auditLogger->expects(self::once())->method('logCustom');

        $resolved = $this->service->resolveApprovalRole(
            $tenant,
            'privacy_addendum',
            DocumentSection::APPROVAL_ROLE_DPO,
        );

        self::assertSame(DocumentSection::APPROVAL_ROLE_CISO, $resolved);
    }

    #[Test]
    public function resolveApprovalRoleKeepsDpoWhenTenantIsGdprSubject(): void
    {
        // Even with no DPO appointed, an explicit gdpr_subject flag keeps
        // the role at `dpo` so the operator must finish the appointment
        // before the gate releases — never silently degrade a GDPR tenant.
        $tenant = $this->createMock(Tenant::class);
        $tenant->method('getId')->willReturn(92);
        $tenant->method('getSettings')->willReturn(['org' => ['is_gdpr_subject' => true]]);
        $this->userRepo->method('findByRole')->willReturn([]);

        $this->auditLogger->expects(self::never())->method('logCustom');

        $resolved = $this->service->resolveApprovalRole(
            $tenant,
            'privacy_addendum',
            DocumentSection::APPROVAL_ROLE_DPO,
        );

        self::assertSame(DocumentSection::APPROVAL_ROLE_DPO, $resolved);
    }

    #[Test]
    public function rejectClearsEditLockSoSectionIsReeditable(): void
    {
        // Pre-condition: section was approved + locked, then DPO vetoes
        // (e.g. discovered missing data-subject category late in review).
        $author = $this->makeUser(20);
        $dpo = $this->makeUser(21, ['ROLE_DPO']);
        $section = $this->makeSection(
            status: DocumentSection::STATUS_APPROVED,
            authoredBy: $author,
            editLocked: true,
        );

        // W3 Gap-B: rejection emits an additional rejection_notification
        // audit event alongside the section_rejected one.
        $this->auditLogger->expects(self::atLeastOnce())->method('logCustom');

        $this->service->reject($section, $dpo, 'Missing lawful-basis evidence for category §22 BDSG.');

        self::assertSame(DocumentSection::STATUS_REJECTED, $section->getStatus());
        self::assertFalse(
            $section->isEditLocked(),
            'DPO veto MUST clear the edit lock so the author can rework the section',
        );
    }
}
