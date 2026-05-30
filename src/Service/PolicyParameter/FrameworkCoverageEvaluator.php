<?php

declare(strict_types=1);

namespace App\Service\PolicyParameter;

/**
 * Aggregates per-framework coverage across catalog params that carry a constraint
 * for the framework, given a map of effective values. Callers resolve effective
 * values (override→profile→baseline→default) via PolicyParameterResolver first.
 */
final readonly class FrameworkCoverageEvaluator
{
    public function __construct(
        private PolicyParameterCatalog $catalog,
        private FrameworkConstraintChecker $checker,
    ) {
    }

    /**
     * @param list<string>         $frameworks
     * @param array<string, mixed> $resolvedValues param-key => effective value
     *
     * @return array<string, FrameworkCoverage> keyed by framework
     */
    public function evaluate(array $frameworks, array $resolvedValues): array
    {
        $out = [];
        foreach ($frameworks as $framework) {
            $total = 0;
            $violations = [];
            foreach ($this->catalog->all() as $key => $def) {
                if ($def->frameworkMin($framework) === null) {
                    continue;
                }
                ++$total;
                $value = $resolvedValues[$key] ?? $def->default;
                $violation = $this->checker->check($def, $value, $framework);
                if ($violation !== null) {
                    $violations[] = $violation;
                }
            }
            $out[$framework] = new FrameworkCoverage($framework, $total, $violations);
        }

        return $out;
    }
}
