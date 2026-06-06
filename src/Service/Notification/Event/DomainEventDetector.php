<?php

declare(strict_types=1);

namespace App\Service\Notification\Event;

use App\Entity\AuditFinding;
use App\Entity\Control;
use App\Entity\DataBreach;
use App\Entity\Document;
use App\Entity\Incident;
use App\Entity\Risk;
use App\Entity\Tenant;
use App\Risk\RiskMatrixThresholds;
use BackedEnum;

/**
 * Pure (DB-free) detector that turns an entity creation / change into the domain
 * events that NotificationRules can subscribe to. Kept free of Doctrine so the
 * mapping logic is unit-testable; the Doctrine subscriber only feeds it the
 * entity + change-set and forwards the result.
 *
 * Severity/status transitions are detected from the Doctrine change-set
 * (`field => [old, new]`) so an event fires once on the TRANSITION, never on
 * every save — the storm-avoidance guarantee. Computed/time-driven events
 * (risk.score_critical, control.overdue) are NOT here; they are emitted by the
 * cron `app:dispatch-domain-notifications` because they have no change-set.
 */
final class DomainEventDetector
{
    private const INCIDENT_HIGH = ['high', 'critical'];

    /**
     * @return DomainEvent[]
     */
    public function forInsert(object $entity): array
    {
        $tenant = $this->tenantOf($entity);
        if (!$tenant instanceof Tenant) {
            return [];
        }

        return match (true) {
            $entity instanceof Incident => array_filter([
                new DomainEvent('incident.created', $tenant, $this->incidentState($entity)),
                in_array($this->str($entity->getSeverity()), self::INCIDENT_HIGH, true)
                    ? new DomainEvent('incident.severity_high', $tenant, $this->incidentState($entity)) : null,
            ]),
            $entity instanceof Risk => array_filter([
                new DomainEvent('risk.created', $tenant, $this->riskState($entity)),
                RiskMatrixThresholds::classify($entity->getRiskScore()) === 'critical'
                    ? new DomainEvent('risk.score_critical', $tenant, $this->riskState($entity)) : null,
            ]),
            $entity instanceof DataBreach => [new DomainEvent('data_breach.created', $tenant, $this->dataBreachState($entity))],
            $entity instanceof AuditFinding => [new DomainEvent('audit.finding_created', $tenant, $this->auditFindingState($entity))],
            default => [],
        };
    }

    /**
     * @param array<string, array{0: mixed, 1: mixed}> $changeSet field => [old, new]
     *
     * @return DomainEvent[]
     */
    public function forUpdate(object $entity, array $changeSet): array
    {
        $tenant = $this->tenantOf($entity);
        if (!$tenant instanceof Tenant) {
            return [];
        }

        $events = [];

        if ($entity instanceof Incident && $this->transitionedInto($changeSet, 'severity', self::INCIDENT_HIGH)) {
            $events[] = new DomainEvent('incident.severity_high', $tenant, $this->incidentState($entity));
        }

        if ($entity instanceof Risk && (isset($changeSet['probability']) || isset($changeSet['impact']))
            && $this->riskCrossedIntoCritical($entity, $changeSet)) {
            $events[] = new DomainEvent('risk.score_critical', $tenant, $this->riskState($entity));
        }

        if ($entity instanceof DataBreach && isset($changeSet['severity'])
            && $this->str($changeSet['severity'][0]) !== $this->str($changeSet['severity'][1])) {
            $events[] = new DomainEvent('data_breach.severity_changed', $tenant, $this->dataBreachState($entity));
        }

        if ($entity instanceof Document && $this->transitionedInto($changeSet, 'status', ['in_review'])) {
            $events[] = new DomainEvent('document.approval_required', $tenant, $this->documentState($entity));
        }

        if ($entity instanceof Control && isset($changeSet['evidenceOutdated'])
            && $changeSet['evidenceOutdated'][0] === false && $changeSet['evidenceOutdated'][1] === true) {
            $events[] = new DomainEvent('control.evidence_expired', $tenant, $this->controlState($entity));
        }

        return $events;
    }

    /**
     * @param array<string, array{0: mixed, 1: mixed}> $changeSet
     * @param string[] $triggerValues
     */
    private function transitionedInto(array $changeSet, string $field, array $triggerValues): bool
    {
        if (!isset($changeSet[$field])) {
            return false;
        }
        $old = $this->str($changeSet[$field][0]);
        $new = $this->str($changeSet[$field][1]);

        return in_array($new, $triggerValues, true) && !in_array($old, $triggerValues, true);
    }

    /**
     * Risk score = probability × impact. Reconstruct the OLD score from the
     * change-set (unchanged dimension == current value) so risk.score_critical
     * fires only on the transition INTO the critical band, never repeatedly.
     *
     * @param array<string, array{0: mixed, 1: mixed}> $changeSet
     */
    private function riskCrossedIntoCritical(Risk $risk, array $changeSet): bool
    {
        $oldProb = isset($changeSet['probability']) ? (int) $changeSet['probability'][0] : (int) $risk->getProbability();
        $oldImp = isset($changeSet['impact']) ? (int) $changeSet['impact'][0] : (int) $risk->getImpact();

        $newIsCritical = RiskMatrixThresholds::classify($risk->getRiskScore()) === 'critical';
        $oldIsCritical = RiskMatrixThresholds::classify($oldProb * $oldImp) === 'critical';

        return $newIsCritical && !$oldIsCritical;
    }

    private function tenantOf(object $entity): ?Tenant
    {
        return method_exists($entity, 'getTenant') ? $entity->getTenant() : null;
    }

    private function str(mixed $value): ?string
    {
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }
        if ($value === null) {
            return null;
        }

        return is_scalar($value) ? (string) $value : null;
    }

    /** @return array<string, scalar|null> */
    private function incidentState(Incident $e): array
    {
        return [
            'id' => $e->getId(),
            'severity' => $this->str($e->getSeverity()),
            'status' => $this->str($e->getStatus()),
            'category' => $e->getCategory(),
            'title' => $e->getTitle(),
        ];
    }

    /** @return array<string, scalar|null> */
    private function riskState(Risk $e): array
    {
        return [
            'id' => $e->getId(),
            'title' => $e->getTitle(),
            'score' => $e->getRiskScore(),
            'status' => $this->str($e->getStatus()),
        ];
    }

    /** @return array<string, scalar|null> */
    private function dataBreachState(DataBreach $e): array
    {
        return [
            'id' => $e->getId(),
            'severity' => $this->str($e->getSeverity()),
            'status' => $this->str($e->getStatus()),
            'title' => $e->getTitle(),
        ];
    }

    /** @return array<string, scalar|null> */
    private function auditFindingState(AuditFinding $e): array
    {
        return [
            'id' => $e->getId(),
            'severity' => $this->str($e->getSeverity()),
            'status' => $this->str($e->getStatus()),
            'title' => $e->getTitle(),
        ];
    }

    /** @return array<string, scalar|null> */
    private function documentState(Document $e): array
    {
        return [
            'id' => $e->getId(),
            'status' => $this->str($e->getStatus()),
            'filename' => $e->getOriginalFilename(),
            'category' => $e->getCategory(),
        ];
    }

    /** @return array<string, scalar|null> */
    private function controlState(Control $e): array
    {
        return [
            'id' => $e->getId(),
            'evidenceOutdated' => $e->isEvidenceOutdated(),
            'status' => $e->getImplementationStatus(),
        ];
    }
}
