<?php

declare(strict_types=1);

namespace App\Service\FollowUpTrigger;

/**
 * Sprint-2 Foundation P-7 — declarative follow-up trigger registry.
 *
 * Entity-class-keyed map of FollowUpTriggerDefinition lists. Consumers
 * (Doctrine entity listeners, controllers, services) call evaluate() with
 * the parent entity after a write and receive zero-or-more
 * FollowUpTriggerResult instances. The service itself is intentionally
 * side-effect-free — it neither persists, dispatches events nor talks to
 * the DB. That responsibility belongs to the caller (and keeps the unit
 * tests pure).
 *
 * Wave-1 customer: `IncidentFollowUpListener` registers the
 * `Incident.dataBreachOccurred = true` trigger to surface the GDPR Art. 33
 * 72h DataBreach skeleton. Future waves add Risk/Asset/Control triggers
 * without changing this class.
 *
 * Field resolution uses reflection-style accessor heuristics:
 *   1. `getDataBreachOccurred()` / `isDataBreachOccurred()`
 *   2. public property `dataBreachOccurred`
 * — so existing entities with `isXxx()` booleans work without adapter.
 */
final class FollowUpTriggerService
{
    /** @var array<class-string, array<int, FollowUpTriggerDefinition>> */
    private array $triggers = [];

    public function register(string $entityClass, FollowUpTriggerDefinition $definition): void
    {
        $this->triggers[$entityClass][] = $definition;
    }

    /**
     * @return list<FollowUpTriggerResult>
     */
    public function evaluate(object $entity): array
    {
        $matches = [];
        foreach ($this->triggers as $class => $defs) {
            if (!$entity instanceof $class) {
                continue;
            }
            foreach ($defs as $def) {
                if (!$this->matchesField($entity, $def)) {
                    continue;
                }
                $payload = [];
                if ($def->preFiller !== null) {
                    /** @var array<string, mixed> $payload */
                    $payload = ($def->preFiller)($entity);
                }
                $matches[] = new FollowUpTriggerResult($def, $payload);
            }
        }
        return $matches;
    }

    /**
     * @return array<class-string, array<int, FollowUpTriggerDefinition>>
     */
    public function getRegistry(): array
    {
        return $this->triggers;
    }

    private function matchesField(object $entity, FollowUpTriggerDefinition $def): bool
    {
        $value = $this->readField($entity, $def->fieldName);
        // Strict equality so a `true` trigger does not fire for an int `1`.
        return $value === $def->equals;
    }

    private function readField(object $entity, string $field): mixed
    {
        $candidates = [
            'get' . ucfirst($field),
            'is' . ucfirst($field),
        ];
        foreach ($candidates as $method) {
            if (method_exists($entity, $method)) {
                return $entity->$method();
            }
        }
        if (property_exists($entity, $field)) {
            $ref = new \ReflectionProperty($entity, $field);
            return $ref->getValue($entity);
        }
        return null;
    }
}
