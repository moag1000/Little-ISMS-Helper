<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\Entity\Document;
use App\Entity\PolicyTemplate;
use App\EventListener\DocumentApprovalListener;
use App\Service\Document\DocumentEvidenceAttachmentInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\UnitOfWork;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * V3 W2-LB-8 + WS-9 + Phase 1 multi-framework evidence attachment tests.
 *
 * Note: listener uses preUpdate (not postUpdate). The preUpdate method
 * reads hasChangedField / getOldValue / getNewValue directly from
 * PreUpdateEventArgs — tested via arg stubs below.
 */
#[AllowMockObjectsWithoutExpectations]
class DocumentApprovalListenerTest extends TestCase
{
    private MockObject $logger;
    private DocumentApprovalListener $listener;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->listener = new DocumentApprovalListener($this->logger);
    }

    #[Test]
    public function nonApprovedStatusChangeIsNoOp(): void
    {
        $doc = $this->doc('draft');

        $uow = $this->createMock(UnitOfWork::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getUnitOfWork')->willReturn($uow);

        $args = $this->preUpdateArgs($doc, $em, ['status' => ['draft', 'in_review']]);
        $this->listener->preUpdate($doc, $args);

        // nextReviewDate must remain null — no auto-set for non-approved transition.
        $this->assertNull($doc->getNextReviewDate());
    }

    #[Test]
    public function approvedTransitionSetsReviewDate(): void
    {
        $doc = $this->doc('approved');
        $doc->setReviewIntervalMonths(12);

        $uow = $this->createMock(UnitOfWork::class);
        // propertyChanged may be called to register the date change.
        $uow->expects($this->any())->method('propertyChanged');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getUnitOfWork')->willReturn($uow);

        $args = $this->preUpdateArgs($doc, $em, ['status' => ['draft', 'approved']]);
        $this->listener->preUpdate($doc, $args);

        $this->assertNotNull($doc->getNextReviewDate());
        $now = new \DateTimeImmutable('today');
        $diffDays = (int) $now->diff($doc->getNextReviewDate())->format('%a');
        // ~365 days for 12 months.
        $this->assertGreaterThan(330, $diffDays);
        $this->assertLessThan(380, $diffDays);
    }

    #[Test]
    public function noStatusChangeIsNoOp(): void
    {
        $doc = $this->doc('approved');
        $doc->setNextReviewDate(null);

        $uow = $this->createMock(UnitOfWork::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getUnitOfWork')->willReturn($uow);

        // No status field in changeset.
        $args = $this->preUpdateArgs($doc, $em, ['description' => ['old', 'new']]);
        $this->listener->preUpdate($doc, $args);

        $this->assertNull($doc->getNextReviewDate());
    }

    #[Test]
    public function existingNextReviewDateIsPreserved(): void
    {
        $doc = $this->doc('approved');
        $custom = new \DateTimeImmutable('+2 months');
        $doc->setNextReviewDate($custom);

        $uow = $this->createMock(UnitOfWork::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getUnitOfWork')->willReturn($uow);

        $args = $this->preUpdateArgs($doc, $em, ['status' => ['draft', 'approved']]);
        $this->listener->preUpdate($doc, $args);

        $this->assertSame($custom, $doc->getNextReviewDate());
    }

    #[Test]
    public function templateDocumentIsQueuedForEvidenceAttachment(): void
    {
        $template = new PolicyTemplate();
        $doc = $this->doc('approved');
        $doc->setGeneratedFromTemplate($template);

        $attachCalled = false;
        $svc = new class ($attachCalled) implements DocumentEvidenceAttachmentInterface {
            public function __construct(private bool &$called) {}
            public function attachOnApproval(Document $doc): array {
                $this->called = true;
                return ['iso27001_links' => 1, 'requirement_links' => 0, 'skipped' => 0];
            }
        };

        $listener = new DocumentApprovalListener($this->logger, null, null, $svc);

        $uow = $this->createMock(UnitOfWork::class);
        $uow->expects($this->any())->method('propertyChanged');
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getUnitOfWork')->willReturn($uow);

        // preUpdate queues the document.
        $args = $this->preUpdateArgs($doc, $em, ['status' => ['draft', 'approved']]);
        $listener->preUpdate($doc, $args);

        // Simulate postFlush by calling the method with a real PostFlushEventArgs.
        $postFlushArgs = new PostFlushEventArgs($em);
        $listener->postFlush($postFlushArgs);

        $this->assertTrue($attachCalled, 'attachOnApproval was not called in postFlush');
    }

    #[Test]
    public function nonTemplateDocumentIsNotQueuedForEvidenceAttachment(): void
    {
        $doc = $this->doc('approved');
        // No generatedFromTemplate set.

        $attachCalled = false;
        $svc = new class ($attachCalled) implements DocumentEvidenceAttachmentInterface {
            public function __construct(private bool &$called) {}
            public function attachOnApproval(Document $doc): array {
                $this->called = true;
                return ['iso27001_links' => 0, 'requirement_links' => 0, 'skipped' => 0];
            }
        };

        $listener = new DocumentApprovalListener($this->logger, null, null, $svc);

        $uow = $this->createMock(UnitOfWork::class);
        $uow->expects($this->any())->method('propertyChanged');
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getUnitOfWork')->willReturn($uow);

        $args = $this->preUpdateArgs($doc, $em, ['status' => ['draft', 'approved']]);
        $listener->preUpdate($doc, $args);

        $postFlushArgs = new PostFlushEventArgs($em);
        $listener->postFlush($postFlushArgs);

        $this->assertFalse($attachCalled, 'attachOnApproval should not be called for non-template docs');
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function doc(string $status): Document
    {
        $doc = new Document();
        $doc->setStatus($status);
        $doc->setCategory('general');
        return $doc;
    }

    /**
     * Build a PreUpdateEventArgs stub with controlled hasChangedField / getOldValue / getNewValue.
     *
     * @param array<string, array{0: mixed, 1: mixed}> $changeset field => [old, new]
     */
    private function preUpdateArgs(Document $doc, EntityManagerInterface $em, array $changeset): PreUpdateEventArgs
    {
        return new PreUpdateEventArgs($doc, $em, $changeset);
    }
}
