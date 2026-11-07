<?php

declare(strict_types=1);

namespace App\Service\ComplianceWizard;

use App\Entity\ManagementDecision;
use App\Entity\Tenant;
use App\Enum\ManagementDecisionType;
use App\Repository\ManagementDecisionRepository;

/**
 * The `management_decision` compliance-wizard check: has top management
 * decided what the clause reserves for it (ISO 27001 5.1, 5.2, 6.1.2, 6.1.3,
 * 7.1, 9.3.3; NIS-2 Art. 20 (1))? Reads the register of top-management
 * decisions ({@see ManagementDecision}).
 *
 * Per type in `decision_types`: an approval in force scores 100, a decision
 * put before top management 50, an approval past its validity 25 (to be
 * confirmed again), otherwise 0. Several types average. No decision of any
 * listed type is "no data" — the requirement then stays with its other rules.
 */
final class ManagementDecisionCheck
{
    public function __construct(
        private readonly ManagementDecisionRepository $decisions,
    ) {
    }

    /**
     * @param array<string, mixed> $check config key `decision_types` (list of ManagementDecisionType values)
     * @return array{score: float, details: array<string, mixed>, gap: array<string, mixed>|null}
     */
    public function check(array $check, ?Tenant $tenant, ?\DateTimeImmutable $today = null): array
    {
        $today ??= new \DateTimeImmutable('today');
        $types = array_values(array_filter(array_map(
            static fn (mixed $value): ?ManagementDecisionType => is_string($value) ? ManagementDecisionType::tryFrom($value) : null,
            (array) ($check['decision_types'] ?? []),
        )));
        $all = $tenant instanceof Tenant ? $this->decisions->findByTenant($tenant) : [];

        $total = 0;
        $effective = 0;
        $pending = 0;
        $scores = [];
        foreach ($types as $type) {
            $ofType = array_values(array_filter($all, static fn (ManagementDecision $d): bool => $d->getType() === $type));
            $total += count($ofType);
            $score = 0.0;
            foreach ($ofType as $decision) {
                $value = match (true) {
                    $decision->isEffective($today) => 100.0,
                    $decision->isPending() => 50.0,
                    $decision->isExpired($today) => 25.0,
                    default => 0.0,
                };
                $score = max($score, $value);
            }
            $effective += $score === 100.0 ? 1 : 0;
            $pending += $score === 50.0 ? 1 : 0;
            $scores[] = $score;
        }

        $typeValues = array_map(static fn (ManagementDecisionType $type): string => $type->value, $types);
        if ($total === 0) {
            return NoDataResult::build('wizard.gap.no_management_decision', 'app_management_decision_index', $check, [
                'type' => 'management_decision',
                'total' => 0,
                'decision_types' => $typeValues,
            ]);
        }

        $score = round(array_sum($scores) / count($scores), 1);
        $gap = null;
        if ($score < 100.0) {
            $gap = [
                'title' => 'wizard.gap.management_decision_missing',
                'description' => 'wizard.gap.management_decision_missing_desc',
                'description_params' => ['%effective%' => $effective, '%total%' => count($types)],
                'priority' => $check['priority'] ?? 'high',
                'route' => $check['route'] ?? 'app_management_decision_index',
            ];
        }

        return [
            'score' => $score,
            'details' => [
                'type' => 'management_decision',
                'total' => $total,
                'effective' => $effective,
                'pending' => $pending,
                'decision_types' => $typeValues,
            ],
            'gap' => $gap,
        ];
    }
}
