<?php

declare(strict_types=1);

namespace App\Tests\Lifecycle\EventListener;

use App\Entity\AuditFinding;
use App\Entity\Control;
use App\Entity\CorrectiveAction;
use App\Lifecycle\EventListener\CorrectiveActionEffectiveListener;
use App\Lifecycle\LifecycleTransitionInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Workflow\Event\CompletedEvent;
use Symfony\Component\Workflow\Marking;
use Symfony\Component\Workflow\Transition;
use Symfony\Component\Workflow\WorkflowInterface;

/** ISO 27001 10.2: an effective corrective action closes its finding and re-verifies its controls. */
#[AllowMockObjectsWithoutExpectations]
final class CorrectiveActionEffectiveListenerTest extends TestCase
{
    /** @var list<string> */
    private array $applied = [];

    #[Test]
    public function closesTheFindingWhenEveryActionIsDone(): void
    {
        $finding = (new AuditFinding())->setStatus('in_progress');
        $capa = $this->capa($finding);

        $this->listener()->onCompleted($this->event($capa, 'confirm_effective'));

        self::assertSame(['resolve', 'verify', 'close'], $this->applied);
        self::assertSame('closed', $finding->getStatus());
    }

    #[Test]
    public function leavesTheFindingOpenWhileAnotherActionRuns(): void
    {
        $finding = (new AuditFinding())->setStatus('in_progress');
        $capa = $this->capa($finding);
        $other = (new CorrectiveAction())->setStatus('in_progress');
        $finding->addCorrectiveAction($other);

        $this->listener()->onCompleted($this->event($capa, 'confirm_effective'));

        self::assertSame([], $this->applied);
        self::assertSame('in_progress', $finding->getStatus());
    }

    #[Test]
    public function reverifiesLinkedControlsWithoutTouchingTheirImplementation(): void
    {
        $control = (new Control())
            ->setImplementationStatus('partial')
            ->setNextReviewDate(new \DateTimeImmutable('+1 year'));
        $capa = $this->capa(null);
        $capa->addRelatedControl($control);
        $capa->setVerifiedAt(new \DateTimeImmutable('2026-11-01 10:00'));

        $this->listener()->onCompleted($this->event($capa, 'confirm_effective'));

        self::assertSame('2026-11-01', $control->getLastEffectivenessTest()?->format('Y-m-d'));
        self::assertLessThanOrEqual(new \DateTimeImmutable('+30 days'), $control->getNextReviewDate());
        self::assertSame('partial', $control->getImplementationStatus());
    }

    #[Test]
    public function otherTransitionsAreIgnored(): void
    {
        $finding = (new AuditFinding())->setStatus('in_progress');
        $capa = $this->capa($finding);

        $this->listener()->onCompleted($this->event($capa, 'confirm_ineffective'));

        self::assertSame([], $this->applied);
    }

    private function capa(?AuditFinding $finding): CorrectiveAction
    {
        $capa = (new CorrectiveAction())->setStatus('verified_effective');
        if ($finding !== null) {
            $finding->addCorrectiveAction($capa);
        }

        return $capa;
    }

    private function listener(): CorrectiveActionEffectiveListener
    {
        $targets = ['start' => 'in_progress', 'resolve' => 'resolved', 'verify' => 'verified', 'close' => 'closed'];
        $lifecycle = $this->createMock(LifecycleTransitionInterface::class);
        $lifecycle->method('transition')->willReturnCallback(
            function (object $entity, string $workflow, string $transition) use ($targets): void {
                $this->applied[] = $transition;
                $entity->setStatus($targets[$transition]);
            },
        );

        return new CorrectiveActionEffectiveListener($lifecycle, $this->createMock(EntityManagerInterface::class), new NullLogger());
    }

    private function event(CorrectiveAction $capa, string $transition): CompletedEvent
    {
        return new CompletedEvent(
            $capa,
            new Marking(['verified_effective' => 1]),
            new Transition($transition, ['verified'], ['verified_effective']),
            $this->createMock(WorkflowInterface::class),
            [],
        );
    }
}
