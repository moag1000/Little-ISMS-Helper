<?php

declare(strict_types=1);

namespace App\Lifecycle\EventListener;

use App\Lifecycle\FieldCompletionAutoTransitionInterface;
use App\Lifecycle\LifecycleTransitionInterface;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\Workflow\Registry;

/**
 * Listens to entity postUpdate events. For each lifecycle-managed entity,
 * reads its `lifecycle.auto_transition_rules` config (entity-class-keyed map)
 * and auto-transitions when all listed required fields are now non-empty, OR
 * when a structured AND/OR condition tree evaluates to true.
 *
 * Per-entity config lives in `config/packages/lifecycle.yaml`.
 *
 * --- Legacy format (backward-compatible) ---
 *     lifecycle.auto_transition_rules:
 *       App\Entity\DataBreach:
 *         assess_when_complete:
 *           workflow: data_breach_lifecycle
 *           transition: assess
 *           required_fields: [severity, affectedDataSubjectsCount, dataCategories]
 *
 * --- Extended format (AND/OR condition tree, Y.1) ---
 *     lifecycle.auto_transition_rules:
 *       App\Entity\DataBreach:
 *         assess_high_severity:
 *           workflow: data_breach_lifecycle
 *           transition: assess
 *           conditions:
 *             all:                              # AND — all sub-conditions must be true
 *               - field: severity, comparison: ">=", value: high
 *               - field: affectedDataSubjectsCount, comparison: ">", value: 100
 *       App\Entity\Risk:
 *         accept_when_appetite_met:
 *           workflow: risk_lifecycle
 *           transition: accept
 *           conditions:
 *             any:                              # OR — at least one must be true
 *               - field: residualRisk, comparison: "<=", value_from: riskAppetiteThreshold
 *               - field: acceptedManually, comparison: "==", value: true
 *
 * Supported comparison operators: ==, !=, >, <, >=, <=
 * value_from: reads the comparison target from another field on the same entity.
 *
 * Rules that reference workflows not yet registered are silently skipped.
 * Auto-transitions fire best-effort: any exception is swallowed.
 *
 * @implements FieldCompletionAutoTransitionInterface
 */
#[AsDoctrineListener(event: Events::postUpdate)]
final class FieldCompletionAutoTransition implements FieldCompletionAutoTransitionInterface
{
    /**
     * Rule schema (union of legacy + extended formats):
     *
     * @param array<class-string, array<string, array{
     *     workflow: string,
     *     transition: string,
     *     required_fields?: string[],
     *     conditions?: array{all?: list<array{field: string, comparison: string, value?: mixed, value_from?: string}>, any?: list<array{field: string, comparison: string, value?: mixed, value_from?: string}>},
     * }>> $rules
     */
    public function __construct(
        private readonly Registry $workflowRegistry,
        private readonly LifecycleTransitionInterface $lifecycleService,
        private readonly PropertyAccessorInterface $propertyAccessor,
        private readonly array $rules = [],
    ) {}

    public function postUpdate(PostUpdateEventArgs $event): void
    {
        $entity = $event->getObject();
        $entityClass = $entity::class;

        if (!isset($this->rules[$entityClass])) {
            return;
        }

        foreach ($this->rules[$entityClass] as $ruleName => $rule) {
            if (!$this->ruleMatches($entity, $rule)) {
                continue;
            }

            try {
                $workflow = $this->workflowRegistry->get($entity, $rule['workflow']);
            } catch (\Throwable) {
                // Workflow not registered yet — skip gracefully
                continue;
            }

            if (!$workflow->can($entity, $rule['transition'])) {
                continue;
            }

            try {
                $this->lifecycleService->transition(
                    $entity,
                    $rule['workflow'],
                    $rule['transition'],
                    null,
                    'Auto-transition: ' . $ruleName,
                );
            } catch (\Throwable) {
                // Auto-transition is best-effort; never break the original write
            }
        }
    }

    // ── Rule matching ─────────────────────────────────────────────────────────

    /**
     * Evaluate a single rule against an entity.
     *
     * Priority:
     *   1. If `conditions` key is present  → evaluate AND/OR tree
     *   2. If `required_fields` key present → all-fields-filled check (legacy)
     *   3. Neither present                 → skip (misconfigured rule)
     */
    private function ruleMatches(object $entity, array $rule): bool
    {
        if (isset($rule['conditions'])) {
            return $this->evaluateConditionTree($entity, $rule['conditions']);
        }

        if (isset($rule['required_fields']) && is_array($rule['required_fields'])) {
            return $this->allFieldsCompleted($entity, $rule['required_fields']);
        }

        return false;
    }

