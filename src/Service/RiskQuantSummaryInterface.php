<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Tenant;

/**
 * F46: Contract for retrieving the aggregate Annual Loss Expectancy across
 * all risks for a tenant. Extracted from RiskService so that consumers
 * (BoardReportGenerator) can depend on the interface rather than the
 * concrete final class — enabling straightforward mocking in unit tests.
 */
interface RiskQuantSummaryInterface
{
    /**
     * @return array{total_ale_eur: int, quantified_risk_count: int}
     */
    public function getRiskQuantSummary(Tenant $tenant): array;
}
