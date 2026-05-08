<?php

declare(strict_types=1);

namespace App\Service\ComplianceWizard\Check\PolicyWizard\Bcm;

use App\Entity\Tenant;
use App\Repository\AssetRepository;
use App\Repository\DocumentRepository;
use App\Service\ComplianceWizard\Check\PolicyWizard\PolicyWizardCheckInterface;
use App\Service\ComplianceWizard\Check\PolicyWizard\PolicyWizardCheckResult;

/**
 * W5-C / ISO 22301 Cl. 8.4.5 + BSI 200-4 Kap. 6.2 — confirms the
 * tenant has at least one Recovery Plan document for every crown-jewel
 * asset (very-high CIA).
 *
 * Crown-jewel detection: Assets where `max(C, I, A) >= 4` per the
 * 4-tier scheme used by `Asset::isHighRisk()` (line 613 — "high CIA
 * value assets are considered high-risk"). For tenants on a 3-tier
 * scheme (`max=3` is the highest), no asset crosses the >=4 bar →
 * the check passes vacuously, and is intentionally permissive: the
 * 4-tier crown-jewel signal is opt-in, not opt-out.
 *
 * Document evidence: at least one published/approved Document with
 * `standard='bcm'` and `topic='recovery_plans'` exists for the
 * tenant. Per-asset recovery-plan binding lives in a future BIA-driven
 * mapping that this check intentionally does not enforce yet — the
 * minimum viable BCMS evidence is that a Recovery-Plans artefact
 * exists when crown-jewels are present.
 *
 * Reference: `docs/plans/policy-wizard/04-bcm-input.md` §2.8 + §7.4.
 */
final class BcmRecoveryPlansPresentCheck implements PolicyWizardCheckInterface
{
    public const CHECK_ID = 'bcm_recovery_plans_present';
    private const STANDARD = 'bcm';
    private const TOPIC = 'recovery_plans';

    /** Crown-jewel CIA threshold (max(C,I,A) >= 4 = very-high). */
    public const CROWN_JEWEL_CIA_THRESHOLD = 4;

    public function __construct(
        private readonly DocumentRepository $documentRepository,
        private readonly AssetRepository $assetRepository,
    ) {
    }

    public function getCheckId(): string
    {
        return self::CHECK_ID;
    }

    public function getStandard(): string
    {
        return self::STANDARD;
    }

    public function run(?Tenant $tenant): PolicyWizardCheckResult
    {
        if ($tenant === null) {
            return new PolicyWizardCheckResult(
                checkId: self::CHECK_ID,
                score: 0.0,
                passed: false,
                details: ['reason' => 'no_tenant'],
            );
        }

        $crownJewelCount = (int) $this->assetRepository->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.tenant = :tenant')
            ->andWhere('GREATEST(COALESCE(a.confidentialityValue, 0), COALESCE(a.integrityValue, 0), COALESCE(a.availabilityValue, 0)) >= :threshold')
            ->setParameter('tenant', $tenant)
            ->setParameter('threshold', self::CROWN_JEWEL_CIA_THRESHOLD)
            ->getQuery()
            ->getSingleScalarResult();

        if ($crownJewelCount === 0) {
            // No crown-jewels declared → Cl. 8.4.5 obligation does not
            // crystallise. Pass with neutral evidence trail.
            return new PolicyWizardCheckResult(
                checkId: self::CHECK_ID,
                score: 100.0,
                passed: true,
                details: [
                    'crown_jewel_assets' => 0,
                    'reason' => 'no_crown_jewels_in_scope',
                ],
            );
        }

        $recoveryPlanCount = (int) $this->documentRepository->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->innerJoin('d.generatedFromTemplate', 't')
            ->where('d.tenant = :tenant')
            ->andWhere('d.status IN (:statuses)')
            ->andWhere('d.isArchived = false')
            ->andWhere('t.standard = :standard')
            ->andWhere('t.topic = :topic')
            ->setParameter('tenant', $tenant)
            ->setParameter('statuses', ['published', 'approved'])
            ->setParameter('standard', self::STANDARD)
            ->setParameter('topic', self::TOPIC)
            ->getQuery()
            ->getSingleScalarResult();

        if ($recoveryPlanCount > 0) {
            return new PolicyWizardCheckResult(
                checkId: self::CHECK_ID,
                score: 100.0,
                passed: true,
                details: [
                    'crown_jewel_assets' => $crownJewelCount,
                    'recovery_plans' => $recoveryPlanCount,
                ],
            );
        }

        return new PolicyWizardCheckResult(
            checkId: self::CHECK_ID,
            score: 0.0,
            passed: false,
            details: [
                'crown_jewel_assets' => $crownJewelCount,
                'recovery_plans' => 0,
            ],
            gap: [
                'title' => sprintf('compliance_check.%s.fail_message', self::CHECK_ID),
                'priority' => 'critical',
                'route' => 'app_policy_wizard_index',
                'translation_domain' => 'policy_wizard',
            ],
        );
    }
}
