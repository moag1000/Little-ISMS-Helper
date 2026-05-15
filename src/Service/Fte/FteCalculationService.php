<?php

declare(strict_types=1);

namespace App\Service\Fte;

use App\Entity\Fte\FteCalibrationConstant;
use App\Entity\Fte\FteTrackingMetric;
use App\Entity\Tenant;
use App\Repository\Fte\FteCalibrationConstantRepository;
use App\Service\AuditLogger;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * F11 FTE-Tracking — savings calculation formulas.
 *
 * All "actual minutes" values reflect the tool-assisted workflow time
 * (a Bulk-Import wizard commit takes roughly 1 min regardless of row count;
 * SSO JIT is essentially zero-touch). Manual values use per-tenant calibration.
 */
class FteCalculationService
{
    /** Fixed overhead of one Bulk-Import wizard session in minutes. */
    private const int BULK_IMPORT_ACTUAL_MINUTES = 1;

    /** Fixed overhead of one SSO JIT provisioning event in minutes. */
    private const int SSO_JIT_ACTUAL_MINUTES = 0;

    /** Fixed overhead of one evidence-reuse attach in minutes. */
    private const int EVIDENCE_REUSE_ACTUAL_MINUTES = 1;

    public function __construct(
        private readonly FteCalibrationConstantRepository $calibrationRepo,
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    /**
     * Calculate minutes saved by JIT-provisioning $newUsers users via SSO
     * instead of manual HR → IT ticket workflow.
     */
    public function calculateSsoJitSavings(int $newUsers, Tenant $tenant): int
    {
        $manualMinutesEach = $this->calibrationRepo->getMinutesFor(
            $tenant,
            FteCalibrationConstant::OP_MANUAL_USER_PROVISIONING
        );

        $manual = (int) round($newUsers * $manualMinutesEach);
        $actual = $newUsers * self::SSO_JIT_ACTUAL_MINUTES;

        return max(0, $manual - $actual);
    }

    /**
     * Calculate minutes saved by bulk-importing $rows rows of $entityType
     * vs. manual data entry for each record.
     */
    public function calculateBulkImportSavings(int $rows, string $entityType, Tenant $tenant): int
    {
        $opType = match (strtolower($entityType)) {
            'asset' => FteCalibrationConstant::OP_MANUAL_ASSET_CREATION,
            'risk' => FteCalibrationConstant::OP_MANUAL_RISK_CREATION,
            'control' => FteCalibrationConstant::OP_MANUAL_CONTROL_MAPPING,
            'business_process', 'businessprocess' => FteCalibrationConstant::OP_MANUAL_BUSINESS_PROCESS_CREATION,
            default => FteCalibrationConstant::OP_MANUAL_ASSET_CREATION,
        };

        $manualMinutesEach = $this->calibrationRepo->getMinutesFor($tenant, $opType);
        $manual = (int) round($rows * $manualMinutesEach);
        $actual = self::BULK_IMPORT_ACTUAL_MINUTES;

        return max(0, $manual - $actual);
    }

    /**
     * Calculate minutes saved by reusing one evidence document across
     * $frameworkCount compliance frameworks vs. maintaining each separately.
     */
    public function calculateEvidenceReuseSavings(int $reuseCount, int $frameworkCount, Tenant $tenant): int
    {
        if ($frameworkCount < 2) {
            // No cross-framework reuse savings with < 2 frameworks
            return 0;
        }

        $manualMinutesPerFramework = $this->calibrationRepo->getMinutesFor(
            $tenant,
            FteCalibrationConstant::OP_SINGLE_FRAMEWORK_EVIDENCE_MAINTENANCE
        );

        // Savings = (frameworkCount - 1) * manualMinutes * reuseCount
        // because with tool reuse you only maintain once instead of once per framework
        $manual = (int) round($reuseCount * $frameworkCount * $manualMinutesPerFramework);
        $actual = (int) round($reuseCount * $manualMinutesPerFramework) + ($reuseCount * self::EVIDENCE_REUSE_ACTUAL_MINUTES);

        return max(0, $manual - $actual);
    }

    /**
     * Persist a FteTrackingMetric row and emit audit entry.
     */
    public function recordMetric(
        string $source,
        string $entityType,
        ?int $entityId,
        int $manualMinutes,
        int $actualMinutes,
        Tenant $tenant,
        array $metadata = [],
    ): FteTrackingMetric {
        $savings = max(0, $manualMinutes - $actualMinutes);

        $metric = new FteTrackingMetric();
        $metric->setTenant($tenant);
        $metric->setSource($source);
        $metric->setEntityType($entityType);
        $metric->setEntityId($entityId);
        $metric->setManualMinutesEstimate($manualMinutes);
        $metric->setActualMinutesEstimate($actualMinutes);
        $metric->setSavingsMinutes($savings);
        $metric->setRecordedAt(new DateTimeImmutable());
        $metric->setMetadata($metadata !== [] ? $metadata : null);

        $this->em->persist($metric);
        $this->em->flush();

        $this->auditLogger->logCustom(
            AuditLogger::ACTION_FTE_METRIC_RECORDED,
            'FteTrackingMetric',
            $metric->getId(),
            null,
            ['source' => $source, 'savings_minutes' => $savings, 'entity_type' => $entityType],
            sprintf('FTE metric recorded: %s saved %d min', $source, $savings)
        );

        return $metric;
    }
}
