<?php

declare(strict_types=1);

namespace App\Service\PolicyParameter;

/**
 * Checks one parameter value against one framework's required minimum.
 * Enum order convention: ParameterDefinition::$allowed is weakest → strongest,
 * so "satisfies" means rank(value) >= rank(min). Ints compare numerically.
 * Returns null when satisfied or when the framework imposes no constraint.
 */
final class FrameworkConstraintChecker
{
    public function check(ParameterDefinition $def, mixed $value, string $framework): ?ConstraintViolation
    {
        $min = $def->frameworkMin($framework);
        if ($min === null) {
            return null;
        }

        $satisfied = $def->type === 'enum'
            ? $this->enumSatisfies($def->allowed, $value, $min)
            : $value >= $min;

        if ($satisfied) {
            return null;
        }

        return new ConstraintViolation(
            paramKey: $def->key,
            framework: $framework,
            requiredMin: $min,
            actualValue: $value,
            authority: $def->frameworkAuthority($framework),
            source: $def->frameworkConstraints[$framework]['source'] ?? null,
        );
    }

    /**
     * @param list<string> $allowed weakest → strongest
     */
    private function enumSatisfies(array $allowed, mixed $value, mixed $min): bool
    {
        $vi = array_search($value, $allowed, true);
        $mi = array_search($min, $allowed, true);

        if ($mi === false) {
            return true;
        }
        if ($vi === false) {
            return false;
        }

        return $vi >= $mi;
    }
}
