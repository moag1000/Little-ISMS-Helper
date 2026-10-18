<?php

declare(strict_types=1);

namespace App\Service\ComplianceWizard;

use App\Entity\Tenant;
use App\Repository\AssetRepository;
use App\Repository\BusinessContinuityPlanRepository;
use App\Repository\DocumentRepository;
use App\Repository\InterestedPartyRepository;
use App\Repository\ISMSContextRepository;
use App\Repository\ISMSObjectiveRepository;
use App\Repository\ManagementReviewRepository;
use App\Repository\ProcessingActivityRepository;
use App\Repository\SupplierRepository;
use App\Repository\VulnerabilityRepository;

/**
 * The `entity_presence` compliance-wizard check: is a clause addressed by at
 * least one record of the mapped entity in the tenant?
 *
 * Extracted from CoverageCheckService, which keeps the checks that weigh the
 * content of records; this one only counts tenant rows per registry key.
 */
final class EntityPresenceChecker
{
    public function __construct(
        private readonly ISMSContextRepository $ismsContextRepository,
        private readonly InterestedPartyRepository $interestedPartyRepository,
        private readonly ISMSObjectiveRepository $ismsObjectiveRepository,
        private readonly ManagementReviewRepository $managementReviewRepository,
        private readonly ProcessingActivityRepository $processingActivityRepository,
        private readonly SupplierRepository $supplierRepository,
        private readonly AssetRepository $assetRepository,
        private readonly DocumentRepository $documentRepository,
        private readonly BusinessContinuityPlanRepository $bcPlanRepository,
        private readonly VulnerabilityRepository $vulnerabilityRepository,
    ) {
    }

    /**
     * Generic "is this clause addressed by ≥1 tenant record?" check.
     *
     * Backs the formerly-`manual` ISO/GDPR clauses that DO map to a concrete
     * entity (Cl. 4.1/4.3 → ISMSContext, Cl. 4.2 → InterestedParty, Cl. 6.2 →
     * ISMSObjective, Cl. 9.3 → ManagementReview, GDPR Art. 30 → ProcessingActivity,
     * …). Scores 100 once the tenant has the data, so populated tenants stop
     * seeing false "critical gap" rows.
     *
     * Config keys: `entity` (registry key, required), `min_count` (default 1).
     *
     * @param array<string, mixed> $check
     * @return array{score: float, details: array<string, mixed>, gap: array<string, mixed>|null}
     */
    public function check(array $check, ?Tenant $tenant): array
    {
        $entityKey = (string) ($check['entity'] ?? '');
        $threshold = max(1, (int) ($check['min_count'] ?? 1));
        $count = $tenant === null ? 0 : $this->countTenantEntities($entityKey, $tenant);

        $score = match (true) {
            $count >= $threshold => 100.0,
            $count > 0 => 50.0,
            default => 0.0,
        };

        $details = [
            'type' => 'entity_presence',
            'entity' => $entityKey,
            'count' => $count,
            'threshold' => $threshold,
        ];

        $gap = null;
        if ($score < 100.0) {
            $gap = [
                'title' => $check['name'] ?? 'wizard.check.generic',
                'description' => $check['description'] ?? '',
                'priority' => $check['priority'] ?? 'medium',
                'action' => $check['action'] ?? null,
                'route' => $check['route'] ?? null,
            ];
        }

        return ['score' => $score, 'details' => $details, 'gap' => $gap];
    }

    /**
     * Count tenant-scoped rows for an `entity_presence` registry key.
     * Unknown keys return 0 (check then renders as an open gap, fail-safe).
     */
    private function countTenantEntities(string $entityKey, Tenant $tenant): int
    {
        $repository = match ($entityKey) {
            'isms_context' => $this->ismsContextRepository,
            'interested_party' => $this->interestedPartyRepository,
            'isms_objective' => $this->ismsObjectiveRepository,
            'management_review' => $this->managementReviewRepository,
            'processing_activity' => $this->processingActivityRepository,
            'supplier' => $this->supplierRepository,
            'asset' => $this->assetRepository,
            'document' => $this->documentRepository,
            'bc_plan' => $this->bcPlanRepository,
            'vulnerability' => $this->vulnerabilityRepository,
            default => null,
        };

        if ($repository === null) {
            return 0;
        }

        return $repository->count(['tenant' => $tenant]);
    }
}
