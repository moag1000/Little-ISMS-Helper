<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\Entity\Document;
use App\EventListener\DocumentApprovalListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\UnitOfWork;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * V3 W2-LB-8 + WS-9 — DocumentApprovalListener tests.
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
    public function nonApprovedStatusIsNoOp(): void
    {
        $doc = $this->doc('draft');

        $uow = $this->createMock(UnitOfWork::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('getUnitOfWork')->willReturn($uow);

        $args = new PostUpdateEventArgs($doc, $em);
        $this->listener->postUpdate($doc, $args);
    }

    #[Test]
    public function approvedTransitionSetsReviewDate(): void
    {
        $doc = $this->doc('approved');
        $doc->setReviewIntervalMonths(12);

        $uow = $this->createMock(UnitOfWork::class);
        $uow->method('getEntityChangeSet')->willReturn(['status' => ['draft', 'approved']]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getUnitOfWork')->willReturn($uow);
        $em->expects($this->atLeastOnce())->method('persist');
        $em->expects($this->atLeastOnce())->method('flush');

        $args = new PostUpdateEventArgs($doc, $em);
        $this->listener->postUpdate($doc, $args);

        $this->assertNotNull($doc->getNextReviewDate());
        $now = new \DateTimeImmutable('today');
        $diffDays = (int) $now->diff($doc->getNextReviewDate())->format('%a');
        // Approximately 365 days for 12 months — allow window.
        $this->assertGreaterThan(330, $diffDays);
        $this->assertLessThan(380, $diffDays);
    }

    #[Test]
    public function noStatusChangeIsNoOp(): void
    {
        $doc = $this->doc('approved');
        $doc->setNextReviewDate(null);

        $uow = $this->createMock(UnitOfWork::class);
        $uow->method('getEntityChangeSet')->willReturn(['description' => ['a', 'b']]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getUnitOfWork')->willReturn($uow);
        $em->expects($this->never())->method('persist');
        $em->expects($this->never())->method('flush');

        $args = new PostUpdateEventArgs($doc, $em);
        $this->listener->postUpdate($doc, $args);

        $this->assertNull($doc->getNextReviewDate());
    }

    #[Test]
    public function existingNextReviewDateIsPreserved(): void
    {
        $doc = $this->doc('approved');
        $custom = new \DateTimeImmutable('+2 months');
        $doc->setNextReviewDate($custom);

        $uow = $this->createMock(UnitOfWork::class);
        $uow->method('getEntityChangeSet')->willReturn(['status' => ['draft', 'approved']]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getUnitOfWork')->willReturn($uow);

        $args = new PostUpdateEventArgs($doc, $em);
        $this->listener->postUpdate($doc, $args);

        $this->assertSame($custom, $doc->getNextReviewDate());
    }

    private function doc(string $status): Document
    {
        $doc = new Document();
        $doc->setStatus($status);
        $doc->setCategory('general');
        return $doc;
    }
}
