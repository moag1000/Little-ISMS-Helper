<?php

declare(strict_types=1);

namespace App\Service\Notification\Event;

use App\Entity\Tenant;
use App\Repository\Notification\NotificationRuleRepository;
use App\Service\Notification\NotificationDispatcher;
use App\Service\Notification\NotificationRuleEvaluator;
use Psr\Log\LoggerInterface;

/**
 * The single chokepoint that finally wires the notification engine together:
 * for a fired domain event it finds the tenant's active rules for that event
 * type, EVALUATES each rule's conditions (the previously call-less
 * NotificationRuleEvaluator), and dispatches the matching ones.
 *
 * Before this existed, 10 of 12 advertised event types could be configured as
 * rules but never fired — only the two SLA events reached delivery, and even
 * those skipped condition evaluation.
 *
 * A failing rule never blocks the others (each is isolated); delivery itself is
 * durable via the async-routed DispatchNotificationMessage.
 */
class DomainEventNotifier
{
    public function __construct(
        private readonly NotificationRuleRepository $ruleRepository,
        private readonly NotificationRuleEvaluator $evaluator,
        private readonly NotificationDispatcher $dispatcher,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function notify(DomainEvent $event): void
    {
        $this->fire($event->eventType, $event->tenant, $event->state);
    }

    /**
     * @param array<string, scalar|null> $state
     */
    public function fire(string $eventType, Tenant $tenant, array $state): void
    {
        foreach ($this->ruleRepository->findActiveByEventType($eventType, $tenant) as $rule) {
            try {
                if ($this->evaluator->evaluate($rule, $state)) {
                    $this->dispatcher->dispatch($rule, $state);
                }
            } catch (\Throwable $e) {
                $this->logger->error('DomainEventNotifier: dispatch failed', [
                    'event' => $eventType,
                    'rule_id' => $rule->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
