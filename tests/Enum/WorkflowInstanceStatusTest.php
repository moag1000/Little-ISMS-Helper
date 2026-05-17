<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Entity\WorkflowInstance;
use App\Enum\WorkflowInstanceStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WorkflowInstanceStatusTest extends TestCase
{
    #[Test]
    public function allFiveStagesAreCovered(): void
    {
        self::assertSame('pending', WorkflowInstanceStatus::Pending->value);
        self::assertSame('in_progress', WorkflowInstanceStatus::InProgress->value);
        self::assertSame('approved', WorkflowInstanceStatus::Approved->value);
        self::assertSame('rejected', WorkflowInstanceStatus::Rejected->value);
        self::assertSame('cancelled', WorkflowInstanceStatus::Cancelled->value);
    }

    #[Test]
    public function labelReturnsTranslationKey(): void
    {
        self::assertSame('workflow_instance.status.pending', WorkflowInstanceStatus::Pending->label());
        self::assertSame('workflow_instance.status.approved', WorkflowInstanceStatus::Approved->label());
    }

    #[Test]
    public function pillVariantMapsToAuroraTones(): void
    {
        self::assertSame('neutral', WorkflowInstanceStatus::Pending->pillVariant());
        self::assertSame('info', WorkflowInstanceStatus::InProgress->pillVariant());
        self::assertSame('success', WorkflowInstanceStatus::Approved->pillVariant());
        self::assertSame('danger', WorkflowInstanceStatus::Rejected->pillVariant());
        self::assertSame('neutral', WorkflowInstanceStatus::Cancelled->pillVariant());
    }

    #[Test]
    public function workflowInstanceSetStatusAcceptsEnumAndString(): void
    {
        $instance = new WorkflowInstance();

        $instance->setStatus(WorkflowInstanceStatus::Approved);
        self::assertSame('approved', $instance->getStatus());
        self::assertSame(WorkflowInstanceStatus::Approved, $instance->getStatusEnum());

        $instance->setStatus('in_progress');
        self::assertSame('in_progress', $instance->getStatus());
        self::assertSame(WorkflowInstanceStatus::InProgress, $instance->getStatusEnum());
    }
}
