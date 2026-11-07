<?php

declare(strict_types=1);

namespace App\Service\ComplianceWizard;

use App\Entity\Tenant;
use App\Enum\RegisterType;
use App\Repository\IsmsRegisterEntryRepository;
use App\Service\Register\RegisterProposalProvider;

/**
 * The `register_coverage` compliance-wizard check: is the clause answered by
 * the organisation's norm register ({@see \App\Entity\IsmsRegisterEntry})?
 *
 * Per register in `registers`: share of active entries whose review is not
 * overdue; a register without entries scores 0. With `count_proposals: true`
 * every open proposal (a persona role held without its role entry, …) counts
 * as a missing entry, so the role register follows the roles actually assigned
 * (ISO 27001 5.3). Several registers average. No entry in any of them is
 * "no data" — the requirement then stays with its other rules.
 */
final class RegisterCoverageCheck
{
    public function __construct(
        private readonly IsmsRegisterEntryRepository $entries,
        private readonly ?RegisterProposalProvider $proposals = null,
    ) {
    }

    /**
     * @param array<string, mixed> $check config keys `registers` (list of RegisterType values), `count_proposals`
     * @return array{score: float, details: array<string, mixed>, gap: array<string, mixed>|null}
     */
    public function check(array $check, ?Tenant $tenant): array
    {
        $types = array_values(array_filter(array_map(
            static fn (mixed $value): ?RegisterType => is_string($value) ? RegisterType::tryFrom($value) : null,
            (array) ($check['registers'] ?? []),
        )));
        $stats = $tenant instanceof Tenant ? $this->entries->statsByType($tenant) : [];

        $active = 0;
        $overdue = 0;
        $missing = 0;
        $scores = [];
        foreach ($types as $type) {
            $row = $stats[$type->value] ?? ['active' => 0, 'overdue' => 0];
            $open = ($check['count_proposals'] ?? false) === true && $tenant instanceof Tenant && $this->proposals !== null
                ? $this->proposals->countFor($tenant, $type)
                : 0;
            $active += $row['active'];
            $overdue += $row['overdue'];
            $missing += $open;
            $expected = $row['active'] + $open;
            $scores[] = $expected > 0 ? ($row['active'] - $row['overdue']) / $expected * 100 : 0.0;
        }

        $registers = array_map(static fn (RegisterType $type): string => $type->value, $types);
        if ($active === 0) {
            return NoDataResult::build('wizard.gap.no_register_entries', 'app_register_index', $check, [
                'type' => 'register_coverage',
                'total' => 0,
                'registers' => $registers,
            ]);
        }

        $score = round(array_sum($scores) / count($scores), 1);
        $gap = null;
        if ($score < 100.0) {
            $gap = [
                'title' => 'wizard.gap.register_incomplete',
                'description' => 'wizard.gap.register_incomplete_desc',
                'description_params' => ['%overdue%' => $overdue, '%missing%' => $missing],
                'priority' => $check['priority'] ?? 'medium',
                'route' => $check['route'] ?? 'app_register_index',
            ];
        }

        return [
            'score' => $score,
            'details' => [
                'type' => 'register_coverage',
                'total' => $active,
                'overdue' => $overdue,
                'missing' => $missing,
                'registers' => $registers,
            ],
            'gap' => $gap,
        ];
    }
}
