<?php

declare(strict_types=1);

namespace App\Service\ComplianceWizard\Check\PolicyWizard\Privacy;

use App\Entity\Tenant;
use App\Repository\DocumentRepository;
use App\Service\ComplianceWizard\Check\PolicyWizard\PolicyWizardCheckInterface;
use App\Service\ComplianceWizard\Check\PolicyWizard\PolicyWizardCheckResult;

/**
 * W6-D / GDPR Art. 5 + Art. 24 — confirms an approved Privacy /
 * Data-Protection Policy exists for the tenant.
 *
 * Evidence: at least one published-or-approved {@see \App\Entity\Document}
 * generated from the {@see \App\Command\SeedPrivacyPolicyTemplatesCommand}
 * top-level template (`standard='gdpr'`, `topic='privacy_policy'`).
 *
 * Reference: `docs/plans/policy-wizard/06-dpo-input.md` §2.1 — top-level
 * Datenschutzleitlinie. Maps to ISO 27701 Cl. 5.1 + 5.2 (leadership +
 * privacy policy declaration).
 */
final class PrivacyPolicyPresentCheck implements PolicyWizardCheckInterface
{
    public const CHECK_ID = 'privacy_policy_present';
    private const STANDARD = 'gdpr';
    private const TOPIC = 'privacy_policy';

    public function __construct(
        private readonly DocumentRepository $documentRepository,
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

        $count = (int) $this->documentRepository->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->innerJoin('d.generatedFromTemplate', 't')
            ->where('d.tenant = :tenant')
            ->andWhere('d.status = :approved')
            ->andWhere('d.isArchived = false')
            ->andWhere('t.standard = :standard')
            ->andWhere('t.topic = :topic')
            ->setParameter('tenant', $tenant)
            ->setParameter('approved', 'approved')
            ->setParameter('standard', self::STANDARD)
            ->setParameter('topic', self::TOPIC)
            ->getQuery()
            ->getSingleScalarResult();

        if ($count > 0) {
            return new PolicyWizardCheckResult(
                checkId: self::CHECK_ID,
                score: 100.0,
                passed: true,
                details: ['approved_documents' => $count],
            );
        }

        return new PolicyWizardCheckResult(
            checkId: self::CHECK_ID,
            score: 0.0,
            passed: false,
            details: ['approved_documents' => 0],
            gap: [
                'title' => sprintf('compliance_check.%s.fail_message', self::CHECK_ID),
                'priority' => 'critical',
                'route' => 'app_policy_wizard_index',
                'translation_domain' => 'policy_wizard',
            ],
        );
    }
}
