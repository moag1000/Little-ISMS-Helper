<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard;

use App\Entity\Tenant;

/**
 * Contract for applying the Annex-A applicability map (collected by
 * RiskClassificationStep) to the tenant's Control entities.
 *
 * Extracted as an interface so orchestrator tests can mock the dependency
 * without needing to stub the final concrete implementation.
 */
interface AnnexAApplicabilityApplierInterface
{
    /**
     * @param array<string, bool> $applicabilityMap controlRef => bool
     * @return array{updated: int, not_found: int}
     */
    public function applyToTenant(Tenant $tenant, array $applicabilityMap): array;
}
