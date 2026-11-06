<?php

declare(strict_types=1);

namespace App\Service\Kpi;

use App\Entity\Risk;
use App\Entity\Tenant;
use App\Enum\RiskStatus;
use App\Repository\RiskRepository;
use App\Service\TenantRecords;

/**
 * Expected annual loss in euro across a tenant's open risks (UI wave D).
 *
 * Reuses the quantitative fields the risk form already collects (module
 * `risk_quant`: SLE × ARO = ALE, NIST SP 800-30 / ISO 27005). No new fields:
 * a risk without SLE and ARO simply does not count, and `quantified` / `open`
 * tell the reader how much of the register the sum covers.
 *
 * Closed risks drop out; accepted risks stay in, because accepting a risk
 * means the organisation carries the expected loss.
 */
readonly class RiskExposure
{
    public function __construct(
        private RiskRepository $risks,
        private TenantRecords $tenantRecords,
    ) {
    }

    /**
     * @return array{
     *     total: int,
     *     quantified: int,
     *     open: int,
     *     top: list<array{id: int|null, title: string, ale: int, level: int}>
     * }
     */
    public function forTenant(Tenant $tenant, int $top = 5): array
    {
        $open = array_filter(
            $this->tenantRecords->all($this->risks, $tenant),
            static fn (Risk $r): bool => $r->getStatus() !== RiskStatus::Closed,
        );

        $quantified = [];
        foreach ($open as $risk) {
            $ale = $risk->getAnnualLossExpectancy();
            if ($ale !== null) {
                $quantified[] = ['risk' => $risk, 'ale' => $ale];
            }
        }
        usort($quantified, static fn (array $a, array $b): int => $b['ale'] <=> $a['ale']);

        return [
            'total' => array_sum(array_column($quantified, 'ale')),
            'quantified' => count($quantified),
            'open' => count($open),
            'top' => array_map(static fn (array $q): array => [
                'id' => $q['risk']->getId(),
                'title' => (string) $q['risk']->getTitle(),
                'ale' => $q['ale'],
                'level' => (int) $q['risk']->getInherentRiskLevel(),
            ], array_slice($quantified, 0, $top)),
        ];
    }
}
