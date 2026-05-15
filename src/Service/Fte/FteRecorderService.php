<?php

declare(strict_types=1);

namespace App\Service\Fte;

use App\Entity\Document;
use App\Entity\Fte\FteTrackingMetric;
use App\Entity\Tenant;
use App\Entity\User;
use Psr\Log\LoggerInterface;

/**
 * F11 FTE-Tracking — thin façade called by existing services.
 *
 * Each method maps to one integration point:
 *   - recordSsoJit()       → called by SsoUserProvisioningService after JIT user creation
 *   - recordBulkImport()   → called by BulkImportOrchestrator after commit
 *   - recordEvidenceReuse() → called by DocumentReuseAnalyticsService when reuse is counted
 *
 * Errors in FTE recording must NEVER bubble up to callers; they are logged
 * and swallowed so core functionality is never disrupted by analytics code.
 */
class FteRecorderService
{
    public function __construct(
        private readonly FteCalculationService $calculator,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Record savings from one JIT-provisioned SSO user.
     */
    public function recordSsoJit(User $user): void
    {
        try {
            $tenant = $user->getTenant();
            if ($tenant === null) {
                return;
            }

            $savings = $this->calculator->calculateSsoJitSavings(1, $tenant);
            $this->calculator->recordMetric(
                FteTrackingMetric::SOURCE_SSO_JIT,
                'User',
                $user->getId(),
                $this->calculator->calculateSsoJitSavings(1, $tenant)
                    + 0, // manual = savings (actual = 0)
                0,
                $tenant,
                ['user_email' => $user->getEmail() ?? 'unknown']
            );
        } catch (\Throwable $e) {
            $this->logger->warning('FteRecorderService::recordSsoJit failed: ' . $e->getMessage());
        }
    }

    /**
     * Record savings from a completed bulk-import session.
     */
    public function recordBulkImport(int $rows, string $entityType, Tenant $tenant): void
    {
        try {
            if ($rows <= 0) {
                return;
            }

            $manual = $this->calculator->calculateBulkImportSavings($rows, $entityType, $tenant)
                + 1; // actual = 1 min session time, savings = manual - 1
            $this->calculator->recordMetric(
                FteTrackingMetric::SOURCE_BULK_IMPORT,
                $entityType,
                null,
                $manual,
                1,
                $tenant,
                ['row_count' => $rows]
            );
        } catch (\Throwable $e) {
            $this->logger->warning('FteRecorderService::recordBulkImport failed: ' . $e->getMessage());
        }
    }

    /**
     * Record savings when a document is reused across multiple frameworks.
     */
    public function recordEvidenceReuse(Document $doc, int $reuseCount): void
    {
        try {
            $tenant = $doc->getTenant();
            if ($tenant === null || $reuseCount < 2) {
                return;
            }

            $manual = $this->calculator->calculateEvidenceReuseSavings($reuseCount, $reuseCount, $tenant)
                + ($reuseCount * 1); // actual = 1 min per reuse
            $this->calculator->recordMetric(
                FteTrackingMetric::SOURCE_EVIDENCE_REUSE,
                'Document',
                $doc->getId(),
                $manual,
                $reuseCount,
                $tenant,
                ['reuse_count' => $reuseCount]
            );
        } catch (\Throwable $e) {
            $this->logger->warning('FteRecorderService::recordEvidenceReuse failed: ' . $e->getMessage());
        }
    }
}
