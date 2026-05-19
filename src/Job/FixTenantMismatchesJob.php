<?php

declare(strict_types=1);

namespace App\Job;

use App\Repository\IncidentRepository;
use App\Repository\RiskRepository;
use App\Service\AuditLogger;
use App\Service\DataIntegrityService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Async admin job: resolve cross-entity tenant mismatches by aligning the
 * child entity's tenant to its parent (Asset).
 *
 * Extracted from DataRepairController::fixTenantMismatches() to avoid PHP-FPM
 * 30s timeout when the broken-references list is large.
 *
 * Args:
 *   reason (string) — operator's audit-log reason (>= 20 chars enforced by controller)
 *
 * ISB MINOR-5 / A.5.3 — judgement call: every reassignment is audit-logged
 * with the before/after tenant.
 */
final class FixTenantMismatchesJob implements AsyncJobInterface
{
    public function __construct(
        private readonly DataIntegrityService $dataIntegrityService,
        private readonly RiskRepository $riskRepository,
        private readonly IncidentRepository $incidentRepository,
        private readonly AuditLogger $auditLogger,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function run(JobContext $ctx): void
    {
        $reason = (string) $ctx->arg('reason', '');
        if ($reason === '') {
            // @intentional-assertion: programmer error — required job arg missing
            throw new \RuntimeException('Missing required arg "reason" in FixTenantMismatchesJob.');
        }

        $ctx->message('Scanning broken references…');

        $filters = $this->entityManager->getFilters();
        $wasEnabled = $filters->isEnabled('tenant_filter');
        if ($wasEnabled) {
            $filters->disable('tenant_filter');
        }

        $fixedCount = 0;

        try {
            $brokenReferences = $this->dataIntegrityService->findBrokenReferences();
            $total = count($brokenReferences);

            if ($total === 0) {
                $ctx->progress(0, 0, 'No tenant mismatches found.');
                return;
            }

            $processed = 0;
            foreach ($brokenReferences as $ref) {
                $processed++;

                if ($ref['type'] === 'risk_asset_tenant_mismatch') {
                    $risk = $this->riskRepository->find($ref['entity_id']);
                    $asset = $risk?->getAsset();
                    if ($risk && $asset && $asset->getTenant()) {
                        $previousTenant = $risk->getTenant();
                        $newTenant = $asset->getTenant();
                        $risk->setTenant($newTenant);
                        $this->auditLogger->logCustom(
                            'admin.data_repair.tenant_mismatch_fixed',
                            'Risk',
                            (int) $risk->getId(),
                            ['tenant_id' => $previousTenant?->getId()],
                            [
                                'tenant_id' => $newTenant->getId(),
                                'tenant_name' => $newTenant->getName(),
                                'aligned_to' => 'Asset#' . (int) $asset->getId(),
                                'reason' => $reason,
                            ],
                            sprintf(
                                'Risk#%d tenant aligned to Asset#%d owner (tenant %d -> %d): %s (async job)',
                                (int) $risk->getId(),
                                (int) $asset->getId(),
                                (int) ($previousTenant?->getId() ?? 0),
                                (int) $newTenant->getId(),
                                $reason,
                            ),
                        );
                        $fixedCount++;
                    }
                }

                if ($ref['type'] === 'incident_asset_tenant_mismatch') {
                    $incident = $this->incidentRepository->find($ref['entity_id']);
                    if ($incident && $incident->getAffectedAssets()->count() > 0) {
                        $firstAsset = $incident->getAffectedAssets()->first();
                        if ($firstAsset && $firstAsset->getTenant()) {
                            $previousTenant = $incident->getTenant();
                            $newTenant = $firstAsset->getTenant();
                            $incident->setTenant($newTenant);
                            $this->auditLogger->logCustom(
                                'admin.data_repair.tenant_mismatch_fixed',
                                'Incident',
                                (int) $incident->getId(),
                                ['tenant_id' => $previousTenant?->getId()],
                                [
                                    'tenant_id' => $newTenant->getId(),
                                    'tenant_name' => $newTenant->getName(),
                                    'aligned_to' => 'Asset#' . (int) $firstAsset->getId(),
                                    'reason' => $reason,
                                ],
                                sprintf(
                                    'Incident#%d tenant aligned to first affected Asset#%d owner (tenant %d -> %d): %s (async job)',
                                    (int) $incident->getId(),
                                    (int) $firstAsset->getId(),
                                    (int) ($previousTenant?->getId() ?? 0),
                                    (int) $newTenant->getId(),
                                    $reason,
                                ),
                            );
                            $fixedCount++;
                        }
                    }
                }

                $ctx->progress($processed, $total, sprintf('Fixed %d mismatches so far…', $fixedCount));
            }

            if ($fixedCount > 0) {
                $this->entityManager->flush();
            }
        } finally {
            if ($wasEnabled && $this->entityManager->isOpen()) {
                $this->entityManager->getFilters()->enable('tenant_filter');
            }
        }

        $ctx->progress(
            $fixedCount,
            $fixedCount,
            sprintf('Done. %d tenant mismatch(es) resolved.', $fixedCount),
        );
    }
}
