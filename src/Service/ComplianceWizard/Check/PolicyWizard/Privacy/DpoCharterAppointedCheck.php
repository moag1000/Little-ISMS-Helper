<?php

declare(strict_types=1);

namespace App\Service\ComplianceWizard\Check\PolicyWizard\Privacy;

use App\Entity\DocumentSection;
use App\Entity\Tenant;
use App\Repository\DocumentRepository;
use App\Repository\UserRepository;
use App\Service\ComplianceWizard\Check\PolicyWizard\PolicyWizardCheckInterface;
use App\Service\ComplianceWizard\Check\PolicyWizard\PolicyWizardCheckResult;

/**
 * W6-D / GDPR Art. 37-39 — confirms the tenant has appointed a Data
 * Protection Officer AND the DPO mandate is documented.
 *
 * Two-pronged evidence:
 *  1. At least one tenant {@see \App\Entity\User} carries the custom
 *     role `ROLE_DPO` (organisational appointment).
 *  2. A DPO Charter is documented — either as a standalone Document
 *     (`standard='gdpr'`, `topic='dpo_charter'`) OR as the GDPR section
 *     `gdpr_dpo_mandate` inside the awareness_training host (per the
 *     {@see \App\Service\PolicyWizard\GdprSectionCatalogue}, the DPO
 *     mandate is one of the 10 GDPR sections that merge into ISO 27001
 *     hosts; it lands on the awareness_training host, see §0 Decision
 *     Matrix v2 row 2).
 *
 * Both prongs must hold — an appointment without a charter has no
 * scope/independence statement, a charter without an appointee is
 * organisationally void.
 *
 * Reference: `docs/plans/policy-wizard/06-dpo-input.md` §0.A. Maps to
 * ISO 27701 Cl. 6.3.1.3 (DPO appointment).
 */
final class DpoCharterAppointedCheck implements PolicyWizardCheckInterface
{
    public const CHECK_ID = 'dpo_charter_appointed';
    public const ROLE_DPO_NAME = 'ROLE_DPO';
    public const SECTION_KEY_DPO_MANDATE = 'gdpr_dpo_mandate';
    private const STANDARD = 'gdpr';
    private const TOPIC_CHARTER = 'dpo_charter';
    private const TOPIC_HOST_AWARENESS = 'awareness_training';

    public function __construct(
        private readonly DocumentRepository $documentRepository,
        private readonly UserRepository $userRepository,
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

        // Prong 1: at least one user carries ROLE_DPO.
        $dpoUsers = $this->userRepository->findByCustomRole(self::ROLE_DPO_NAME);
        $tenantDpoCount = 0;
        foreach ($dpoUsers as $user) {
            $userTenant = $user->getTenant();
            if ($userTenant !== null && $userTenant->getId() === $tenant->getId()) {
                $tenantDpoCount++;
            }
        }

        // Prong 2a: standalone DPO Charter document (topic='dpo_charter').
        $charterDocCount = (int) $this->documentRepository->createQueryBuilder('d')
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
            ->setParameter('topic', self::TOPIC_CHARTER)
            ->getQuery()
            ->getSingleScalarResult();

        // Prong 2b: gdpr_dpo_mandate section approved on the awareness host.
        $sectionCount = (int) $this->documentRepository->createQueryBuilder('d')
            ->select('COUNT(s.id)')
            ->innerJoin('d.generatedFromTemplate', 't')
            ->innerJoin(DocumentSection::class, 's', 'WITH', 's.document = d')
            ->where('d.tenant = :tenant')
            ->andWhere('d.isArchived = false')
            ->andWhere('t.topic = :topic')
            ->andWhere('s.sectionKey = :sectionKey')
            ->andWhere('s.status = :sectionStatus')
            ->setParameter('tenant', $tenant)
            ->setParameter('topic', self::TOPIC_HOST_AWARENESS)
            ->setParameter('sectionKey', self::SECTION_KEY_DPO_MANDATE)
            ->setParameter('sectionStatus', DocumentSection::STATUS_APPROVED)
            ->getQuery()
            ->getSingleScalarResult();

        $charterDocumented = ($charterDocCount > 0) || ($sectionCount > 0);

        $violations = [];
        if ($tenantDpoCount === 0) {
            $violations[] = ['reason' => 'no_user_with_role_dpo'];
        }
        if (!$charterDocumented) {
            $violations[] = ['reason' => 'no_dpo_charter_document_or_section'];
        }

        if ($violations === []) {
            return new PolicyWizardCheckResult(
                checkId: self::CHECK_ID,
                score: 100.0,
                passed: true,
                details: [
                    'dpo_users' => $tenantDpoCount,
                    'charter_documents' => $charterDocCount,
                    'charter_sections' => $sectionCount,
                ],
            );
        }

        return new PolicyWizardCheckResult(
            checkId: self::CHECK_ID,
            score: 0.0,
            passed: false,
            details: [
                'dpo_users' => $tenantDpoCount,
                'charter_documents' => $charterDocCount,
                'charter_sections' => $sectionCount,
                'violations' => $violations,
            ],
            gap: [
                'title' => sprintf('compliance_check.%s.fail_message', self::CHECK_ID),
                'priority' => 'critical',
                'route' => 'app_policy_wizard_index',
                'translation_domain' => 'policy_wizard',
                'items' => $violations,
            ],
        );
    }
}
