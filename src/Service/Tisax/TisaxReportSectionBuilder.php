<?php

declare(strict_types=1);

namespace App\Service\Tisax;

use App\Repository\ComplianceFrameworkRepository;
use App\Service\ModuleConfigurationService;
use App\Service\TenantContext;

/**
 * Builds the TISAX per-tier maturity section of the management-review PDF
 * report for the current tenant. Returns null when there is no tenant, the
 * `tisax` module is inactive for it, the TISAX framework is not loaded or no
 * assessment rows exist yet — the report then omits the section.
 */
final readonly class TisaxReportSectionBuilder
{
    public function __construct(
        private TisaxMaturityAssessmentService $tisaxAssessment,
        private TenantContext $tenantContext,
        private ModuleConfigurationService $moduleConfiguration,
        private ComplianceFrameworkRepository $complianceFrameworkRepository,
    ) {
    }

    /**
     * @return array{average: mixed, assessed: mixed, total: mixed, byTier: mixed, dp: mixed}|null
     */
    public function build(): ?array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null || !$this->moduleConfiguration->isModuleActiveForTenant('tisax', $tenant)) {
            return null;
        }

        $framework = $this->complianceFrameworkRepository->findOneBy(['code' => 'TISAX']);
        if ($framework === null) {
            return null;
        }

        $agg = $this->tisaxAssessment->computeAggregate($framework, $tenant);
        if ($agg['total'] <= 0) {
            return null;
        }

        return [
            'average'  => $agg['average'],
            'assessed' => $agg['assessed'],
            'total'    => $agg['total'],
            'byTier'   => $agg['byTier'],
            'dp'       => $agg['dp'],
        ];
    }
}
