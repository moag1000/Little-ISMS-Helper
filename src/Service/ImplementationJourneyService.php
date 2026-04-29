<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AuditFinding;
use App\Entity\CorrectiveAction;
use App\Entity\Tenant;
use App\Model\JourneyPhase;
use App\Model\JourneyProgress;
use App\Repository\AssetRepository;
use App\Repository\AuditFindingRepository;
use App\Repository\BusinessContinuityPlanRepository;
use App\Repository\ControlRepository;
use App\Repository\CorrectiveActionRepository;
use App\Repository\InternalAuditRepository;
use App\Repository\InterestedPartyRepository;
use App\Repository\ISMSContextRepository;
use App\Repository\ManagementReviewRepository;
use App\Repository\RiskAppetiteRepository;
use App\Repository\RiskRepository;

/**
 * Calculates the ISMS implementation journey progress for a tenant.
 *
 * Maps the 7 core phases of an ISO 27001 implementation to measurable
 * completion criteria drawn from existing entities/repositories.
 */
class ImplementationJourneyService
{
    public function __construct(
        private readonly ISMSContextRepository $ismsContextRepository,
        private readonly InterestedPartyRepository $interestedPartyRepository,
        private readonly AssetRepository $assetRepository,
        private readonly RiskRepository $riskRepository,
        private readonly RiskAppetiteRepository $riskAppetiteRepository,
        private readonly ControlRepository $controlRepository,
        private readonly BusinessContinuityPlanRepository $bcPlanRepository,
        private readonly InternalAuditRepository $internalAuditRepository,
        private readonly ManagementReviewRepository $managementReviewRepository,
        private readonly AuditFindingRepository $auditFindingRepository,
        private readonly CorrectiveActionRepository $correctiveActionRepository,
    ) {
    }

    /**
     * Build the full journey progress snapshot for a given tenant.
     *
     * @param Tenant $tenant          The tenant to evaluate
     * @param array  $dismissedPhases Map of phase-key => dismiss metadata (reserved for AP 2)
     *
     * @return JourneyProgress
     */
    public function getProgress(Tenant $tenant, array $dismissedPhases = []): JourneyProgress
    {
        // --- Collect raw counts / flags per phase ---
        $metrics = $this->collectMetrics($tenant);

        // --- Phase definitions (order matters) ---
        $definitions = $this->getPhaseDefinitions();

        // --- Build JourneyPhase objects ---
        $phases = [];
        $completionMap = []; // key => percent, for prerequisite checks

        foreach ($definitions as $def) {
            $key = $def['key'];
            $percent = $this->calculatePercent($key, $metrics);
            $completionMap[$key] = $percent;

            // Dismissed handling (reserved for AP 2)
            $dismissed = isset($dismissedPhases[$key]);
            $dismissReason = $dismissed ? ($dismissedPhases[$key]['reason'] ?? null) : null;
            $dismissedBy = $dismissed ? ($dismissedPhases[$key]['by'] ?? null) : null;
            $dismissedAt = $dismissed ? ($dismissedPhases[$key]['at'] ?? null) : null;

            // Locked: prerequisite not complete and not dismissed
            $locked = false;
            if ($def['prerequisiteKey'] !== null) {
                $prereqPercent = $completionMap[$def['prerequisiteKey']] ?? 0;
                $prereqDismissed = isset($dismissedPhases[$def['prerequisiteKey']]);
                $locked = $prereqPercent < 100 && !$prereqDismissed;
            }

            $phases[] = new JourneyPhase(
                key: $key,
                labelKey: $def['labelKey'],
                isoRef: $def['isoRef'],
                icon: $def['icon'],
                completionPercent: $percent,
                locked: $locked,
                dismissed: $dismissed,
                dismissReason: $dismissReason,
                dismissedBy: $dismissedBy,
                dismissedAt: $dismissedAt,
                route: $def['route'],
                prerequisiteKey: $def['prerequisiteKey'],
            );
        }

        // --- Overall percent (simple average of all phase percents) ---
        $totalPercent = 0;
        foreach ($phases as $phase) {
            $totalPercent += $phase->dismissed ? 100 : $phase->completionPercent;
        }
        $overallPercent = count($phases) > 0
            ? (int) round($totalPercent / count($phases))
            : 0;

        // --- Current phase index: first non-complete, non-dismissed, non-locked ---
        $currentIndex = 0;
        foreach ($phases as $i => $phase) {
            $status = $phase->getStatus();
            if ($status !== 'complete' && $status !== 'dismissed' && $status !== 'locked') {
                $currentIndex = $i;
                break;
            }
            // If everything is complete, stay on the last phase
            $currentIndex = $i;
        }

        $alvaMood = $this->resolveAlvaMood($overallPercent);

        return new JourneyProgress(
            phases: $phases,
            overallPercent: $overallPercent,
            currentPhaseIndex: $currentIndex,
            alvaMood: $alvaMood,
        );
    }

