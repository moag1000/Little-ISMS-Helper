<?php

declare(strict_types=1);

namespace App\Service\Notification;

use App\Entity\Notification\NotificationRule;

/**
 * Evaluates a NotificationRule's JSON conditions against a runtime entity state.
 *
 * Condition format (each element of the conditions array):
 *   {"field": "severity", "op": ">=", "value": "high"}
 *
 * Optional top-level _logic key (placed as first element OR separate):
 *   {"_logic": "or"}  → OR-join; default is AND.
 *
 * Supported operators: ==, !=, >, <, >=, <=, in, contains
 *
 * Severity comparison uses a canonical ordering:
 *   low < medium < high < critical
 */
class NotificationRuleEvaluator
{
    private const SEVERITY_ORDER = ['low' => 1, 'medium' => 2, 'high' => 3, 'critical' => 4];

    /**
     * @param array<string, mixed> $entityState
     */
    public function evaluate(NotificationRule $rule, array $entityState): bool
    {
        $conditions = $rule->getConditions();

        // Extract optional _logic directive
        $logic = 'and';
        $realConditions = [];
        foreach ($conditions as $condition) {
            if (isset($condition['_logic'])) {
                $logic = strtolower((string) $condition['_logic']);
                continue;
            }
            $realConditions[] = $condition;
        }

        if (empty($realConditions)) {
            // No conditions → rule always matches (opt-in to receive everything)
            return true;
        }

        $results = array_map(
            fn(array $cond) => $this->evaluateCondition($cond, $entityState),
            $realConditions,
        );

        return $logic === 'or'
            ? in_array(true, $results, true)
            : !in_array(false, $results, true);
    }

    /**
     * @param array<string, mixed> $condition
     * @param array<string, mixed> $entityState
     */
    private function evaluateCondition(array $condition, array $entityState): bool
    {
        if (!isset($condition['field'], $condition['op'], $condition['value'])) {
            // Malformed condition — treat as non-matching (fail safe)
            return false;
        }

        $field = (string) $condition['field'];
        $op    = (string) $condition['op'];
        $expected = $condition['value'];

        // Missing field in entity state — non-matching
        if (!array_key_exists($field, $entityState)) {
            return false;
        }

        $actual = $entityState[$field];

        return match ($op) {
            '=='       => $this->compare($actual, $expected) === 0,
            '!='       => $this->compare($actual, $expected) !== 0,
            '>'        => $this->compare($actual, $expected) > 0,
            '<'        => $this->compare($actual, $expected) < 0,
            '>='       => $this->compare($actual, $expected) >= 0,
            '<='       => $this->compare($actual, $expected) <= 0,
            'in'       => is_array($expected) && in_array($actual, $expected, true),
            'contains' => is_string($actual) && is_string($expected) && str_contains($actual, $expected),
            default    => false,
        };
    }

    /**
     * Compare two values. For known severity strings uses the canonical order;
     * for numerics uses numeric comparison; otherwise falls back to string comparison.
     */
    private function compare(mixed $actual, mixed $expected): int
    {
        // Severity-aware comparison
        $actualSev   = is_string($actual)   ? (self::SEVERITY_ORDER[strtolower($actual)]   ?? null) : null;
        $expectedSev = is_string($expected) ? (self::SEVERITY_ORDER[strtolower($expected)] ?? null) : null;

        if ($actualSev !== null && $expectedSev !== null) {
            return $actualSev <=> $expectedSev;
        }

        // Numeric comparison
        if (is_numeric($actual) && is_numeric($expected)) {
            return (float) $actual <=> (float) $expected;
        }

        // String fallback
        return strcmp((string) $actual, (string) $expected);
    }
}
