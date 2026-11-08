<?php

declare(strict_types=1);

namespace App\Service\ComplianceWizard;

/**
 * The `clause_coverage` compliance-wizard check: a clause that is fulfilled
 * when the clauses it stands for are (ISO 27001 4.4 "establish, implement,
 * maintain and continually improve the ISMS" = clauses 4–10 are in place).
 *
 * `checks` holds the data checks of those clauses (the category provider
 * copies them in); each one counts once it reaches `min_score` (default 60).
 * Score = share of checks that reach it. Checks whose module is off are left
 * out, so an organisation without audits is not judged on audits.
 */
final class ClauseCoverageCheck
{
    public const float DEFAULT_MIN_SCORE = 60.0;

    /**
     * @param array<string, mixed> $check config keys `checks` (key => check definition), `min_score`
     * @param callable(array<string, mixed>): ?array{score: float|int} $run runs one sub-check, null = not a data check
     * @param callable(string): bool $moduleActive
     * @return array{score: float, details: array<string, mixed>, gap: array<string, mixed>|null}
     */
    public function check(array $check, callable $run, callable $moduleActive): array
    {
        $minScore = (float) ($check['min_score'] ?? self::DEFAULT_MIN_SCORE);
        $total = 0;
        $reached = 0;
        $open = [];
        foreach ((array) ($check['checks'] ?? []) as $key => $sub) {
            if (!is_array($sub) || (isset($sub['module']) && !$moduleActive((string) $sub['module']))) {
                continue;
            }
            $result = $run($sub);
            if ($result === null) {
                continue;
            }
            $total++;
            if ((float) $result['score'] >= $minScore) {
                $reached++;
            } else {
                $open[] = (string) ($sub['clause'] ?? $key);
            }
        }

        if ($total === 0) {
            return NoDataResult::build('wizard.gap.no_clause_checks', $check['route'] ?? 'app_compliance_wizard_index', $check, [
                'type' => 'clause_coverage',
                'total' => 0,
            ]);
        }

        $score = round($reached / $total * 100, 1);
        $open = array_values(array_unique($open));
        sort($open, SORT_NATURAL);
        $gap = null;
        if ($score < 100.0) {
            $gap = [
                'title' => 'wizard.gap.clauses_open',
                'description' => 'wizard.gap.clauses_open_desc',
                'description_params' => [
                    '%open%' => count($open),
                    '%clauses%' => implode(', ', $open),
                    '%min%' => (int) $minScore,
                ],
                'priority' => $check['priority'] ?? 'high',
                'route' => $check['route'] ?? null,
            ];
        }

        return [
            'score' => $score,
            'details' => [
                'type' => 'clause_coverage',
                'total' => $total,
                'reached' => $reached,
                'min_score' => $minScore,
                'open_clauses' => $open,
            ],
            'gap' => $gap,
        ];
    }
}