    // ------------------------------------------------------------------
    //  Phase definitions
    // ------------------------------------------------------------------

    /**
     * @return array<int, array{key: string, labelKey: string, isoRef: string, icon: string, route: string, prerequisiteKey: ?string}>
     */
    private function getPhaseDefinitions(): array
    {
        return [
            [
                'key'             => 'context',
                'labelKey'        => 'journey.phase.context',
                'isoRef'          => '4.1-4.3',
                'icon'            => 'diagram-2',
                'route'           => 'app_context_index',
                'prerequisiteKey' => null,
            ],
            [
                'key'             => 'assets',
                'labelKey'        => 'journey.phase.assets',
                'isoRef'          => '8.1',
                'icon'            => 'hdd-network',
                'route'           => 'app_asset_index',
                'prerequisiteKey' => 'context',
            ],
            [
                'key'             => 'risks',
                'labelKey'        => 'journey.phase.risks',
                'isoRef'          => '6.1',
                'icon'            => 'exclamation-triangle',
                'route'           => 'app_risk_index',
                'prerequisiteKey' => 'assets',
            ],
            [
                'key'             => 'controls',
                'labelKey'        => 'journey.phase.controls',
                'isoRef'          => '6.1.3',
                'icon'            => 'shield-check',
                'route'           => 'app_soa_index',
                'prerequisiteKey' => 'risks',
            ],
            [
                'key'             => 'emergency',
                'labelKey'        => 'journey.phase.emergency',
                'isoRef'          => 'A.5.29-A.5.30',
                'icon'            => 'hospital',
                'route'           => 'app_bcm_index',
                'prerequisiteKey' => 'risks',
            ],
            [
                'key'             => 'evidence',
                'labelKey'        => 'journey.phase.evidence',
                'isoRef'          => '9.2-9.3',
                'icon'            => 'clipboard-check',
                'route'           => 'app_audit_index',
                'prerequisiteKey' => 'controls',
            ],
            [
                'key'             => 'improvement',
                'labelKey'        => 'journey.phase.improvement',
                'isoRef'          => '10.1-10.2',
                'icon'            => 'arrow-repeat',
                'route'           => 'app_corrective_action_index',
                'prerequisiteKey' => 'evidence',
            ],
        ];
    }

    // ------------------------------------------------------------------
    //  Metrics collection
    // ------------------------------------------------------------------

    /**
     * Gather all raw counts / flags needed for phase completion calculations.
     *
     * @return array<string, mixed>
     */
    private function collectMetrics(Tenant $tenant): array
    {
        // Phase 1: Context
        $context = $this->ismsContextRepository->getContextForTenant($tenant);
        $hasScope = $context !== null && $context->getIsmsScope() !== null && $context->getIsmsScope() !== '';
        $interestedPartyCount = count($this->interestedPartyRepository->findBy(['tenant' => $tenant]));

        // Phase 2: Assets
        $assetCount = count($this->assetRepository->findBy(['tenant' => $tenant]));

        // Phase 3: Risks
        $riskCount = count($this->riskRepository->findBy(['tenant' => $tenant]));
        $hasAppetite = count($this->riskAppetiteRepository->findAllActiveForTenant($tenant)) > 0;

        // Phase 4: Controls (applicable = true AND implementationStatus != 'not_started')
        $applicableReviewedCount = $this->countApplicableReviewedControls($tenant);

        // Phase 5: Business Continuity Plans
        $bcPlanCount = count($this->bcPlanRepository->findBy(['tenant' => $tenant]));

        // Phase 6: Evidence
        $auditCount = count($this->internalAuditRepository->findBy(['tenant' => $tenant]));
        $reviewCount = count($this->managementReviewRepository->findBy(['tenant' => $tenant]));

        // Phase 7: Improvement
        $openFindingsCount = count($this->auditFindingRepository->findOpenByTenant($tenant));
        $openCorrectiveCount = count($this->correctiveActionRepository->findBy([
            'tenant' => $tenant,
            'status' => [CorrectiveAction::STATUS_PLANNED, CorrectiveAction::STATUS_IN_PROGRESS],
        ]));

        return [
            'hasScope'                => $hasScope,
            'interestedPartyCount'    => $interestedPartyCount,
            'assetCount'              => $assetCount,
            'riskCount'               => $riskCount,
            'hasAppetite'             => $hasAppetite,
            'applicableReviewedCount' => $applicableReviewedCount,
            'bcPlanCount'             => $bcPlanCount,
            'auditCount'              => $auditCount,
            'reviewCount'             => $reviewCount,
            'openFindingsCount'       => $openFindingsCount,
            'openCorrectiveCount'     => $openCorrectiveCount,
        ];
    }

