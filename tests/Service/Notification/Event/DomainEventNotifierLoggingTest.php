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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Observability contract for DomainEventNotifier: rule-lookup failures emit a
 * warning + early return; per-rule dispatch failures emit a warning but let the
 * remaining rules continue; successful dispatches emit an info log.
 *
 * Resilience behavior (dispatch-continues-after-failure) is also verified here
 * because the logging assertions double as a precise signal for the two failure
 * modes (lookup vs per-rule). Basic dispatch behaviour (only-matching-rules,
 * no-rules-no-op) is covered by DomainEventNotifierTest.
 *
 * The control.overdue cron path is exercised here to document that the
 * time-driven event type is routed through DomainEventNotifier.fire() from
 * DispatchOverdueControlNotificationsCommand, NOT from the Doctrine subscriber.
 */
#[AllowMockObjectsWithoutExpectations]
final class DomainEventNotifierLoggingTest extends TestCase
{
    private MockObject&NotificationRuleRepository $ruleRepo;
    private MockObject&NotificationRuleEvaluator $evaluator;
    private MockObject&NotificationDispatcher $dispatcher;
    private MockObject&LoggerInterface $logger;
    private DomainEventNotifier $notifier;

    protected function setUp(): void
    {
        $this->ruleRepo   = $this->createMock(NotificationRuleRepository::class);
        $this->evaluator  = $this->createMock(NotificationRuleEvaluator::class);
        $this->dispatcher = $this->createMock(NotificationDispatcher::class);
        $this->logger     = $this->createMock(LoggerInterface::class);

        $this->notifier = new DomainEventNotifier(
            $this->ruleRepo,
            $this->evaluator,
            $this->dispatcher,
            $this->logger,
        );
    }

    #[Test]
    public function logsInfoOnSuccessfulDispatch(): void
    {
        $rule = $this->rule(3);

        $this->ruleRepo->method('findActiveByEventType')->willReturn([$rule]);
        $this->evaluator->method('evaluate')->willReturn(true);
        $this->dispatcher->method('dispatch')->willReturn([]);

        $this->logger->expects(self::once())->method('info');

        $this->notifier->fire('data_breach.created', new Tenant(), []);
    }

    #[Test]
    public function logsWarningAndReturnsEarlyWhenRuleLookupFails(): void
    {
        $this->ruleRepo
            ->method('findActiveByEventType')
            ->willThrowException(new \RuntimeException('db connection lost'));

        $this->logger->expects(self::once())->method('warning');
        $this->dispatcher->expects(self::never())->method('dispatch');

        // Must not throw — graceful degradation.
        $this->notifier->fire('audit.finding_created', new Tenant(), []);
        self::assertTrue(true); // reached = no exception
    }

    #[Test]
    public function logsWarningPerFailedRuleAndContinuesToDispatchOthers(): void
    {
        $ruleA = $this->rule(10);
        $ruleB = $this->rule(11);

        // Use an inline logger so we can count levels precisely without PHPUnit
        // expectation ordering constraints.
        $warnMessages = [];
        $infoMessages = [];
        $capturingLogger = new class($warnMessages, $infoMessages) extends \Psr\Log\AbstractLogger {
            /** @param string[] $warns @param string[] $infos */
            public function __construct(private array &$warns, private array &$infos) {}
            public function log(mixed $level, string|\Stringable $message, array $context = []): void
            {
                if ($level === 'warning') {
                    $this->warns[] = (string) $message;
                } elseif ($level === 'info') {
                    $this->infos[] = (string) $message;
                }
            }
        };

        $ruleRepo = $this->createMock(NotificationRuleRepository::class);
        $ruleRepo->method('findActiveByEventType')->willReturn([$ruleA, $ruleB]);

        $evaluator = $this->createMock(NotificationRuleEvaluator::class);
        $evaluator->method('evaluate')->willReturn(true);

        $dispatchCount = 0;
        $dispatcher = $this->createMock(NotificationDispatcher::class);
        $dispatcher
            ->method('dispatch')
            ->willReturnCallback(static function (NotificationRule $r) use ($ruleA, &$dispatchCount): array {
                ++$dispatchCount;
                if ($r === $ruleA) {
                    throw new \RuntimeException('channel delivery failed');
                }
                return []; // ruleB succeeds
            });

        $notifier = new DomainEventNotifier($ruleRepo, $evaluator, $dispatcher, $capturingLogger);
        $notifier->fire('document.approval_required', new Tenant(), []);

        // ruleA failed → exactly one warning; ruleB still dispatched.
        self::assertCount(1, $warnMessages, 'Exactly one warning for the one failed rule');
        self::assertStringContainsString('dispatch failed', $warnMessages[0]);
        self::assertSame(2, $dispatchCount, 'Both rules must be attempted despite the first one failing');
    }

    /**
     * control.overdue is time-driven and has no Doctrine change-set. The cron
     * command (DispatchOverdueControlNotificationsCommand) calls
     * DomainEventNotifier::fire() directly. This test documents that the plumbing
     * is wired for that path.
     */
    #[Test]
    public function controlOverdueCanBeDispatchedDirectlyThroughNotifier(): void
    {
        $tenant = new Tenant();
        $rule   = $this->rule(99);

        $this->ruleRepo
            ->expects(self::once())
            ->method('findActiveByEventType')
            ->with('control.overdue', $tenant)
            ->willReturn([$rule]);

        $this->evaluator->method('evaluate')->willReturn(true);
        $this->dispatcher->expects(self::once())->method('dispatch')->with($rule, self::anything())->willReturn([]);

        $this->notifier->fire('control.overdue', $tenant, ['id' => 42, 'status' => 'partially_implemented']);
    }

    private function rule(int $id): NotificationRule
    {
        $rule = $this->createMock(NotificationRule::class);
        $rule->method('getId')->willReturn($id);
        $rule->method('getName')->willReturn('Test Rule ' . $id);
        return $rule;
    }
}
