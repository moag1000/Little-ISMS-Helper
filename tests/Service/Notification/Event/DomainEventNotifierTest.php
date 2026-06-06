<?php

declare(strict_types=1);

namespace App\Tests\Service\Notification\Event;

use App\Entity\Notification\NotificationRule;
use App\Entity\Tenant;
use App\Repository\Notification\NotificationRuleRepository;
use App\Service\Notification\Event\DomainEventNotifier;
use App\Service\Notification\NotificationDispatcher;
use App\Service\Notification\NotificationRuleEvaluator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The chokepoint: only rules whose conditions EVALUATE true are dispatched, and
 * one failing rule never blocks the others.
 */
#[AllowMockObjectsWithoutExpectations]
final class DomainEventNotifierTest extends TestCase
{
    #[Test]
    public function onlyMatchingRulesAreDispatched(): void
    {
        $matching = new NotificationRule();
        $nonMatching = new NotificationRule();

        $repo = $this->createMock(NotificationRuleRepository::class);
        $repo->method('findActiveByEventType')->willReturn([$matching, $nonMatching]);

        $evaluator = $this->createMock(NotificationRuleEvaluator::class);
        $evaluator->method('evaluate')->willReturnCallback(
            static fn (NotificationRule $r): bool => $r === $matching,
        );

        $dispatcher = $this->createMock(NotificationDispatcher::class);
        $dispatcher->expects(self::once())->method('dispatch')->with($matching, self::anything());

        $notifier = new DomainEventNotifier($repo, $evaluator, $dispatcher, new NullLogger());
        $notifier->fire('incident.severity_high', new Tenant(), ['severity' => 'high']);
    }

    #[Test]
    public function aFailingRuleDoesNotBlockTheRest(): void
    {
        $a = new NotificationRule();
        $b = new NotificationRule();

        $repo = $this->createMock(NotificationRuleRepository::class);
        $repo->method('findActiveByEventType')->willReturn([$a, $b]);

        $evaluator = $this->createMock(NotificationRuleEvaluator::class);
        $evaluator->method('evaluate')->willReturn(true);

        $dispatcher = $this->createMock(NotificationDispatcher::class);
        $dispatcher->expects(self::exactly(2))->method('dispatch')->willReturnCallback(
            static function (NotificationRule $r) use ($a): array {
                if ($r === $a) {
                    throw new \RuntimeException('channel down');
                }
                return [];
            },
        );

        $notifier = new DomainEventNotifier($repo, $evaluator, $dispatcher, new NullLogger());
        // Must not throw — the failure on $a is isolated and $b still dispatches.
        $notifier->fire('incident.created', new Tenant(), []);
    }

    #[Test]
    public function noRulesIsANoOp(): void
    {
        $repo = $this->createMock(NotificationRuleRepository::class);
        $repo->method('findActiveByEventType')->willReturn([]);

        $dispatcher = $this->createMock(NotificationDispatcher::class);
        $dispatcher->expects(self::never())->method('dispatch');

        $notifier = new DomainEventNotifier(
            $repo,
            $this->createMock(NotificationRuleEvaluator::class),
            $dispatcher,
            new NullLogger(),
        );
        $notifier->fire('risk.created', new Tenant(), []);
    }
}
