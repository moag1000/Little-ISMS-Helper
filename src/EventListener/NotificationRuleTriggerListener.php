<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Incident;
use App\Entity\Notification\NotificationRule;
use App\Entity\Tenant;
use App\Enum\IncidentSeverity;
use App\Repository\Notification\NotificationRuleRepository;
use App\Service\Notification\NotificationDispatcher;
use App\Service\Notification\NotificationRuleEvaluator;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Wires the notification-rule subsystem to real domain events.
 *
 * Without this listener, rules stored in `notification_rule` for event
 * types like `incident.created` / `incident.severity_high` are never
 * evaluated — the `NotificationRuleEvaluator` + `NotificationDispatcher`
 * stack was wired only to SLA cron events (see `SlaDeadlineWatcher`).
 *
 * This listener closes that gap for the two Incident triggers that are
 * both high-value and safe to wire in isolation:
 *
 *   - `incident.created`        → postPersist on Incident
 *   - `incident.severity_high`  → postUpdate on Incident when severity
 *                                   transitions to High or Critical
 *
 * Pattern mirrors AutoReactionCorrectiveActionListener: best-effort,
 * never throws, never blocks the persist/update.
 *
 * Entity state passed to the evaluator (keys match the condition builder
 * field names that operators can configure in the UI):
 *   - severity   : string  (e.g. 'high', 'critical')
 *   - status     : string  (e.g. 'reported')
 *   - category   : string
 *   - incident_id: int
 *   - title      : string
 */
#[AsEntityListener(event: Events::postPersist, entity: Incident::class)]
#[AsEntityListener(event: Events::postUpdate, entity: Incident::class)]
final class NotificationRuleTriggerListener
{
    /** Event-type string for new incidents (matches NotificationRuleType choices). */
    public const EVENT_INCIDENT_CREATED = 'incident.created';

    /** Event-type string for high/critical severity changes. */
    public const EVENT_INCIDENT_SEVERITY_HIGH = 'incident.severity_high';

    /** Severity values that qualify as "high or above". */
    private const HIGH_SEVERITIES = [IncidentSeverity::High, IncidentSeverity::Critical];

    public function __construct(
        private readonly NotificationRuleRepository $ruleRepository,
        private readonly NotificationRuleEvaluator $evaluator,
        private readonly NotificationDispatcher $dispatcher,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * Fires `incident.created` for every freshly persisted Incident.
     */
    public function postPersist(Incident $incident, PostPersistEventArgs $args): void
    {
        $tenant = $incident->getTenant();
        if ($tenant === null) {
            return;
        }

        $this->triggerRules(
            self::EVENT_INCIDENT_CREATED,
            $this->buildEntityState($incident),
            $tenant,
        );
    }

    /**
     * Fires `incident.severity_high` when severity changes to High or Critical.
     *
     * The change-set is consulted via UnitOfWork to avoid firing on every save.
     */
    public function postUpdate(Incident $incident, PostUpdateEventArgs $args): void
    {
        $tenant = $incident->getTenant();
        if ($tenant === null) {
            return;
        }

        $uow       = $args->getObjectManager()->getUnitOfWork();
        $changeSet = $uow->getEntityChangeSet($incident);

        // Only fire when the severity field actually changed
        if (!isset($changeSet['severity'])) {
            return;
        }

        $newSeverity = $changeSet['severity'][1];
        // $newSeverity may be an IncidentSeverity enum or a raw string depending on ORM hydration.
        $newSeverityEnum = $newSeverity instanceof IncidentSeverity
            ? $newSeverity
            : (is_string($newSeverity) ? IncidentSeverity::tryFrom($newSeverity) : null);

        if ($newSeverityEnum === null || !in_array($newSeverityEnum, self::HIGH_SEVERITIES, true)) {
            return;
        }

        $this->triggerRules(
            self::EVENT_INCIDENT_SEVERITY_HIGH,
            $this->buildEntityState($incident),
            $tenant,
        );
    }

    /**
     * Build the entity state array passed to the rule evaluator.
     *
     * Keys match the condition-builder field names documented in the UI
     * (NotificationRuleType) so operators can write conditions like
     * {"field": "severity", "op": ">=", "value": "high"}.
     *
     * @return array<string, mixed>
     */
    private function buildEntityState(Incident $incident): array
    {
        return [
            'incident_id' => $incident->getId(),
            'title'       => $incident->getTitle() ?? '',
            'severity'    => $incident->getSeverity()?->value ?? '',
            'status'      => $incident->getStatus()?->value ?? '',
            'category'    => $incident->getCategory() ?? '',
        ];
    }

    /**
     * Look up active rules for the given event type + tenant, evaluate each,
     * and dispatch if conditions pass. Failures are logged but never re-thrown.
     *
     * @param array<string, mixed> $entityState
     */
    private function triggerRules(string $eventType, array $entityState, Tenant $tenant): void
    {
        try {
            $rules = $this->ruleRepository->findActiveByEventType($eventType, $tenant);
        } catch (\Throwable $e) {
            $this->logger->error('NotificationRuleTriggerListener: rule lookup failed', [
                'event_type' => $eventType,
                'error'      => $e->getMessage(),
            ]);
            return;
        }

        foreach ($rules as $rule) {
            $this->dispatchRule($rule, $entityState, $eventType);
        }
    }

    /**
     * Evaluate a single rule and dispatch if conditions match.
     */
    private function dispatchRule(NotificationRule $rule, array $entityState, string $eventType): void
    {
        try {
            if (!$this->evaluator->evaluate($rule, $entityState)) {
                return;
            }

            $this->dispatcher->dispatch($rule, $entityState);

            $this->logger->info('NotificationRuleTriggerListener: rule dispatched', [
                'event_type' => $eventType,
                'rule_id'    => $rule->getId(),
                'rule_name'  => $rule->getName(),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('NotificationRuleTriggerListener: dispatch failed', [
                'event_type' => $eventType,
                'rule_id'    => $rule->getId(),
                'error'      => $e->getMessage(),
            ]);
        }
    }
}
