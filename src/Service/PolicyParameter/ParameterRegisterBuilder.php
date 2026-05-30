<?php

declare(strict_types=1);

namespace App\Service\PolicyParameter;

/**
 * Builds the cross-framework parameter register: one RegisterRow per catalog
 * parameter, carrying the effective value + the strongest applicable framework
 * authority/source among the selected frameworks. Filter on isRegulatory() to
 * get the audit obligation list.
 */
final readonly class ParameterRegisterBuilder
{
    private const array AUTHORITY_RANK = ['regulatory' => 3, 'benchmark' => 2, 'recommended' => 1];

    public function __construct(
        private PolicyParameterCatalog $catalog,
    ) {
    }

    /**
     * @param list<string>         $frameworks
     * @param array<string, mixed> $resolvedValues
     *
     * @return list<RegisterRow>
     */
    public function build(array $frameworks, array $resolvedValues): array
    {
        $rows = [];
        foreach ($this->catalog->all() as $key => $def) {
            $applicable = [];
            $bestAuthority = null;
            $bestSource = null;
            $bestRank = 0;

            foreach ($frameworks as $framework) {
                if ($def->frameworkMin($framework) === null) {
                    continue;
                }
                $applicable[] = $framework;
                $authority = $def->frameworkAuthority($framework);
                $rank = self::AUTHORITY_RANK[$authority] ?? 0;
                if ($rank > $bestRank) {
                    $bestRank = $rank;
                    $bestAuthority = $authority;
                    $bestSource = $def->frameworkConstraints[$framework]['source'] ?? null;
                }
            }

            $rows[] = new RegisterRow(
                paramKey: $key,
                label: $def->labels['de'] ?? $key,
                value: $resolvedValues[$key] ?? $def->default,
                authority: $bestAuthority,
                source: $bestSource,
                isoClauses: $def->isoClauses,
                frameworks: $applicable,
            );
        }

        return $rows;
    }
}
