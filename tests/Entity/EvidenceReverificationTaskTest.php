<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Control;
use App\Entity\ComplianceRequirementFulfillment;
use App\Entity\DocumentVersion;
use App\Entity\EvidenceReverificationTask;
use App\Entity\Tenant;
use App\Entity\User;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * F4 — EvidenceReverificationTask entity unit tests.
 */
class EvidenceReverificationTaskTest extends TestCase
{
    #[Test]
    public function testDefaultValues(): void
    {
        $task = new EvidenceReverificationTask();

        self::assertSame(EvidenceReverificationTask::STATUS_PENDING, $task->getStatus());
        self::assertNull($task->getDueDate());
        self::assertNull($task->getCompletedAt());
        self::assertNull($task->getNotes());
        self::assertNull($task->getControl());
        self::assertNull($task->getComplianceFulfillment());
        self::assertNull($task->getAssignedTo());
        self::assertInstanceOf(DateTimeImmutable::class, $task->getCreatedAt());
        self::assertTrue($task->isOpen());
        self::assertFalse($task->isOverdue());
    }

    #[Test]
    public function testValidStatusTransitions(): void
    {
        $task = new EvidenceReverificationTask();

        foreach (EvidenceReverificationTask::VALID_STATUSES as $status) {
            $task->setStatus($status);
            self::assertSame($status, $task->getStatus());
        }
    }

    #[Test]
    public function testInvalidStatusThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $task = new EvidenceReverificationTask();
        $task->setStatus('invalid_status');
    }

    #[Test]
    public function testIsOpenForPendingAndInProgress(): void
    {
        $task = new EvidenceReverificationTask();

        $task->setStatus(EvidenceReverificationTask::STATUS_PENDING);
        self::assertTrue($task->isOpen());

        $task->setStatus(EvidenceReverificationTask::STATUS_IN_PROGRESS);
        self::assertTrue($task->isOpen());

        $task->setStatus(EvidenceReverificationTask::STATUS_COMPLETED);
        self::assertFalse($task->isOpen());

        $task->setStatus(EvidenceReverificationTask::STATUS_SKIPPED);
        self::assertFalse($task->isOpen());
    }

    #[Test]
    public function testIsOverdueWhenDueDateInPast(): void
    {
        $task = new EvidenceReverificationTask();
        $task->setStatus(EvidenceReverificationTask::STATUS_PENDING);
        $task->setDueDate(new DateTimeImmutable('-1 day'));

        self::assertTrue($task->isOverdue());
    }

    #[Test]
    public function testIsNotOverdueWhenCompleted(): void
    {
        $task = new EvidenceReverificationTask();
        $task->setStatus(EvidenceReverificationTask::STATUS_COMPLETED);
        $task->setDueDate(new DateTimeImmutable('-1 day'));

        self::assertFalse($task->isOverdue(), 'Completed task should never be overdue.');
    }

    #[Test]
    public function testIsNotOverdueWhenDueDateNull(): void
    {
        $task = new EvidenceReverificationTask();
        $task->setStatus(EvidenceReverificationTask::STATUS_PENDING);
        $task->setDueDate(null);

        self::assertFalse($task->isOverdue(), 'Task without due date should not be overdue.');
    }

    #[Test]
    public function testSetterChaining(): void
    {
        $task = new EvidenceReverificationTask();
        $tenant = new Tenant();
        $docVersion = new DocumentVersion();
        $control = new Control();
        $user = new User();
        $dueDate = new DateTimeImmutable('+14 days');

        $result = $task
            ->setTenant($tenant)
            ->setDocumentVersion($docVersion)
            ->setControl($control)
            ->setAssignedTo($user)
            ->setDueDate($dueDate)
            ->setNotes('Test notes');

        self::assertSame($task, $result);
        self::assertSame($tenant, $task->getTenant());
        self::assertSame($docVersion, $task->getDocumentVersion());
        self::assertSame($control, $task->getControl());
        self::assertSame($user, $task->getAssignedTo());
        self::assertSame($dueDate, $task->getDueDate());
        self::assertSame('Test notes', $task->getNotes());
    }
}
