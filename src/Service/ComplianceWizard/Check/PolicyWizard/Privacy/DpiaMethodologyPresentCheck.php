<?php

declare(strict_types=1);

namespace App\Service\ComplianceWizard\Check\PolicyWizard\Privacy;

use App\Entity\Tenant;
use App\Enum\DocumentStatus;
use App\Repository\DocumentRepository;
use App\Service\ComplianceWizard\Check\PolicyWizard\PolicyWizardCheckInterface;
use App\Service\ComplianceWizard\Check\PolicyWizard\PolicyWizardCheckResult;

/**
 * W6-D / GDPR Art. 35 + Art. 36 — confirms a published Data-Protection
 * Impact-Assessment (DPIA) methodology document exists for the tenant.
 *
 * Evidence: at least one published-or-approved {@see \App\Entity\Document}
 * generated from the {@see \App\Command\SeedPrivacyPolicyTemplatesCommand}
 * DPIA methodology template (`standard='gdpr'`, `topic='dpia_methodology'`).
 *
 * Reference: `docs/plans/policy-wizard/06-dpo-input.md` §2.3. Maps to ISO
 * 27701 Cl. 6.2 (privacy risk treatment) + 7.2.5 (DPIA).
 */
final class DpiaMethodologyPresentCheck implements PolicyWizardCheckInterface
{
    public const CHECK_ID = 'dpia_methodology_present';
    private const STANDARD = 'gdpr';
    private const TOPIC = 'dpia_methodology';

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
            ->andWhere('d.status IN (:statuses)')
            ->andWhere('d.isArchived = false')
            ->andWhere('t.standard = :standard')
            ->andWhere('t.topic = :topic')
            ->setParameter('tenant', $tenant)
            ->setParameter('statuses', [DocumentStatus::Published->value, DocumentStatus::Approved->value])
            ->setParameter('standard', self::STANDARD)
            ->setParameter('topic', self::TOPIC)
            ->getQuery()
            ->getSingleScalarResult();

        if ($count > 0) {
            return new PolicyWizardCheckResult(
                checkId: self::CHECK_ID,
                score: 100.0,
                passed: true,
                details: ['published_documents' => $count],
            );
        }

        return new PolicyWizardCheckResult(
            checkId: self::CHECK_ID,
            score: 0.0,
            passed: false,
            details: ['published_documents' => 0],
            gap: [
                'title' => sprintf('compliance_check.%s.fail_message', self::CHECK_ID),
                'priority' => 'critical',
                'route' => 'app_policy_wizard_index',
                'translation_domain' => 'policy_wizard',
            ],
        );
    }
}
