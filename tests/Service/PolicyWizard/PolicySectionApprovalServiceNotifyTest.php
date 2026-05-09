<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyWizard;

use App\Entity\Document;
use App\Entity\DocumentSection;
use App\Entity\Tenant;
use App\Entity\User;
use App\Entity\WizardRun;
use App\Repository\DocumentSectionRepository;
use App\Service\AuditLogger;
use App\Service\EmailNotificationService;
use App\Service\PolicyWizard\PolicySectionApprovalService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * W3 Gap-B — PolicySectionApprovalService rejection-notification tests.
 *
 * Spec: `docs/plans/policy-wizard/07-phase4-sprint-reconciliation.md`
 * line 232 — "Notify-target on rejection = Document.owner not
 * WizardRun.startedByUser_id (ISB review 'Bulk-approval ergonomics' #2)".
 *
 * The service notifies the Document.owner (modelled here as
 * Document.uploadedBy) and falls back to WizardRun.startedByUser only
 * when no owner is recorded. Every rejection emits the
 * `policy_wizard.rejection_notification` audit-log event regardless of
 * whether an email was actually dispatched.
 */
#[AllowMockObjectsWithoutExpectations]
final class PolicySectionApprovalServiceNotifyTest extends TestCase
{
    private EntityManagerInterface $em;
    private DocumentSectionRepository $sectionRepo;
    private AuditLogger $auditLogger;
    private EmailNotificationService $emailService;
    private PolicySectionApprovalService $service;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->sectionRepo = $this->createMock(DocumentSectionRepository::class);
        $this->auditLogger = $this->createMock(AuditLogger::class);
        $this->emailService = $this->createMock(EmailNotificationService::class);

        $hostRepo = $this->createMock(EntityRepository::class);
        $hostRepo->method('findOneBy')->willReturn(null);
        $this->em->method('getRepository')->willReturn($hostRepo);

        $this->service = new PolicySectionApprovalService(
            $this->em,
            $this->sectionRepo,
            $this->auditLogger,
            null,
            new \Psr\Log\NullLogger(),
            null,
            $this->emailService,
        );
    }

    private function makeUser(int $id, string $email = 'user@example.com'): User
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn($id);
        $user->method('getEmail')->willReturn($email);
        return $user;
    }

    private function makeDocument(?User $uploader, ?WizardRun $run): Document
    {
        $doc = new Document();
        if ($uploader !== null) {
            $doc->setUploadedBy($uploader);
        }
        if ($run !== null) {
            $doc->setGeneratedFromWizardRun($run);
        }
        $reflection = new \ReflectionProperty(Document::class, 'id');
        $reflection->setValue($doc, 501);
        return $doc;
    }

    private function makeSection(Document $document): DocumentSection
    {
        $tenant = $this->createMock(Tenant::class);
        $tenant->method('getId')->willReturn(1);

        $section = new DocumentSection();
        $section->setSectionKey('privacy_addendum');
        $section->setStatus(DocumentSection::STATUS_DPO_SIGN_OFF);
        $section->setTenant($tenant);
        $section->setDocument($document);
        return $section;
    }

    #[Test]
    public function testRejectionNotifiesDocumentOwnerWhenSet(): void
    {
        $owner = $this->makeUser(11, 'owner@example.com');
        $starter = $this->makeUser(22, 'starter@example.com');
        $approver = $this->makeUser(33, 'dpo@example.com');

        $run = new WizardRun();
        $run->setStartedByUser($starter);

        $document = $this->makeDocument($owner, $run);
        $section = $this->makeSection($document);

        // Email service must be called with the OWNER, not the starter.
        $this->emailService->expects(self::once())
            ->method('sendGenericNotification')
            ->with(
                self::callback(static fn ($s): bool => is_string($s) && $s !== ''),
                self::callback(static fn ($s): bool => is_string($s) && $s !== ''),
                self::callback(function (array $context) use ($owner): bool {
                    return ($context['notify_target'] ?? null) === $owner;
                }),
                self::callback(function (array $recipients) use ($owner): bool {
                    return in_array($owner, $recipients, true);
                }),
            );

        $this->service->reject($section, $approver, 'rationale text long enough');

        self::assertSame(DocumentSection::STATUS_REJECTED, $section->getStatus());
    }

    #[Test]
    public function testRejectionFallsBackToWizardRunStarter(): void
    {
        $starter = $this->makeUser(44, 'starter@example.com');
        $approver = $this->makeUser(55, 'dpo@example.com');

        $run = new WizardRun();
        $run->setStartedByUser($starter);

        // Document has NO uploadedBy → fallback path.
        $document = $this->makeDocument(null, $run);
        $section = $this->makeSection($document);

        $this->emailService->expects(self::once())
            ->method('sendGenericNotification')
            ->with(
                self::callback(static fn ($s): bool => is_string($s) && $s !== ''),
                self::callback(static fn ($s): bool => is_string($s) && $s !== ''),
                self::callback(function (array $context) use ($starter): bool {
                    return ($context['notify_target'] ?? null) === $starter;
                }),
                self::callback(function (array $recipients) use ($starter): bool {
                    return in_array($starter, $recipients, true);
                }),
            );

        $this->service->reject($section, $approver, 'rationale text long enough');

        // Bonus: helper directly returns the starter.
        $resolved = $this->service->resolveRejectionNotifyTarget($document, $run);
        self::assertSame($starter, $resolved);
    }

    #[Test]
    public function testRejectionLogsAuditEvent(): void
    {
        $owner = $this->makeUser(11);
        $approver = $this->makeUser(33);
        $document = $this->makeDocument($owner, null);
        $section = $this->makeSection($document);

        $logged = [];
        $this->auditLogger->method('logCustom')->willReturnCallback(
            function (string $action, ?string $entityType, ?int $entityId, ?array $oldValues, ?array $newValues, ?string $description) use (&$logged): void {
                $logged[] = [
                    'action' => $action,
                    'entityType' => $entityType,
                    'entityId' => $entityId,
                    'newValues' => $newValues,
                ];
            },
        );

        $this->service->reject($section, $approver, 'rationale text long enough');

        $rejectionEvents = array_filter(
            $logged,
            static fn (array $entry): bool => $entry['action'] === 'policy_wizard.rejection_notification',
        );
        self::assertCount(1, $rejectionEvents, 'rejection_notification audit event must be emitted');

        $event = array_values($rejectionEvents)[0];
        self::assertSame('DocumentSection', $event['entityType']);
        self::assertSame(11, $event['newValues']['notify_target_id'] ?? null);
        self::assertSame('document_owner', $event['newValues']['notify_source'] ?? null);
    }
}
