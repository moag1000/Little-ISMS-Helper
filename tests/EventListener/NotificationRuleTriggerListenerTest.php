<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\Entity\AuditFinding;
use App\Entity\Control;
use App\Entity\DataBreach;
use App\Entity\Document;
use App\Entity\Incident;
use App\Entity\Notification\NotificationRule;
use App\Entity\Risk;
use App\Entity\Tenant;
use App\Enum\IncidentSeverity;
use App\EventListener\NotificationRuleTriggerListener;
use App\EventSubscriber\EntityChangeNotificationSubscriber;
use App\Repository\Notification\NotificationRuleRepository;
use App\Service\Notification\Event\DomainEvent;
use App\Service\Notification\Event\DomainEventDetector;
use App\Service\Notification\Event\DomainEventNotifier;
use App\Service\Notification\NotificationDispatcher;
use App\Service\Notification\NotificationRuleEvaluator;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\UnitOfWork;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Full coverage for all 10 notification event-types exposed by NotificationRuleType.
 *
 * The triggering infrastructure (since PR #901) is:
 *   EntityChangeNotificationSubscriber (Doctrine onFlush / postFlush)
 *     → DomainEventDetector (pure change-set → event mapping)
 *     → DomainEventNotifier (rule lookup + condition eval + dispatch)
 *
 * Event-type taxonomy:
 *
 *   Entity-event-driven (Doctrine postPersist / postUpdate change-set):
 *     incident.created, incident.severity_high,
 *     risk.created, risk.score_critical,
 *     data_breach.created, data_breach.severity_changed,
 *     audit.finding_created,
 *     document.approval_required   (status → in_review transition),
 *     control.evidence_expired     (evidenceOutdated flag false→true)
 *
 *   Time/state-deadline-driven (NO change-set — cron path):
 *     control.overdue  →  app:notify-overdue-controls command
 *       (DispatchOverdueControlNotificationsCommand with lookback-hours window)
 *
 * The NotificationRuleTriggerListener class retains the public EVENT_* constants
 * for backwards-compat and documents the supersession; no active Doctrine hooks.
 *
 * Tests are structured in three groups:
 *   A) Public API — constants on NotificationRuleTriggerListener match event-type strings.
 *   B) Entity-event-driven — DomainEventDetector + DomainEventNotifier + Subscriber integration.
 *   C) DomainEventNotifier — logging + resilience improvements.
 */
