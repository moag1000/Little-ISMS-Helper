<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyWizard;

use App\Entity\Document;
use App\Entity\DocumentSection;
use App\Entity\PolicyTemplate;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\DocumentSectionRepository;
use App\Service\AuditLogger;
use App\Service\PolicyWizard\PolicySectionApprovalService;
use App\Service\PolicyWizard\TopicApproverRoleResolver;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see PolicySectionApprovalService::assertApproverRoleMatch()}
 * — Task #126.
 *
 * Verifies the three audit-events emitted by the role-match guard:
 *  - policy_wizard.approver_role_match            (strict_match)
 *  - policy_wizard.approver_role_weak_match_warning (weak_match)
 *  - policy_wizard.approver_role_mismatch_warning (mismatch)
 *
 * The guard is non-blocking by design — Persona-walkthrough Risk-Owner-
 * Business + Auditor-External require the audit-trail to answer "warum
 * DIESER Approver fuer DIESES Topic", but they do NOT require a runtime
 * block. The wizard-starter may still overrule.
 */
#[AllowMockObjectsWithoutExpectations]
final class PolicySectionApprovalServiceRoleMatchTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private DocumentSectionRepository&MockObject $sectionRepo;
    private AuditLogger&MockObject $auditLogger;
    private PolicySectionApprovalService $service;
    private TopicApproverRoleResolver $resolver;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->sectionRepo = $this->createMock(DocumentSectionRepository::class);
        $this->auditLogger = $this->createMock(AuditLogger::class);

        $hostRepo = $this->createMock(EntityRepository::class);
        $hostRepo->method('findOneBy')->willReturn(null);
        $this->em->method('getRepository')->willReturn($hostRepo);
        $this->sectionRepo->method('allSectionsApproved')->willReturn(false);

        $this->resolver = new TopicApproverRoleResolver();

        $this->service = new PolicySectionApprovalService(
            entityManager: $this->em,
            sectionRepository: $this->sectionRepo,
            auditLogger: $this->auditLogger,
            topicApproverRoleResolver: $this->resolver,
        );
    }

    private function makeSectionWithTopic(string $topic): DocumentSection
    {
        $tenant = $this->createMock(Tenant::class);
        $tenant->method('getId')->willReturn(1);

        $template = $this->createMock(PolicyTemplate::class);
        $template->method('getTopic')->willReturn($topic);

        $document = $this->createMock(Document::class);
        $document->method('getId')->willReturn(99);
        $document->method('getGeneratedFromTemplate')->willReturn($template);
        $document->method('getTenant')->willReturn($tenant);

        $section = new DocumentSection();
        $section->setSectionKey('main');
        $section->setStatus(DocumentSection::STATUS_DRAFT);
        $section->setTenant($tenant);
        $section->setDocument($document);
        return $section;
    }

    private function makeUser(int $id, array $roles): User
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn($id);
        $user->method('getRoles')->willReturn($roles);
        return $user;
    }

    #[Test]
    public function strictMatchEmitsRoleMatchEvent(): void
    {
        // CISO approves Cryptography Policy → fachlich correct.
        $section = $this->makeSectionWithTopic('cryptography');
        $approver = $this->makeUser(7, ['ROLE_USER', 'ROLE_CISO']);

        $sawRoleMatch = false;
        $this->auditLogger->method('logCustom')->willReturnCallback(
            function (string $action) use (&$sawRoleMatch) {
                if ($action === 'policy_wizard.approver_role_match') {
                    $sawRoleMatch = true;
                }
            }
        );

        $result = $this->service->assertApproverRoleMatch($section, $approver);

        self::assertNotNull($result);
        self::assertTrue($result->isStrictMatch());
        self::assertTrue($sawRoleMatch, 'policy_wizard.approver_role_match was not emitted');
    }

    #[Test]
    public function mismatchEmitsMismatchWarningEvent(): void
    {
        // Persona-walkthrough canonical: Risk-Owner-Business approves
        // Cryptography Policy. Neither topic-recommended nor universal-
        // weak. Audit-event policy_wizard.approver_role_mismatch_warning
        // MUST land in the trail. The approval is NOT blocked.
        $section = $this->makeSectionWithTopic('cryptography');
        $riskOwner = $this->makeUser(99, ['ROLE_USER', 'ROLE_MANAGER']);

        $sawMismatch = false;
        $payload = null;
        $this->auditLogger->method('logCustom')->willReturnCallback(
            function (string $action, ?string $entityType, ?int $entityId, ?array $oldValues, ?array $newValues) use (&$sawMismatch, &$payload) {
                if ($action === 'policy_wizard.approver_role_mismatch_warning') {
                    $sawMismatch = true;
                    $payload = $newValues;
                }
            }
        );

        $result = $this->service->assertApproverRoleMatch($section, $riskOwner);

        self::assertNotNull($result);
        self::assertTrue($result->isMismatch());
        self::assertTrue($sawMismatch, 'policy_wizard.approver_role_mismatch_warning was not emitted');
        self::assertIsArray($payload);
        self::assertSame('cryptography', $payload['topic_key']);
        self::assertSame(99, $payload['approver_user_id']);
        self::assertContains('ROLE_CISO', $payload['recommended_roles']);
        self::assertEmpty($payload['matched_roles']);
    }

    #[Test]
    public function weakMatchEmitsWeakMatchWarningEvent(): void
    {
        // Plain ROLE_ADMIN approves Cryptography → broad-authority but
        // not fachlich-specialist. The wizard-starter is permitted but
        // the audit-trail flags the use of broad-authority approval.
        $section = $this->makeSectionWithTopic('cryptography');
        $admin = $this->makeUser(7, ['ROLE_USER', 'ROLE_ADMIN']);

        $sawWeak = false;
        $this->auditLogger->method('logCustom')->willReturnCallback(
            function (string $action) use (&$sawWeak) {
                if ($action === 'policy_wizard.approver_role_weak_match_warning') {
                    $sawWeak = true;
                }
            }
        );

        $result = $this->service->assertApproverRoleMatch($section, $admin);

        self::assertNotNull($result);
        self::assertTrue($result->isWeakMatch());
        self::assertTrue($sawWeak, 'policy_wizard.approver_role_weak_match_warning was not emitted');
    }

    #[Test]
    public function nonBlockingMismatchAllowsApproveToProceed(): void
    {
        // Wire the section author so self-approval guard does not fire
        // (different user-ids).
        $section = $this->makeSectionWithTopic('cryptography');
        $author = $this->makeUser(1, ['ROLE_USER']);
        $section->setAuthoredByUser($author);

        $riskOwner = $this->makeUser(99, ['ROLE_USER', 'ROLE_MANAGER']);

        // Approve through the full path — the mismatch event fires but
        // the approval proceeds and the section status flips.
        $this->service->approve($section, $riskOwner);

        self::assertSame(DocumentSection::STATUS_APPROVED, $section->getStatus());
        self::assertSame($riskOwner, $section->getApprovedByUser());
    }

    #[Test]
    public function noResolverWiredYieldsNullAndNoEvent(): void
    {
        // Service constructed WITHOUT the resolver — defensive degrade
        // to "no role-match info, no event". The approval still works
        // through the regular path (legacy DI graph compat).
        $service = new PolicySectionApprovalService(
            entityManager: $this->em,
            sectionRepository: $this->sectionRepo,
            auditLogger: $this->auditLogger,
        );

        $section = $this->makeSectionWithTopic('cryptography');
        $approver = $this->makeUser(7, ['ROLE_USER', 'ROLE_CISO']);

        $result = $service->assertApproverRoleMatch($section, $approver);
        self::assertNull($result);
    }

    #[Test]
    public function topicResolutionFallsBackToSectionKeyWhenTemplateMissing(): void
    {
        // Section without a host PolicyTemplate (test fixture / ad-hoc
        // render). Topic resolution falls back to the section-key, the
        // resolver's unknown-topic fallback fires (DEFAULT recommendation
        // = ROLE_CISO + ROLE_TOP_MGMT), and the event still lands.
        $tenant = $this->createMock(Tenant::class);
        $tenant->method('getId')->willReturn(1);

        $document = $this->createMock(Document::class);
        $document->method('getId')->willReturn(99);
        $document->method('getGeneratedFromTemplate')->willReturn(null);
        $document->method('getTenant')->willReturn($tenant);

        $section = new DocumentSection();
        $section->setSectionKey('arbitrary_section_key');
        $section->setStatus(DocumentSection::STATUS_DRAFT);
        $section->setTenant($tenant);
        $section->setDocument($document);

        $approver = $this->makeUser(7, ['ROLE_USER', 'ROLE_CISO']);

        $this->auditLogger->expects(self::atLeastOnce())->method('logCustom');

        $result = $this->service->assertApproverRoleMatch($section, $approver);

        self::assertNotNull($result);
        // CISO matches the default fallback → strict.
        self::assertTrue($result->isStrictMatch());
    }
}
