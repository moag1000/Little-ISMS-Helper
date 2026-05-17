<?php

declare(strict_types=1);

namespace App\Tests\Lifecycle\EventListener;

use App\Entity\Document;
use App\Lifecycle\EventListener\AlvaHintInvalidator;
use App\Repository\AlvaHintDismissalRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Workflow\Event\CompletedEvent;
use Symfony\Component\Workflow\Marking;
use Symfony\Component\Workflow\Transition;
use Symfony\Component\Workflow\WorkflowInterface;

class AlvaHintInvalidatorTest extends TestCase
{
    public function testInvalidatesHintsForEntity(): void
    {
        $doc = $this->createStub(Document::class);
        $doc->method('getId')->willReturn(99);

        $repo = $this->createMock(AlvaHintDismissalRepository::class);
        $repo->expects($this->once())
            ->method('invalidateForEntity')
            ->with('Document', 99);

        $event = new CompletedEvent(
            $doc,
            new Marking(['approved' => 1]),
            new Transition('approve', ['in_review'], ['approved']),
            $this->createStub(WorkflowInterface::class),
            [],
        );

        (new AlvaHintInvalidator($repo))->onCompleted($event);
    }

    public function testSkipsSubjectWithoutGetId(): void
    {
        $subject = new \stdClass();

        $repo = $this->createMock(AlvaHintDismissalRepository::class);
        $repo->expects($this->never())->method('invalidateForEntity');

        $event = new CompletedEvent(
            $subject,
            new Marking(['done' => 1]),
            new Transition('finish', ['open'], ['done']),
            $this->createStub(WorkflowInterface::class),
            [],
        );

        (new AlvaHintInvalidator($repo))->onCompleted($event);
    }
}