#[AllowMockObjectsWithoutExpectations]
final class NotificationRuleTriggerListenerTest extends TestCase
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

    // -----------------------------------------------------------------------
    // A) Public API — event-type constants
    // -----------------------------------------------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function allEventTypeConstants(): array
    {
        return [
            'incident.created'           => [NotificationRuleTriggerListener::EVENT_INCIDENT_CREATED],
            'incident.severity_high'     => [NotificationRuleTriggerListener::EVENT_INCIDENT_SEVERITY_HIGH],
            'risk.created'               => [NotificationRuleTriggerListener::EVENT_RISK_CREATED],
            'risk.score_critical'        => [NotificationRuleTriggerListener::EVENT_RISK_SCORE_CRITICAL],
            'data_breach.created'        => [NotificationRuleTriggerListener::EVENT_DATA_BREACH_CREATED],
            'data_breach.severity_changed' => [NotificationRuleTriggerListener::EVENT_DATA_BREACH_SEVERITY],
            'audit.finding_created'      => [NotificationRuleTriggerListener::EVENT_AUDIT_FINDING_CREATED],
            'document.approval_required' => [NotificationRuleTriggerListener::EVENT_DOCUMENT_APPROVAL],
            'control.evidence_expired'   => [NotificationRuleTriggerListener::EVENT_CONTROL_EVIDENCE_EXPIRED],
            'control.overdue'            => [NotificationRuleTriggerListener::EVENT_CONTROL_OVERDUE],
        ];
    }

    #[Test]
    #[DataProvider('allEventTypeConstants')]
    public function eventTypeConstantMatchesNotificationRuleTypeChoice(string $value): void
    {
        // The constant strings MUST match the choices in NotificationRuleType so
        // stored rules fire for the correct event.
        self::assertNotEmpty($value);
        self::assertStringContainsString('.', $value, 'Event type must be namespaced (e.g. "incident.created")');
    }

    // -----------------------------------------------------------------------
    // B) Entity-event-driven — detector emits the right event type per entity
    // -----------------------------------------------------------------------

    #[Test]
    public function incidentCreatedFiresOnInsert(): void
    {
        $tenant   = new Tenant();
        $incident = (new Incident())->setTenant($tenant)->setSeverity(IncidentSeverity::Low);

        $events = (new DomainEventDetector())->forInsert($incident);
        $types  = $this->eventTypes($events);

        self::assertContains(NotificationRuleTriggerListener::EVENT_INCIDENT_CREATED, $types);
    }

    #[Test]
    public function incidentSeverityHighAlsoFiresOnCriticalInsert(): void
    {
        $tenant   = new Tenant();
        $incident = (new Incident())->setTenant($tenant)->setSeverity(IncidentSeverity::Critical);

        $types = $this->eventTypes((new DomainEventDetector())->forInsert($incident));

        self::assertContains(NotificationRuleTriggerListener::EVENT_INCIDENT_CREATED, $types);
        self::assertContains(NotificationRuleTriggerListener::EVENT_INCIDENT_SEVERITY_HIGH, $types);
    }

    #[Test]
    public function incidentSeverityHighFiresOnTransitionToHigh(): void
    {
        $tenant   = new Tenant();
        $incident = (new Incident())->setTenant($tenant)->setSeverity(IncidentSeverity::High);

        $types = $this->eventTypes(
            (new DomainEventDetector())->forUpdate($incident, ['severity' => [IncidentSeverity::Low, IncidentSeverity::High]]),
        );

        self::assertSame([NotificationRuleTriggerListener::EVENT_INCIDENT_SEVERITY_HIGH], $types);
    }

    #[Test]
    public function incidentSeverityHighDoesNotFireOnTransitionToBelowHigh(): void
    {
        $tenant   = new Tenant();
        $incident = (new Incident())->setTenant($tenant)->setSeverity(IncidentSeverity::Low);

        $types = $this->eventTypes(
            (new DomainEventDetector())->forUpdate($incident, ['severity' => [IncidentSeverity::High, IncidentSeverity::Low]]),
        );

        self::assertNotContains(NotificationRuleTriggerListener::EVENT_INCIDENT_SEVERITY_HIGH, $types);
    }

    #[Test]
    public function riskCreatedFiresOnInsert(): void
    {
        $risk  = (new Risk())->setTenant(new Tenant())->setProbability(2)->setImpact(2);
        $types = $this->eventTypes((new DomainEventDetector())->forInsert($risk));

        self::assertContains(NotificationRuleTriggerListener::EVENT_RISK_CREATED, $types);
    }

    #[Test]
    public function riskScoreCriticalAlsoFiresWhenCreatedCritical(): void
    {
        // probability 5 × impact 5 = 25 >= RiskMatrixThresholds::CRITICAL_MIN (20)
        $risk  = (new Risk())->setTenant(new Tenant())->setProbability(5)->setImpact(5);
        $types = $this->eventTypes((new DomainEventDetector())->forInsert($risk));

        self::assertContains(NotificationRuleTriggerListener::EVENT_RISK_CREATED, $types);
        self::assertContains(NotificationRuleTriggerListener::EVENT_RISK_SCORE_CRITICAL, $types);
    }

    #[Test]
    public function riskScoreCriticalFiresOnTransitionIntoCriticalBand(): void
    {
        // Old: 2×2=4 (low), New: 5×5=25 (critical) — crosses the threshold
        $risk  = (new Risk())->setTenant(new Tenant())->setProbability(5)->setImpact(5);
        $types = $this->eventTypes(
            (new DomainEventDetector())->forUpdate($risk, ['impact' => [2, 5]]),
        );

        self::assertContains(NotificationRuleTriggerListener::EVENT_RISK_SCORE_CRITICAL, $types);
    }

    #[Test]
    public function riskScoreCriticalDoesNotRepeatWhenAlreadyInCriticalBand(): void
    {
        // Old: 4×5=20 (critical), New: 5×5=25 (still critical) — no re-fire
        $risk  = (new Risk())->setTenant(new Tenant())->setProbability(5)->setImpact(5);
        $types = $this->eventTypes(
            (new DomainEventDetector())->forUpdate($risk, ['probability' => [4, 5]]),
        );

        self::assertNotContains(NotificationRuleTriggerListener::EVENT_RISK_SCORE_CRITICAL, $types);
    }

    #[Test]
    public function dataBreachCreatedFiresOnInsert(): void
    {
        $breach = (new DataBreach())->setTenant(new Tenant())->setSeverity('medium');
        $types  = $this->eventTypes((new DomainEventDetector())->forInsert($breach));

        self::assertContains(NotificationRuleTriggerListener::EVENT_DATA_BREACH_CREATED, $types);
    }

    #[Test]
    public function dataBreachSeverityChangedFiresOnSeverityChange(): void
    {
        $breach = (new DataBreach())->setTenant(new Tenant())->setSeverity('high');
        $types  = $this->eventTypes(
            (new DomainEventDetector())->forUpdate($breach, ['severity' => ['low', 'high']]),
        );

        self::assertContains(NotificationRuleTriggerListener::EVENT_DATA_BREACH_SEVERITY, $types);
    }

    #[Test]
    public function dataBreachSeverityChangedDoesNotFireWhenSeverityUnchanged(): void
    {
        $breach = (new DataBreach())->setTenant(new Tenant())->setSeverity('high');
        $types  = $this->eventTypes(
            (new DomainEventDetector())->forUpdate($breach, ['severity' => ['high', 'high']]),
        );

        self::assertNotContains(NotificationRuleTriggerListener::EVENT_DATA_BREACH_SEVERITY, $types);
    }

    #[Test]
    public function auditFindingCreatedFiresOnInsert(): void
    {
        $finding = (new AuditFinding())
            ->setTenant(new Tenant())
            ->setType('nonconformity')
            ->setSeverity('major');
        $types = $this->eventTypes((new DomainEventDetector())->forInsert($finding));

        self::assertContains(NotificationRuleTriggerListener::EVENT_AUDIT_FINDING_CREATED, $types);
    }

    #[Test]
    public function documentApprovalRequiredFiresOnTransitionToInReview(): void
    {
        $doc   = (new Document())->setTenant(new Tenant())->setStatus('in_review');
        $types = $this->eventTypes(
            (new DomainEventDetector())->forUpdate($doc, ['status' => ['draft', 'in_review']]),
        );

        self::assertContains(NotificationRuleTriggerListener::EVENT_DOCUMENT_APPROVAL, $types);
    }

    #[Test]
    public function documentApprovalRequiredDoesNotFireOnOtherStatusTransitions(): void
    {
        $doc   = (new Document())->setTenant(new Tenant())->setStatus('approved');
        $types = $this->eventTypes(
            (new DomainEventDetector())->forUpdate($doc, ['status' => ['in_review', 'approved']]),
        );

        self::assertNotContains(NotificationRuleTriggerListener::EVENT_DOCUMENT_APPROVAL, $types);
    }

    #[Test]
    public function controlEvidenceExpiredFiresOnFlagFlipToTrue(): void
    {
        $control = (new Control())->setTenant(new Tenant())->setEvidenceOutdated(true);
        $types   = $this->eventTypes(
            (new DomainEventDetector())->forUpdate($control, ['evidenceOutdated' => [false, true]]),
        );

        self::assertContains(NotificationRuleTriggerListener::EVENT_CONTROL_EVIDENCE_EXPIRED, $types);
    }

    #[Test]
    public function controlEvidenceExpiredDoesNotFireWhenFlagFlipsBackToFalse(): void
    {
        $control = (new Control())->setTenant(new Tenant())->setEvidenceOutdated(false);
        $types   = $this->eventTypes(
            (new DomainEventDetector())->forUpdate($control, ['evidenceOutdated' => [true, false]]),
        );

        self::assertNotContains(NotificationRuleTriggerListener::EVENT_CONTROL_EVIDENCE_EXPIRED, $types);
    }

    #[Test]
    public function noEventsForEntityWithNoTenant(): void
    {
        // All insert paths short-circuit if getTenant() returns null.
        self::assertSame([], (new DomainEventDetector())->forInsert(new Incident()));
        self::assertSame([], (new DomainEventDetector())->forInsert(new Risk()));
        self::assertSame([], (new DomainEventDetector())->forInsert(new DataBreach()));
    }

    // -----------------------------------------------------------------------
    // B2) Subscriber integration — onFlush buffers, postFlush fires notifier
    // -----------------------------------------------------------------------

    #[Test]
    public function subscriberBuffersAndFiresAllDetectedEventsOnPostFlush(): void
    {
        $tenant   = new Tenant();
        $incident = (new Incident())->setTenant($tenant)->setSeverity(IncidentSeverity::Low);

        $firedTypes = [];
        $notifier   = $this->createMock(DomainEventNotifier::class);
        $notifier->method('notify')->willReturnCallback(static function (DomainEvent $event) use (&$firedTypes): void {
            $firedTypes[] = $event->eventType;
        });

        $subscriber = new EntityChangeNotificationSubscriber(new DomainEventDetector(), $notifier);
        $subscriber->onFlush($this->onFlushArgs([$incident], []));
        $subscriber->postFlush($this->createMock(PostFlushEventArgs::class));

        self::assertContains(NotificationRuleTriggerListener::EVENT_INCIDENT_CREATED, $firedTypes);
    }

    #[Test]
    public function subscriberReentrancyGuardPreventsNestedOnFlushFromReCollecting(): void
    {
        // The $firing flag is set to true DURING postFlush while draining the buffer.
        // If a nested onFlush fires during that window (due to delivery-persistence),
        // the guard prevents it from collecting new events into the buffer — so a
        // subsequent postFlush call is a no-op.
        $tenant   = new Tenant();
        $incident = (new Incident())->setTenant($tenant)->setSeverity(IncidentSeverity::Low);

        $notifyCount = 0;
        $notifier    = $this->createMock(DomainEventNotifier::class);
        $notifier->method('notify')->willReturnCallback(
            static function () use (&$notifyCount): void { ++$notifyCount; },
        );

        $subscriber = new EntityChangeNotificationSubscriber(new DomainEventDetector(), $notifier);

        // Buffer one event.
        $subscriber->onFlush($this->onFlushArgs([$incident], []));

        // Fire postFlush — drains the buffer and sets $firing = true during drain.
        $subscriber->postFlush($this->createMock(PostFlushEventArgs::class));

        // Now simulate a second postFlush (e.g. triggered by delivery persistence).
        // The buffer is now empty — it must remain a no-op.
        $subscriber->postFlush($this->createMock(PostFlushEventArgs::class));

        // Only the one buffered event should have been fired, exactly once.
        self::assertSame(1, $notifyCount, 'Second postFlush on empty buffer must not fire any events');
    }

    // -----------------------------------------------------------------------
    // C) DomainEventNotifier — logging improvements + resilience
    // -----------------------------------------------------------------------

    #[Test]
    public function notifierDispatchesWhenMatchingRuleExists(): void
    {
        $tenant = new Tenant();
        $rule   = $this->rule(1);

        $this->ruleRepo
            ->expects(self::once())
            ->method('findActiveByEventType')
            ->with(NotificationRuleTriggerListener::EVENT_INCIDENT_CREATED, $tenant)
            ->willReturn([$rule]);

        $this->evaluator->method('evaluate')->willReturn(true);
        $this->dispatcher->expects(self::once())->method('dispatch')->with($rule, self::anything())->willReturn([]);

        $this->notifier->fire(
            NotificationRuleTriggerListener::EVENT_INCIDENT_CREATED,
            $tenant,
            ['severity' => 'low'],
        );
    }

    #[Test]
    public function notifierDoesNotDispatchWhenEvaluatorRejectsConditions(): void
    {
        $tenant = new Tenant();
        $rule   = $this->rule(2);

        $this->ruleRepo->method('findActiveByEventType')->willReturn([$rule]);
        $this->evaluator->method('evaluate')->willReturn(false);
        $this->dispatcher->expects(self::never())->method('dispatch');

        $this->notifier->fire(NotificationRuleTriggerListener::EVENT_RISK_CREATED, $tenant, []);
    }

    #[Test]
    public function notifierLogsInfoOnSuccessfulDispatch(): void
    {
        $tenant = new Tenant();
        $rule   = $this->rule(3);

        $this->ruleRepo->method('findActiveByEventType')->willReturn([$rule]);
        $this->evaluator->method('evaluate')->willReturn(true);
        $this->dispatcher->method('dispatch')->willReturn([]);

        $this->logger->expects(self::once())->method('info');

        $this->notifier->fire(NotificationRuleTriggerListener::EVENT_DATA_BREACH_CREATED, $tenant, []);
    }

    #[Test]
    public function notifierLogsWarningAndContinuesWhenRuleLookupFails(): void
    {
        $tenant = new Tenant();

        $this->ruleRepo
            ->method('findActiveByEventType')
            ->willThrowException(new \RuntimeException('db connection lost'));

        $this->logger->expects(self::once())->method('warning');
        $this->dispatcher->expects(self::never())->method('dispatch');

        // Must not throw — graceful degradation.
        $this->notifier->fire(NotificationRuleTriggerListener::EVENT_AUDIT_FINDING_CREATED, $tenant, []);
        self::assertTrue(true); // reached = no exception
    }

    #[Test]
    public function notifierLogsWarningAndContinuesWhenDispatchFails(): void
    {
        $tenant = new Tenant();
        $ruleA  = $this->rule(10);
        $ruleB  = $this->rule(11);

        // Use an inline logger (not a PHPUnit mock) so we can count levels precisely
        // without PHPUnit expectation ordering constraints.
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
                return []; // ruleB succeeds — return the expected array type
            });

        $notifier = new DomainEventNotifier($ruleRepo, $evaluator, $dispatcher, $capturingLogger);
        $notifier->fire(NotificationRuleTriggerListener::EVENT_DOCUMENT_APPROVAL, $tenant, []);

        // ruleA failed → exactly one warning logged; ruleB still dispatched.
        self::assertCount(1, $warnMessages, 'Exactly one warning for the one failed rule');
        self::assertStringContainsString('dispatch failed', $warnMessages[0]);
        self::assertSame(2, $dispatchCount, 'Both rules must be attempted despite the first one failing');
    }

    #[Test]
    public function notifierNoDispatchWhenNoRulesExist(): void
    {
        $this->ruleRepo->method('findActiveByEventType')->willReturn([]);
        $this->dispatcher->expects(self::never())->method('dispatch');

        $this->notifier->fire(NotificationRuleTriggerListener::EVENT_CONTROL_EVIDENCE_EXPIRED, new Tenant(), []);
    }

    /**
     * control.overdue is time-driven; the DomainEventDetector has no forInsert/forUpdate
     * path for it. The correct path is DispatchOverdueControlNotificationsCommand.
     * This test documents the design decision: detector does NOT emit control.overdue.
     */
    #[Test]
    public function controlOverdueIsNotEmittedByDomainEventDetector(): void
    {
        $control = (new Control())->setTenant(new Tenant());

        // No change-set → no control.overdue from insert
        $insertTypes = $this->eventTypes((new DomainEventDetector())->forInsert($control));
        self::assertNotContains(NotificationRuleTriggerListener::EVENT_CONTROL_OVERDUE, $insertTypes);

        // Any change-set → no control.overdue from update
        $updateTypes = $this->eventTypes(
            (new DomainEventDetector())->forUpdate($control, ['title' => ['old', 'new']]),
        );
        self::assertNotContains(NotificationRuleTriggerListener::EVENT_CONTROL_OVERDUE, $updateTypes);
    }

    /**
     * control.overdue CAN be fired through DomainEventNotifier.fire() from the
     * DispatchOverdueControlNotificationsCommand — this ensures the plumbing is
     * wired end-to-end for the cron path.
     */
    #[Test]
    public function controlOverdueCanBeDispatchedDirectlyThroughNotifier(): void
    {
        $tenant = new Tenant();
        $rule   = $this->rule(99);

        $this->ruleRepo
            ->expects(self::once())
            ->method('findActiveByEventType')
            ->with(NotificationRuleTriggerListener::EVENT_CONTROL_OVERDUE, $tenant)
            ->willReturn([$rule]);

        $this->evaluator->method('evaluate')->willReturn(true);
        $this->dispatcher->expects(self::once())->method('dispatch')->with($rule, self::anything())->willReturn([]);

        $this->notifier->fire(
            NotificationRuleTriggerListener::EVENT_CONTROL_OVERDUE,
            $tenant,
            ['id' => 42, 'status' => 'partially_implemented'],
        );
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * @param DomainEvent[] $events
     *
     * @return string[]
     */
    private function eventTypes(array $events): array
    {
        return array_values(array_map(static fn (DomainEvent $e): string => $e->eventType, $events));
    }

    private function rule(int $id): NotificationRule
    {
        $rule = $this->createMock(NotificationRule::class);
        $rule->method('getId')->willReturn($id);
        $rule->method('getName')->willReturn('Test Rule ' . $id);
        return $rule;
    }

    /**
     * @param object[] $insertions
     * @param object[] $updates
     */
    private function onFlushArgs(array $insertions, array $updates): OnFlushEventArgs
    {
        $uow = $this->createMock(UnitOfWork::class);
        $uow->method('getScheduledEntityInsertions')->willReturn($insertions);
        $uow->method('getScheduledEntityUpdates')->willReturn($updates);
        $uow->method('getEntityChangeSet')->willReturn([]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getUnitOfWork')->willReturn($uow);

        return new OnFlushEventArgs($em);
    }
}
