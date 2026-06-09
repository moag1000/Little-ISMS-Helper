<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\Entity\Incident;
use App\Entity\Notification\NotificationRule;
use App\Entity\Tenant;
use App\Enum\IncidentSeverity;
use App\EventListener\NotificationRuleTriggerListener;
use App\Repository\Notification\NotificationRuleRepository;
use App\Service\Notification\NotificationDispatcher;
use App\Service\Notification\NotificationRuleEvaluator;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\UnitOfWork;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for NotificationRuleTriggerListener.
 *
 * Verifies that:
 * - `incident.created` fires on postPersist when a matching rule exists.
 * - `incident.severity_high` fires on postUpdate when severity changes to High/Critical.
 * - No dispatch occurs when the rule evaluator rejects conditions.
 * - No dispatch occurs when there is no tenant on the incident.
 * - No dispatch occurs when severity changes to a non-high value.
 * - Errors in the dispatcher are swallowed (best-effort).
 */
#[AllowMockObjectsWithoutExpectations]
final class NotificationRuleTriggerListenerTest extends TestCase
{
    private MockObject&NotificationRuleRepository $ruleRepo;
    private MockObject&NotificationRuleEvaluator $evaluator;
    private MockObject&NotificationDispatcher $dispatcher;
    private MockObject&LoggerInterface $logger;
    private NotificationRuleTriggerListener $listener;

    protected function setUp(): void
    {
        $this->ruleRepo   = $this->createMock(NotificationRuleRepository::class);
        $this->evaluator  = $this->createMock(NotificationRuleEvaluator::class);
        $this->dispatcher = $this->createMock(NotificationDispatcher::class);
        $this->logger     = $this->createMock(LoggerInterface::class);

        $this->listener = new NotificationRuleTriggerListener(
            $this->ruleRepo,
            $this->evaluator,
            $this->dispatcher,
            $this->logger,
        );
    }

    // -----------------------------------------------------------------------
    // postPersist — incident.created
    // -----------------------------------------------------------------------

    #[Test]
    public function postPersistDispatchesWhenMatchingRuleExists(): void
    {
        $tenant   = $this->tenant(42);
        $incident = $this->incident($tenant, IncidentSeverity::High);
        $rule     = $this->rule(1);

        $this->ruleRepo
            ->expects($this->once())
            ->method('findActiveByEventType')
            ->with(NotificationRuleTriggerListener::EVENT_INCIDENT_CREATED, $tenant)
            ->willReturn([$rule]);

        $this->evaluator
            ->expects($this->once())
            ->method('evaluate')
            ->with($rule, $this->callback(static fn ($v) => is_array($v)))
            ->willReturn(true);

        $this->dispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with($rule, $this->callback(static fn ($v) => is_array($v)));

        $this->listener->postPersist($incident, $this->persistArgs($incident));
    }

    #[Test]
    public function postPersistNoDispatchWhenNoRulesExist(): void
    {
        $tenant   = $this->tenant(42);
        $incident = $this->incident($tenant, IncidentSeverity::Low);

        $this->ruleRepo->method('findActiveByEventType')->willReturn([]);
        $this->dispatcher->expects($this->never())->method('dispatch');

        $this->listener->postPersist($incident, $this->persistArgs($incident));
    }

    #[Test]
    public function postPersistNoDispatchWhenEvaluatorRejectsConditions(): void
    {
        $tenant   = $this->tenant(42);
        $incident = $this->incident($tenant, IncidentSeverity::Medium);
        $rule     = $this->rule(2);

        $this->ruleRepo->method('findActiveByEventType')->willReturn([$rule]);
        $this->evaluator->method('evaluate')->willReturn(false);
        $this->dispatcher->expects($this->never())->method('dispatch');

        $this->listener->postPersist($incident, $this->persistArgs($incident));
    }

    #[Test]
    public function postPersistNoDispatchWhenIncidentHasNoTenant(): void
    {
        $incident = $this->incident(null, IncidentSeverity::Critical);

        $this->ruleRepo->expects($this->never())->method('findActiveByEventType');
        $this->dispatcher->expects($this->never())->method('dispatch');

        $this->listener->postPersist($incident, $this->persistArgs($incident));
    }

    // -----------------------------------------------------------------------
    // postUpdate — incident.severity_high
    // -----------------------------------------------------------------------

    #[Test]
    public function postUpdateDispatchesWhenSeverityChangesToHigh(): void
    {
        $tenant   = $this->tenant(7);
        $incident = $this->incident($tenant, IncidentSeverity::High);
        $rule     = $this->rule(3);

        $this->ruleRepo
            ->expects($this->once())
            ->method('findActiveByEventType')
            ->with(NotificationRuleTriggerListener::EVENT_INCIDENT_SEVERITY_HIGH, $tenant)
            ->willReturn([$rule]);

        $this->evaluator->method('evaluate')->willReturn(true);
        $this->dispatcher->expects($this->once())->method('dispatch')->with($rule, $this->callback(static fn ($v) => is_array($v)));

        $args = $this->updateArgs($incident, ['severity' => [IncidentSeverity::Low, IncidentSeverity::High]]);
        $this->listener->postUpdate($incident, $args);
    }

