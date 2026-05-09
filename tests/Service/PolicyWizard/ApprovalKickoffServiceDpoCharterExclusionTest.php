<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyWizard;

use App\Entity\Document;
use App\Entity\PolicyTemplate;
use App\Repository\WorkflowRepository;
use App\Service\AuditLogger;
use App\Service\PolicyWizard\ApprovalKickoffService;
use App\Service\PolicyWizard\DpoCharterBulkApprovalException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * W6 Gap-E — DPO Charter excluded from bulk-approval (code-level guard).
 *
 * Spec: `docs/plans/policy-wizard/07-phase4-sprint-reconciliation.md`
 * line 285 (Auditor "Open Q" #1 lines 291-293).
 *
 * GDPR Art. 38(3) requires the DPO appointment to be approved standalone
 * so the independence sign-off is positively documented. The same rule
 * extends to the Privacy Policy as the top-level data-protection
 * commitment. ApprovalKickoffService::assertNotDpoCharterInBulk() is the
 * code-level guard called BEFORE adding a Document to a bulk batch.
 */
#[AllowMockObjectsWithoutExpectations]
final class ApprovalKickoffServiceDpoCharterExclusionTest extends TestCase
{
    private function makeService(): ApprovalKickoffService
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $workflowRepo = $this->createMock(WorkflowRepository::class);
        $auditLogger = $this->createMock(AuditLogger::class);
        return new ApprovalKickoffService(
            $em,
            $workflowRepo,
            $auditLogger,
        );
    }

    private function makeDocument(int $id, ?string $topic): Document
    {
        $doc = new Document();
        if ($topic !== null) {
            $template = new PolicyTemplate();
            $template->setStandard('iso27001');
            $template->setKey('test-template');
            $template->setDocumentType('policy');
            $template->setTitleTranslationKey('test.title');
            $template->setBodyTranslationKey('test.body');
            $template->setTopic($topic);
            $doc->setGeneratedFromTemplate($template);
        }
        $reflection = new \ReflectionProperty(Document::class, 'id');
        $reflection->setValue($doc, $id);
        return $doc;
    }

    #[Test]
    public function testDpoCharterRejectedFromBulk(): void
    {
        $service = $this->makeService();
        $document = $this->makeDocument(1, 'dpo_charter');

        $this->expectException(DpoCharterBulkApprovalException::class);
        $service->assertNotDpoCharterInBulk($document);
    }

    #[Test]
    public function testPrivacyPolicyRejectedFromBulk(): void
    {
        $service = $this->makeService();
        $document = $this->makeDocument(2, 'privacy_policy');

        $this->expectException(DpoCharterBulkApprovalException::class);
        $service->assertNotDpoCharterInBulk($document);
    }

    #[Test]
    public function testOtherDocsAcceptedInBulk(): void
    {
        $service = $this->makeService();

        // Plain ISO topic: ok.
        $service->assertNotDpoCharterInBulk(
            $this->makeDocument(3, 'acceptable_use'),
        );

        // RoPA / DPIA / DSR are GDPR but operational — bulk-able.
        $service->assertNotDpoCharterInBulk(
            $this->makeDocument(4, 'ropa_methodology'),
        );
        $service->assertNotDpoCharterInBulk(
            $this->makeDocument(5, 'dpia_methodology'),
        );

        // No template at all — pass through (cannot be a charter).
        $service->assertNotDpoCharterInBulk(
            $this->makeDocument(6, null),
        );

        // No exception → all four passed.
        self::assertTrue(true);
    }
}
