<?php

declare(strict_types=1);

namespace App\Service\ComplianceWizard;

use App\Entity\Tenant;
use App\Entity\TrainingParticipation;
use App\Entity\User;
use App\Repository\TrainingParticipationRepository;
use App\Service\Responsibility\ResponsibilityResolver;
use DateTimeImmutable;

/**
 * The `training_participation` compliance-wizard check: share of assigned
 * people who completed their training (ISO 27001 A.6.3 awareness, 7.3).
 * Waived assignments do not count; no assignment at all is "no data".
 *
 * With `roles` (and `months`, default 12) it counts people instead: the share
 * of the users holding one of the roles who completed a training within the
 * period — NIS-2 Art. 20 (2) asks this of every member of top management.
 */
final class TrainingParticipationCheck
{
    public function __construct(
        private readonly TrainingParticipationRepository $participations,
        private readonly ResponsibilityResolver $responsibility,
    ) {
    }

    /**
     * @param array<string, mixed> $check
     * @return array{score: float, details: array<string, mixed>, gap: array<string, mixed>|null}
     */
    public function check(array $check, ?Tenant $tenant): array
    {
        if (isset($check['roles']) && $tenant instanceof Tenant) {
            return $this->checkPeople($check, $tenant);
        }

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

    /**
     * @param array<string, mixed> $check
     * @return array{score: float, details: array<string, mixed>, gap: array<string, mixed>|null}
     */
    private function checkPeople(array $check, Tenant $tenant): array
    {
        /** @var list<string> $roles */
        $roles = (array) $check['roles'];
        $people = $this->responsibility->usersHolding($tenant, ...$roles);
        if ($people === []) {
            return NoDataResult::build('wizard.gap.no_role_holders', 'user_management_index', $check, [
                'type' => 'training_participation',
                'total' => 0,
            ]);
        }

        $months = (int) ($check['months'] ?? 12);
        $trained = $this->participations->lastCompletionByUser(
            $tenant,
            $people,
            new DateTimeImmutable(sprintf('-%d months', $months)),
        );
        $missing = array_values(array_filter($people, static fn (User $u): bool => !isset($trained[(int) $u->getId()])));
        $total = count($people);
        $score = round(($total - count($missing)) / $total * 100, 1);

        $gap = null;
        if ($missing !== []) {
            $gap = [
                'title' => 'wizard.gap.role_training_missing',
                'description' => 'wizard.gap.role_training_missing_desc',
                'description_params' => [
                    '%names%' => implode(', ', array_map(static fn (User $u): string => trim($u->getFullName()) ?: (string) $u->getEmail(), $missing)),
                    '%months%' => $months,
                ],
                'priority' => $check['priority'] ?? 'high',
                'route' => $check['route'] ?? 'app_training_index',
            ];
        }

        return [
            'score' => $score,
            'details' => ['type' => 'training_participation', 'total' => $total, 'completed' => $total - count($missing)],
            'gap' => $gap,
        ];
    }
}