    /**
     * Count controls that are applicable AND have been reviewed (status != 'not_started').
     */
    private function countApplicableReviewedControls(Tenant $tenant): int
    {
        $allControls = $this->controlRepository->findAllInIsoOrder($tenant);

        $count = 0;
        foreach ($allControls as $control) {
            if ($control->getApplicable() === true
                && $control->getImplementationStatus() !== 'not_started'
            ) {
                ++$count;
            }
        }

        return $count;
    }

    // ------------------------------------------------------------------
    //  Per-phase completion calculation
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $metrics
     */
    private function calculatePercent(string $key, array $metrics): int
    {
        $percent = match ($key) {
            'context' => $this->calcContext($metrics),
            'assets' => $this->calcAssets($metrics),
            'risks' => $this->calcRisks($metrics),
            'controls' => $this->calcControls($metrics),
            'emergency' => $this->calcEmergency($metrics),
            'evidence' => $this->calcEvidence($metrics),
            'improvement' => $this->calcImprovement($metrics),
            default => 0,
        };

        return max(0, min(100, $percent));
    }

    /**
     * Phase 1 -- Context (ISO 4.1-4.3)
     * scope filled = 50 pts, interested parties (min 3) = 50 pts proportional
     *
     * @param array<string, mixed> $m
     */
    private function calcContext(array $m): int
    {
        $scopePart = $m['hasScope'] ? 50 : 0;
        $ipPart = $m['interestedPartyCount'] >= 3
            ? 50
            : (int) round($m['interestedPartyCount'] / 3 * 50);

        return $scopePart + $ipPart;
    }

    /**
     * Phase 2 -- Assets (ISO 8.1)
     * Linear ramp to 20 assets = 100 %
     *
     * @param array<string, mixed> $m
     */
    private function calcAssets(array $m): int
    {
        return (int) min(100, round($m['assetCount'] / 20 * 100));
    }

    /**
     * Phase 3 -- Risks (ISO 6.1)
     * 10 risks = 80 pts + appetite exists = 20 pts
     *
     * @param array<string, mixed> $m
     */
    private function calcRisks(array $m): int
    {
        $riskPart = (int) min(80, round($m['riskCount'] / 10 * 80));
        $appetitePart = $m['hasAppetite'] ? 20 : 0;

        return $riskPart + $appetitePart;
    }

    /**
     * Phase 4 -- Controls / SoA (ISO 6.1.3)
     * 50 applicable & reviewed controls = 100 %
     *
     * @param array<string, mixed> $m
     */
    private function calcControls(array $m): int
    {
        return (int) min(100, round($m['applicableReviewedCount'] / 50 * 100));
    }

    /**
     * Phase 5 -- Emergency / BCM (A.5.29-A.5.30)
     * At least 1 BC plan = 100 %
     *
     * @param array<string, mixed> $m
     */
    private function calcEmergency(array $m): int
    {
        return $m['bcPlanCount'] >= 1 ? 100 : 0;
    }

    /**
     * Phase 6 -- Evidence (ISO 9.2-9.3)
     * 1 internal audit = 50 pts, 1 management review = 50 pts
     *
     * @param array<string, mixed> $m
     */
    private function calcEvidence(array $m): int
    {
        $auditPart = $m['auditCount'] >= 1 ? 50 : 0;
        $reviewPart = $m['reviewCount'] >= 1 ? 50 : 0;

        return $auditPart + $reviewPart;
    }

    /**
     * Phase 7 -- Improvement (ISO 10.1-10.2)
     * 0 open findings/actions = 100 %, each open item costs 10 pts
     *
     * @param array<string, mixed> $m
     */
    private function calcImprovement(array $m): int
    {
        $openItems = $m['openFindingsCount'] + $m['openCorrectiveCount'];

        if ($openItems === 0) {
            return 100;
        }

        return max(0, 100 - $openItems * 10);
    }

    // ------------------------------------------------------------------
    //  Alva mood
    // ------------------------------------------------------------------

    private function resolveAlvaMood(int $overallPercent): string
    {
        return match (true) {
            $overallPercent >= 91 => 'celebrating',
            $overallPercent >= 61 => 'focused',
            $overallPercent >= 21 => 'working',
            default               => 'thinking',
        };
    }
}
