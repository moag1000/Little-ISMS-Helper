<?php

declare(strict_types=1);

namespace App\Service\ComplianceWizard\Check\PolicyWizard\Bsi;

use App\Entity\PolicyTemplate;
use App\Entity\Tenant;
use App\Repository\DocumentRepository;
use App\Repository\TenantPolicySettingRepository;
use App\Service\ComplianceWizard\Check\PolicyWizard\PolicyWizardCheckInterface;
use App\Service\ComplianceWizard\Check\PolicyWizard\PolicyWizardCheckResult;

/**
 * W5-C / BSI 200-2 Kap. 3 (Vorgehensweise) — confirms the tenant's
 * `bsi.tier_filter` policy-setting matches the actual `bsiTier` of
 * generated tenant Documents.
 *
 * Per the BSI methodology a tenant chooses ONE of three tiers
 * (Basis-/Standard-/Kern-Absicherung). The chosen tier filters which
 * templates the wizard offers. This check guards against drift: e.g.
 * a tenant in `tier_filter=basis_only` MUST NOT have any `kern` or
 * `standard` documents in the wizard-generated set, otherwise the
 * audit-evidence is inconsistent with the declared methodology.
 *
 * Setting key:
 *   - `bsi.tier_filter` ∈ {`basis_only`, `basis_standard`, `all`}
 *     - `basis_only`     → only Basis-tier Documents allowed
 *     - `basis_standard` → Basis + Standard, NO Kern
 *     - `all`            → Basis + Standard + Kern (vacuously satisfied)
 *
 * When the setting is missing the check passes neutrally (no
 * declaration → no consistency claim to violate).
 */
final class BsiTierConsistencyCheck implements PolicyWizardCheckInterface
{
    public const CHECK_ID = 'bsi_tier_consistency';
    public const SETTING_KEY_TIER_FILTER = 'bsi.tier_filter';
    private const STANDARD = 'bsi';

    public const TIER_FILTER_BASIS_ONLY = 'basis_only';
    public const TIER_FILTER_BASIS_STANDARD = 'basis_standard';
    public const TIER_FILTER_ALL = 'all';

    public function __construct(
        private readonly DocumentRepository $documentRepository,
        private readonly TenantPolicySettingRepository $tenantPolicySettingRepository,
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

        $setting = $this->tenantPolicySettingRepository->findOneByTenantAndKey(
            $tenant,
            self::SETTING_KEY_TIER_FILTER,
        );
        $tierFilter = $setting?->getValue();
        $tierFilter = is_string($tierFilter) ? $tierFilter : null;

        if ($tierFilter === null || $tierFilter === self::TIER_FILTER_ALL) {
            // No declared filter → no consistency claim to verify.
            return new PolicyWizardCheckResult(
                checkId: self::CHECK_ID,
                score: 100.0,
                passed: true,
                details: [
                    'tier_filter' => $tierFilter ?? 'undeclared',
                    'reason' => 'vacuously_consistent',
                ],
            );
        }

        $forbiddenTiers = match ($tierFilter) {
            self::TIER_FILTER_BASIS_ONLY => [PolicyTemplate::BSI_TIER_STANDARD, PolicyTemplate::BSI_TIER_KERN],
            self::TIER_FILTER_BASIS_STANDARD => [PolicyTemplate::BSI_TIER_KERN],
            default => [],
        };

        if ($forbiddenTiers === []) {
            return new PolicyWizardCheckResult(
                checkId: self::CHECK_ID,
                score: 100.0,
                passed: true,
                details: [
                    'tier_filter' => $tierFilter,
                    'reason' => 'unknown_filter_passes_neutral',
                ],
            );
        }

        $offendingCount = (int) $this->documentRepository->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->innerJoin('d.generatedFromTemplate', 't')
            ->where('d.tenant = :tenant')
            ->andWhere('d.isArchived = false')
            ->andWhere('t.standard = :standard')
            ->andWhere('t.bsiTier IN (:forbidden)')
            ->setParameter('tenant', $tenant)
            ->setParameter('standard', self::STANDARD)
            ->setParameter('forbidden', $forbiddenTiers)
            ->getQuery()
            ->getSingleScalarResult();

        if ($offendingCount === 0) {
            return new PolicyWizardCheckResult(
                checkId: self::CHECK_ID,
                score: 100.0,
                passed: true,
                details: [
                    'tier_filter' => $tierFilter,
                    'forbidden_tier_documents' => 0,
                ],
            );
        }

        return new PolicyWizardCheckResult(
            checkId: self::CHECK_ID,
            score: 0.0,
            passed: false,
            details: [
                'tier_filter' => $tierFilter,
                'forbidden_tiers' => $forbiddenTiers,
                'forbidden_tier_documents' => $offendingCount,
            ],
            gap: [
                'title' => sprintf('compliance_check.%s.fail_message', self::CHECK_ID),
                'priority' => 'high',
                'route' => 'app_policy_wizard_index',
                'translation_domain' => 'policy_wizard',
            ],
        );
    }
}
