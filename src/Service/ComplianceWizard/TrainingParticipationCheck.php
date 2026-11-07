<?php

declare(strict_types=1);

namespace App\Service\ComplianceWizard;

use App\Entity\Tenant;
use App\Entity\TrainingParticipation;
use App\Repository\TrainingParticipationRepository;

/**
 * The `training_participation` compliance-wizard check: share of assigned
 * people who completed their training (ISO 27001 A.6.3 awareness, 7.3).
 * Waived assignments do not count; no assignment at all is "no data".
 */
final class TrainingParticipationCheck
{
    public function __construct(
        private readonly TrainingParticipationRepository $participations,
    ) {
    }

    /**
     * @param array<string, mixed> $check
     * @return array{score: float, details: array<string, mixed>, gap: array<string, mixed>|null}
     */
    public function check(array $check, ?Tenant $tenant): array
    {
        $counts = $tenant instanceof Tenant ? $this->participations->countByStatus($tenant) : [];
        $completed = $counts[TrainingParticipation::STATUS_COMPLETED] ?? 0;
        $total = array_sum($counts) - ($counts[TrainingParticipation::STATUS_WAIVED] ?? 0);

        if ($total <= 0) {
            return NoDataResult::build('wizard.gap.no_training_participation', 'app_training_index', $check, [
                'type' => 'training_participation',
                'total' => 0,
            ]);
        }

        $score = round($completed / $total * 100, 1);
        $gap = null;
        if ($score < 100.0) {
            $gap = [
                'title' => 'wizard.gap.training_participation_open',
                'description' => 'wizard.gap.training_participation_open_desc',
                'description_params' => ['%completed%' => $completed, '%total%' => $total],
                'priority' => $check['priority'] ?? 'medium',
                'route' => $check['route'] ?? 'app_training_index',
            ];
        }

        return [
            'score' => $score,
            'details' => ['type' => 'training_participation', 'total' => $total, 'completed' => $completed],
            'gap' => $gap,
        ];
    }
}
