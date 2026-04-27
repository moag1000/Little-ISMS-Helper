<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AppliedBaseline;
use App\Entity\Asset;
use App\Entity\IndustryBaseline;
use App\Entity\Risk;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\RiskStatus;
use App\Enum\TreatmentStrategy;
use App\Repository\AppliedBaselineRepository;
use App\Repository\AssetRepository;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ControlRepository;
use App\Repository\RiskRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Applies a pre-seeded IndustryBaseline to a Tenant:
 * - creates preset Risks (unless a risk with the same title already exists)
 * - creates preset Assets (unless one with the same name exists)
 * - marks preset Annex-A Controls as applicable
 * - records the application in AppliedBaseline (one per tenant+baseline)
 *
 * Idempotent: running apply() twice has no additional effect.
 * Tenant-strict: all writes are scoped to the passed tenant.
 */
class IndustryBaselineApplier
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AppliedBaselineRepository $appliedRepository,
        private readonly RiskRepository $riskRepository,
        private readonly AssetRepository $assetRepository,
        private readonly ControlRepository $controlRepository,
        private readonly ComplianceFrameworkRepository $frameworkRepository,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    /**
     * @return array{already_applied:bool, risks_created:int, assets_created:int, controls_marked_applicable:int, frameworks_missing:list<string>}
     */
    public function apply(IndustryBaseline $baseline, Tenant $tenant, ?User $actor = null): array
    {
        $existing = $this->appliedRepository->findOneByTenantAndCode($tenant, $baseline->getCode());
        if ($existing !== null) {
            return [
                'already_applied' => true,
                'risks_created' => 0,
                'assets_created' => 0,
                'controls_marked_applicable' => 0,
                'frameworks_missing' => [],
            ];
        }

        $risksCreated = 0;
        foreach ($baseline->getPresetRisks() as $data) {
            $title = (string) ($data['title'] ?? '');
            if ($title === '') {
                continue;
            }
            $existingRisk = $this->riskRepository->findOneBy(['tenant' => $tenant, 'title' => $title]);
            if ($existingRisk !== null) {
                continue;
            }
            $risk = (new Risk())
                ->setTenant($tenant)
                ->setTitle($title)
                ->setCategory((string) ($data['category'] ?? 'operational'))
                ->setDescription((string) ($data['description'] ?? ''))
                ->setProbability((int) ($data['inherent_likelihood'] ?? 3))
                ->setImpact((int) ($data['inherent_impact'] ?? 3))
                ->setTreatmentStrategy(TreatmentStrategy::tryFrom((string) ($data['treatment_strategy'] ?? 'mitigate')) ?? TreatmentStrategy::Mitigate)
                ->setStatus(RiskStatus::Identified);
            if (!empty($data['threat'])) {
                $risk->setThreat((string) $data['threat']);
            }
            if (!empty($data['vulnerability'])) {
                $risk->setVulnerability((string) $data['vulnerability']);
            }
            $this->entityManager->persist($risk);
            $risksCreated++;
        }

        $assetsCreated = 0;
        foreach ($baseline->getPresetAssets() as $data) {
            $name = (string) ($data['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $existingAsset = $this->assetRepository->findOneBy(['tenant' => $tenant, 'name' => $name]);
            if ($existingAsset !== null) {
                continue;
            }
            $asset = (new Asset())
                ->setTenant($tenant)
                ->setName($name)
                ->setAssetType((string) ($data['asset_type'] ?? 'hardware'))
                ->setOwner((string) ($data['owner'] ?? 'ISMS-Team'))
                ->setConfidentialityValue((int) ($data['confidentiality'] ?? 3))
                ->setIntegrityValue((int) ($data['integrity'] ?? 3))
                ->setAvailabilityValue((int) ($data['availability'] ?? 3));
            if (!empty($data['description']) && method_exists($asset, 'setDescription')) {
                $asset->setDescription((string) $data['description']);
            }
            $this->entityManager->persist($asset);
            $assetsCreated++;
        }

        $controlsMarked = 0;
        foreach ($baseline->getPresetApplicableControls() as $controlCode) {
            $control = $this->controlRepository->findOneBy([
                'tenant' => $tenant,
                'controlId' => $controlCode,
            ]);
            if ($control === null) {
                continue;
            }
            if (!$control->isApplicable()) {
                $control->setApplicable(true);
                $controlsMarked++;
            }
        }

        $frameworksMissing = [];
        foreach ($baseline->getRequiredFrameworks() as $code) {
            if ($this->frameworkRepository->findOneBy(['code' => $code]) === null) {
                $frameworksMissing[] = $code;
            }
        }

        $summary = [
            'risks_created' => $risksCreated,
            'assets_created' => $assetsCreated,
            'controls_marked_applicable' => $controlsMarked,
            'required_frameworks' => $baseline->getRequiredFrameworks(),
            'recommended_frameworks' => $baseline->getRecommendedFrameworks(),
            'frameworks_missing_at_apply' => $frameworksMissing,
        ];

        $record = (new AppliedBaseline())
            ->setTenant($tenant)
            ->setBaselineCode($baseline->getCode())
            ->setBaselineVersion($baseline->getVersion())
            ->setAppliedBy($actor)
            ->setCreatedSummary($summary);
        $this->entityManager->persist($record);

        $this->entityManager->flush();

        $this->auditLogger->logCustom(
            'industry_baseline.applied',
            'IndustryBaseline',
            $baseline->getId(),
            null,
            $summary,
            sprintf(
                'Industry baseline %s v%s applied to tenant %s',
                $baseline->getCode(),
                $baseline->getVersion(),
                (string) $tenant->getName(),
            ),
        );

        return [
            'already_applied' => false,
            'risks_created' => $risksCreated,
            'assets_created' => $assetsCreated,
            'controls_marked_applicable' => $controlsMarked,
            'frameworks_missing' => $frameworksMissing,
        ];
    }

    /**
     * Apply the baseline to $root and every direct and transitive
     * subsidiary. Each tenant gets its own AppliedBaseline record and
     * its own preset risks/assets/controls — propagation, not mere
     * reference. Tenants that already have the baseline are left alone
     * (idempotent per-subtree). Phase 9.P1.5.
     *
     * The return array is keyed by tenant code so a caller can render a
     * per-tenant roll-up ("Holding: 12 risks, Tochter-A: 0 (already
     * applied), Tochter-B: 12 risks").
     *
     * @return array<string, array{already_applied:bool, risks_created:int, assets_created:int, controls_marked_applicable:int, frameworks_missing:list<string>}>
     */
    public function applyRecursive(IndustryBaseline $baseline, Tenant $root, ?User $actor = null): array
    {
        $results = [];
        $results[(string) $root->getCode()] = $this->apply($baseline, $root, $actor);

        foreach ($root->getAllSubsidiaries() as $subsidiary) {
            $results[(string) $subsidiary->getCode()] = $this->apply($baseline, $subsidiary, $actor);
        }

        return $results;
    }
}
