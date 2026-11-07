<?php

declare(strict_types=1);

namespace App\Tests\Service\Workflow;

use App\Entity\Risk;
use App\Entity\WorkflowInstance;
use App\Enum\WorkflowInstanceStatus;
use App\Repository\WorkflowInstanceRepository;
use App\Service\Workflow\WorkflowStateLookup;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WorkflowStateLookupTest extends TestCase
{
    #[Test]
    public function oneQueryForThePageKeyedByEntityId(): void
    {
        $repo = $this->createMock(WorkflowInstanceRepository::class);
        $repo->expects(self::once())
            ->method('findActiveForEntities')
            ->with(['Risk', Risk::class], [7, 9])
            ->willReturn([
                $this->instance(9, new \DateTimeImmutable('-1 day')),
                $this->instance(9, null),
            ]);

        $states = (new WorkflowStateLookup($repo))->forMany([$this->risk(7), $this->risk(9)]);

        self::assertArrayNotHasKey(7, $states);
        self::assertTrue($states[9]['stuck'], 'newest instance wins, its due date is past');
    }

    #[Test]
    public function emptyPageRunsNoQuery(): void
    {
        $repo = $this->createMock(WorkflowInstanceRepository::class);
        $repo->expects(self::never())->method('findActiveForEntities');

        self::assertSame([], (new WorkflowStateLookup($repo))->forMany([]));
    }

    private function risk(int $id): Risk
    {
        $risk = new Risk();
        (new \ReflectionProperty(Risk::class, 'id'))->setValue($risk, $id);

        return $risk;
    }

    private function instance(int $entityId, ?\DateTimeImmutable $due): WorkflowInstance
    {
        return (new WorkflowInstance())
            ->setEntityType('Risk')
            ->setEntityId($entityId)
            ->setStatus(WorkflowInstanceStatus::Pending)
            ->setDueDate($due);
    }
}