    /**
     * Evaluate a condition tree.
     *
     * The tree is a map with at most two keys: `all` (AND) and `any` (OR).
     * Both may appear simultaneously — the overall result is AND of both groups.
     *
     * @param array{all?: list<array<string,mixed>>, any?: list<array<string,mixed>>} $tree
     */
    private function evaluateConditionTree(object $entity, array $tree): bool
    {
        // AND group: every condition must pass
        if (isset($tree['all'])) {
            foreach ($tree['all'] as $condition) {
                if (!$this->evaluateSingleCondition($entity, $condition)) {
                    return false;
                }
            }
        }

        // OR group: at least one condition must pass
        if (isset($tree['any'])) {
            $anyPassed = false;
            foreach ($tree['any'] as $condition) {
                if ($this->evaluateSingleCondition($entity, $condition)) {
                    $anyPassed = true;
                    break;
                }
            }
            if (!$anyPassed) {
                return false;
            }
        }

        // True when neither group is present (edge-case: empty conditions map)
        // or when all groups passed.
        return true;
    }

    /**
     * Evaluate one leaf condition.
     *
     * Keys:
     *   - field:       getter-accessible property name (camelCase)
     *   - comparison:  one of ==, !=, >, <, >=, <=
     *   - value:       scalar expected value (string / int / float / bool)
     *   - value_from:  read expected value from another property on the entity
     *                  (takes precedence over `value` when both present)
     *
     * @param array{field: string, comparison: string, value?: mixed, value_from?: string} $condition
     */
    private function evaluateSingleCondition(object $entity, array $condition): bool
    {
        if (!isset($condition['field'], $condition['comparison'])) {
            return false; // malformed — fail safely
        }

        try {
            $actual = $this->getField($entity, $condition['field']);

            $expected = isset($condition['value_from'])
                ? $this->getField($entity, $condition['value_from'])
                : ($condition['value'] ?? null);

            // Normalise boolean string literals coming from YAML
            if ($expected === 'true') {
                $expected = true;
            } elseif ($expected === 'false') {
                $expected = false;
            } elseif ($expected === 'null') {
                $expected = null;
            }

            // Numeric coercion for ordered comparison operators
            if (in_array($condition['comparison'], ['>', '<', '>=', '<='], true)
                && is_numeric($actual) && is_numeric($expected)) {
                $actual   = (float) $actual;
                $expected = (float) $expected;
            }

            return $this->compare($actual, $condition['comparison'], $expected);
        } catch (\Throwable) {
            return false; // field inaccessible — treat as not-met
        }
    }

    /**
     * Read a field value using getter convention then PropertyAccessor fallback.
     */
    private function getField(object $entity, string $field): mixed
    {
        $getter = 'get' . ucfirst($field);
        if (method_exists($entity, $getter)) {
            return $entity->{$getter}();
        }

        // Boolean isX / hasX convention
        $isSer = 'is' . ucfirst($field);
        if (method_exists($entity, $isSer)) {
            return $entity->{$isSer}();
        }

        return $this->propertyAccessor->getValue($entity, $field);
    }

    /**
     * Apply a comparison operator.
     *
     * Supported: ==, !=, >, <, >=, <=
     */
    private function compare(mixed $actual, string $operator, mixed $expected): bool
    {
        return match ($operator) {
            '==' => $actual == $expected,
            '!=' => $actual != $expected,
            '>'  => $actual > $expected,
            '<'  => $actual < $expected,
            '>=' => $actual >= $expected,
            '<=' => $actual <= $expected,
            default => false,
        };
    }

    // ── Legacy field-completion helper ────────────────────────────────────────

    /**
     * @param string[] $fields
     */
    private function allFieldsCompleted(object $entity, array $fields): bool
    {
        foreach ($fields as $field) {
            $getter = 'get' . ucfirst($field);

            if (!method_exists($entity, $getter)) {
                return false;
            }

            $value = $entity->{$getter}();

            if ($value === null || $value === '' || (is_array($value) && $value === [])) {
                return false;
            }
        }

        return true;
    }
}
