<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard;

use App\Entity\Tenant;
use App\Repository\ControlRepository;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Policy-Wizard — applies the explicit Annex-A applicability map collected
 * by {@see \App\Service\PolicyWizard\Step\RiskClassificationStep} to the
 * corresponding Control entities for the tenant.
 *
 * Hook point: called immediately after RiskClassificationStep validate
 * success inside {@see WizardOrchestrator::processStep} (early-apply on
 * step-validate, not waiting for wizard-complete). This ensures the SoA
 * reflects the user's intent as soon as they advance past Step 4.
 *
 * Authoritativeness: the wizard's explicit submit IS the truth at that
 * moment. If a control was previously set to applicable=false via a manual
 * edit but the user leaves the wizard toggle as "applicable" (=true), the
 * wizard flips it back to true. Rationale: the user has consciously reviewed
 * the full control list and submitted — the resulting map is their deliberate
 * declaration.
 *
 * Controls absent from the map are left untouched (no change = no effect).
 *
 * @see docs/plans/policy-wizard/05-architecture.md §4 (Annex-A applicability)
 */
final class AnnexAApplicabilityApplier implements AnnexAApplicabilityApplierInterface
{
    public function __construct(
        private readonly ControlRepository $controlRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly ?AuditLogger $auditLogger = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Iterate the `[controlRef => bool]` map and flip Control.applicable for
     * every entry that resolves to a persisted Control in the tenant.
     *
     * @param array<string, bool> $applicabilityMap  Keys are ISO 27001 control
     *                                               IDs as stored in
     *                                               Control.controlId ("5.1",
     *                                               "8.12", …). Values are
     *                                               the desired applicable state.
     * @return array{updated: int, not_found: int}   Stats for the caller/logs.
     */
    public function applyToTenant(Tenant $tenant, array $applicabilityMap): array
    {
        $updated = 0;
        $notFound = 0;

        foreach ($applicabilityMap as $controlRef => $desiredApplicable) {
            if (!is_string($controlRef) || $controlRef === '') {
                continue;
            }

            $control = $this->controlRepository->findByControlIdAndTenant($controlRef, $tenant);

            if ($control === null) {
                ++$notFound;
                $this->logger->debug('AnnexAApplicabilityApplier: control not found', [
                    'controlRef' => $controlRef,
                    'tenant_id' => $tenant->getId(),
                ]);
                continue;
            }

            $previous = $control->isApplicable();

            if ($previous === $desiredApplicable) {
                // No change — skip persist + audit overhead.
                continue;
            }

            $control->setApplicable($desiredApplicable);
            ++$updated;

            if ($this->auditLogger !== null) {
                $this->auditLogger->logUpdate(
                    entityType: 'Control',
                    entityId: $control->getId(),
                    oldValues: ['applicable' => $previous],
                    newValues: ['applicable' => $desiredApplicable],
                    description: sprintf(
                        'Policy-Wizard RiskClassificationStep: Control %s applicable set to %s for tenant %d',
                        $controlRef,
                        $desiredApplicable ? 'true' : 'false',
                        (int) $tenant->getId(),
                    ),
                );
            }
        }

        $this->logger->info('AnnexAApplicabilityApplier finished', [
            'tenant_id' => $tenant->getId(),
            'updated' => $updated,
            'not_found' => $notFound,
        ]);

        return ['updated' => $updated, 'not_found' => $notFound];
    }
}
