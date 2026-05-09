<?php

declare(strict_types=1);

namespace App\Service\ComplianceWizard\Check\PolicyWizard\Bsi;

use App\Entity\Tenant;
use App\Repository\DocumentRepository;
use App\Service\ComplianceWizard\Check\PolicyWizard\PolicyWizardCheckInterface;
use App\Service\ComplianceWizard\Check\PolicyWizard\PolicyWizardCheckResult;

/**
 * W5-C / BSI 200-2 Kap. 6 — confirms the tenant has a published
 * Schutzbedarfsfeststellungs-Methodik (Protection-Needs Assessment
 * Methodology) document.
 *
 * Per `docs/plans/policy-wizard/02-bsi-input.md` §4 the methodology is
 * a stand-alone "Methode" document (not a Richtlinie) that defines the
 * 3- vs 4-level Schutzbedarf scheme, Vererbungs-Regeln, and the
 * Schadensszenarien-Katalog.
 *
 * A document qualifies when ALL of the following hold:
 * - `tenant` matches
 * - `status` is `published` or `approved`
 * - `isArchived` is false
 * - `generatedFromTemplate` has `standard='bsi'` and
 *   `topic='protection_needs_methodology'` (the topic-key emitted by
 *   the W5-A seed command for the §4 methodology row)
 */
final class BsiSchutzbedarfMethodPresentCheck implements PolicyWizardCheckInterface
{
    public const CHECK_ID = 'bsi_schutzbedarf_method_present';
    private const STANDARD = 'bsi';
    private const TOPIC = 'protection_needs_methodology';

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
