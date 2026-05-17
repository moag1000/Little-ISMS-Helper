<?php

declare(strict_types=1);

namespace App\Tests\Lifecycle\EventListener;

use App\Entity\Document;
use App\Lifecycle\EventListener\AuditLogListener;
use App\Service\AuditLogger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Workflow\Event\CompletedEvent;
use Symfony\Component\Workflow\Marking;
use Symfony\Component\Workflow\Transition;
use Symfony\Component\Workflow\WorkflowInterface;

class AuditLogListenerTest extends TestCase
{
    public function testLogsStatusChangeAction(): void
    {
        $doc = $this->createStub(Document::class);
        $doc->method('getId')->willReturn(42);

        $auditLogger = $this->createMock(AuditLogger::class);
        $auditLogger->expects($this->once())
            ->method('logCustom')
            ->with(
                $this->equalTo('status_change'),
                $this->equalTo('Document'),
                $this->equalTo(42),
                $this->isNull(),
                $this->callback(fn ($newValues) => $newValues['status'] === 'in_review'
                    && $newValues['transition'] === 'submit_for_review'
                    && $newValues['reason'] === 'first review'
                ),
                $this->stringContains('transitioned via "submit_for_review"'),
            );

        $event = new CompletedEvent(
            $doc,
            new Marking(['in_review' => 1]),
            new Transition('submit_for_review', ['draft'], ['in_review']),
            $this->createStub(WorkflowInterface::class),
            ['reason' => 'first review'],
        );

        (new AuditLogListener($auditLogger))->onCompleted($event);
    }
}
