<?php

declare(strict_types=1);

namespace App\Service\PolicyParameter;

/**
 * Turns resolved parameter values into a {interpolate-key => value} map for the
 * policy document renderer. Only params whose catalog entry declares
 * `template_slot.interpolate` contribute. Missing values fall back to the
 * catalog default.
 */
final readonly class PolicyParameterVariables
{
    public function __construct(
        private PolicyParameterCatalog $catalog,
    ) {
    }

    /**
     * @param array<string, mixed> $resolvedValues param-key => effective value
     *
     * @return array<string, mixed> interpolate-key => value
     */
    public function build(array $resolvedValues): array
    {
        $out = [];
        foreach ($this->catalog->all() as $key => $def) {
            $interpolate = $def->templateSlot['interpolate'] ?? null;
            if ($interpolate === null) {
                continue;
            }
            $out[(string) $interpolate] = $resolvedValues[$key] ?? $def->default;
        }

        return $out;
    }
}
