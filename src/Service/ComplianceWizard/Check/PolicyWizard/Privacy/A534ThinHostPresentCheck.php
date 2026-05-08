<?php

declare(strict_types=1);

namespace App\Service\ComplianceWizard\Check\PolicyWizard\Privacy;

use App\Entity\ComplianceFramework;
use App\Entity\Tenant;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\DocumentRepository;
use App\Service\ComplianceWizard\Check\PolicyWizard\PolicyWizardCheckInterface;
use App\Service\ComplianceWizard\Check\PolicyWizard\PolicyWizardCheckResult;

/**
 * W6-D / ISO 27001 A.5.34 + GDPR cross-reference — confirms the thin
 * A.5.34 host Document exists when the tenant has BOTH ISO 27001 AND
 * GDPR scope active.
 *
 * Per `docs/plans/policy-wizard/06-dpo-input.md` Decision Matrix v2 row
 * 18, ISO 27001 A.5.34 (Privacy & PII Protection) gets a thin host
 * policy whose sole purpose is to cross-reference the 5 standalone
 * privacy artefacts (Privacy Policy, RoPA Methodology, DPIA Methodology,
 * DSR Procedure, Data Breach Notification Procedure). Without it the
 * SoA cell for A.5.34 has no evidence-link target.
 *
 * Trigger: BOTH conditions must hold to enforce the check —
 *  • ISO 27001 ComplianceFramework active (`code='ISO27001'` + isActive=true)
 *  • GDPR ComplianceFramework active (`code='GDPR'` + isActive=true)
 *
 * For tenants outside both scopes simultaneously the check is vacuously
 * satisfied — there is no A.5.34 cell to populate.
 *
 * Evidence: at least one published-or-approved {@see \App\Entity\Document}
 * generated from the {@see \App\Command\SeedPrivacyPolicyTemplatesCommand}
 * thin-host template (`standard='gdpr'`, `topic='iso_a534_thin_host'`).
 */
final class A534ThinHostPresentCheck implements PolicyWizardCheckInterface
{
    public const CHECK_ID = 'a534_thin_host_present';
    private const STANDARD = 'gdpr';
    private const TOPIC = 'iso_a534_thin_host';

    public function __construct(
        private readonly DocumentRepository $documentRepository,
        private readonly ComplianceFrameworkRepository $frameworkRepository,
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

        if (!$this->isIsoActive() || !$this->isGdprActive()) {
            // Outside dual scope — vacuously satisfied.
            return new PolicyWizardCheckResult(
                checkId: self::CHECK_ID,
                score: 100.0,
                passed: true,
                details: [
                    'iso_active' => $this->isIsoActive(),
                    'gdpr_active' => $this->isGdprActive(),
                    'reason' => 'thin_host_not_required_outside_dual_scope',
                ],
            );
        }

        $count = (int) $this->documentRepository->createQueryBuilder('d')
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

        if ($count > 0) {
            return new PolicyWizardCheckResult(
                checkId: self::CHECK_ID,
                score: 100.0,
                passed: true,
                details: ['thin_host_documents' => $count],
            );
        }

        return new PolicyWizardCheckResult(
            checkId: self::CHECK_ID,
            score: 0.0,
            passed: false,
            details: ['thin_host_documents' => 0],
            gap: [
                'title' => sprintf('compliance_check.%s.fail_message', self::CHECK_ID),
                'priority' => 'high',
                'route' => 'app_policy_wizard_index',
                'translation_domain' => 'policy_wizard',
            ],
        );
    }

    private function isIsoActive(): bool
    {
        $framework = $this->frameworkRepository->findOneBy(['code' => 'ISO27001']);
        return $framework instanceof ComplianceFramework && $framework->isActive() === true;
    }

    private function isGdprActive(): bool
    {
        $framework = $this->frameworkRepository->findOneBy(['code' => 'GDPR']);
        return $framework instanceof ComplianceFramework && $framework->isActive() === true;
    }
}