    #[Test]
    public function postUpdateDispatchesWhenSeverityChangesToCritical(): void
    {
        $tenant   = $this->tenant(7);
        $incident = $this->incident($tenant, IncidentSeverity::Critical);
        $rule     = $this->rule(4);

        $this->ruleRepo
            ->expects($this->once())
            ->method('findActiveByEventType')
            ->with(NotificationRuleTriggerListener::EVENT_INCIDENT_SEVERITY_HIGH, $tenant)
            ->willReturn([$rule]);

        $this->evaluator->method('evaluate')->willReturn(true);
        $this->dispatcher->expects($this->once())->method('dispatch');

        $args = $this->updateArgs($incident, ['severity' => [IncidentSeverity::Medium, IncidentSeverity::Critical]]);
        $this->listener->postUpdate($incident, $args);
    }

    #[Test]
    public function postUpdateNoDispatchWhenSeverityChangesToLow(): void
    {
        $tenant   = $this->tenant(7);
        $incident = $this->incident($tenant, IncidentSeverity::Low);

        $this->ruleRepo->expects($this->never())->method('findActiveByEventType');
        $this->dispatcher->expects($this->never())->method('dispatch');

        $args = $this->updateArgs($incident, ['severity' => [IncidentSeverity::High, IncidentSeverity::Low]]);
        $this->listener->postUpdate($incident, $args);
    }

    #[Test]
    public function postUpdateNoDispatchWhenSeverityFieldNotChanged(): void
    {
        $tenant   = $this->tenant(7);
        $incident = $this->incident($tenant, IncidentSeverity::High);

        $this->ruleRepo->expects($this->never())->method('findActiveByEventType');
        $this->dispatcher->expects($this->never())->method('dispatch');

        // Change-set has a different field — severity unchanged.
        $args = $this->updateArgs($incident, ['title' => ['Old title', 'New title']]);
        $this->listener->postUpdate($incident, $args);
    }

    #[Test]
    public function postUpdateNoDispatchWhenIncidentHasNoTenant(): void
    {
        $incident = $this->incident(null, IncidentSeverity::Critical);

        $this->ruleRepo->expects($this->never())->method('findActiveByEventType');
        $this->dispatcher->expects($this->never())->method('dispatch');

        $args = $this->updateArgs($incident, ['severity' => [IncidentSeverity::Low, IncidentSeverity::Critical]]);
        $this->listener->postUpdate($incident, $args);
    }

    #[Test]
    public function postUpdateAcceptsRawStringSeverityInChangeSet(): void
    {
        // ORM sometimes returns raw strings from the change-set for enum columns.
        $tenant   = $this->tenant(9);
        $incident = $this->incident($tenant, IncidentSeverity::High);
        $rule     = $this->rule(5);

        $this->ruleRepo->method('findActiveByEventType')->willReturn([$rule]);
        $this->evaluator->method('evaluate')->willReturn(true);
        $this->dispatcher->expects($this->once())->method('dispatch');

        // Use raw string 'high' instead of the enum instance.
        $args = $this->updateArgs($incident, ['severity' => ['low', 'high']]);
        $this->listener->postUpdate($incident, $args);
    }

    // -----------------------------------------------------------------------
    // Resilience — dispatcher errors must not bubble up
    // -----------------------------------------------------------------------

    #[Test]
    public function dispatcherExceptionIsSwallowedSoFlushIsNotAborted(): void
    {
        $tenant   = $this->tenant(42);
        $incident = $this->incident($tenant, IncidentSeverity::High);
        $rule     = $this->rule(99);

        $this->ruleRepo->method('findActiveByEventType')->willReturn([$rule]);
        $this->evaluator->method('evaluate')->willReturn(true);
        $this->dispatcher
            ->method('dispatch')
            ->willThrowException(new \RuntimeException('channel delivery failed'));

        // Must not throw; logger::error should be called.
        $this->logger->expects($this->once())->method('error');

        $this->listener->postPersist($incident, $this->persistArgs($incident));
        // If we reach here the exception was swallowed — test passes.
        $this->assertTrue(true);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function tenant(int $id): Tenant
    {
        $tenant = $this->createMock(Tenant::class);
        $tenant->method('getId')->willReturn($id);
        return $tenant;
    }

    private function incident(?Tenant $tenant, IncidentSeverity $severity): Incident
    {
        $incident = $this->createMock(Incident::class);
        $incident->method('getId')->willReturn(1);
        $incident->method('getTenant')->willReturn($tenant);
        $incident->method('getSeverity')->willReturn($severity);
        $incident->method('getStatus')->willReturn(null);
        $incident->method('getTitle')->willReturn('Test Incident');
        $incident->method('getCategory')->willReturn('phishing');
        return $incident;
    }

    private function rule(int $id): NotificationRule
    {
        $rule = $this->createMock(NotificationRule::class);
        $rule->method('getId')->willReturn($id);
        $rule->method('getName')->willReturn('Test Rule ' . $id);
        return $rule;
    }

    private function persistArgs(Incident $incident): PostPersistEventArgs
    {
        $em = $this->createMock(EntityManagerInterface::class);
        return new PostPersistEventArgs($incident, $em);
    }

    /**
     * @param array<string, array{0: mixed, 1: mixed}> $changeSet
     */
    private function updateArgs(Incident $incident, array $changeSet): PostUpdateEventArgs
    {
        $uow = $this->createMock(UnitOfWork::class);
        $uow->method('getEntityChangeSet')->willReturn($changeSet);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getUnitOfWork')->willReturn($uow);

        return new PostUpdateEventArgs($incident, $em);
    }
}
