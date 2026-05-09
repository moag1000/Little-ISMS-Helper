<?php

declare(strict_types=1);

namespace App\Service\ComplianceWizard\Check\PolicyWizard\Privacy;

use App\Entity\IncidentSlaConfig;
use App\Entity\Tenant;
use App\Repository\DocumentRepository;
use App\Repository\IncidentSlaConfigRepository;
use App\Service\ComplianceWizard\Check\PolicyWizard\PolicyWizardCheckInterface;
use App\Service\ComplianceWizard\Check\PolicyWizard\PolicyWizardCheckResult;

/**
 * W6-D / GDPR Art. 33 — confirms the Data-Breach Notification Procedure
 * is documented AND the breach-severity SLA is configured at or below
 * the 72-hour controller-notification ceiling.
 *
 * Two-pronged evidence:
 *  1. A published-or-approved {@see \App\Entity\Document} generated from
 *     the {@see \App\Command\SeedPrivacyPolicyTemplatesCommand} breach
 *     template (`standard='gdpr'`, `topic='data_breach_notification_procedure'`).
 *  2. The tenant's {@see IncidentSlaConfig} row for severity `breach`
 *     has `responseHours <= 72` (Art. 33(1) without undue delay, and
 *     where feasible, not later than 72 hours).
 *
 * Both prongs must hold — a procedure without an enforced SLA is
 * paper-only, an SLA without a procedure has no chain-of-custody.
 *
 * Reference: `docs/plans/policy-wizard/06-dpo-input.md` §2.5. Maps to
 * ISO 27701 Cl. 6.13 (2025) / Cl. 6.13.1.5 (2019).
 */
final class DataBreachNotification72hCheck implements PolicyWizardCheckInterface
{
    public const CHECK_ID = 'data_breach_notification_72h';
    public const GDPR_BREACH_DEADLINE_HOURS = 72;
    private const STANDARD = 'gdpr';
    private const TOPIC = 'data_breach_notification_procedure';

    public function __construct(
        private readonly DocumentRepository $documentRepository,
        private readonly IncidentSlaConfigRepository $slaRepository,
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

        $procedureCount = (int) $this->documentRepository->createQueryBuilder('d')
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

        $sla = $this->slaRepository->findByTenantAndSeverity(
            $tenant,
            IncidentSlaConfig::SEVERITY_BREACH,
        );

        $violations = [];
        if ($procedureCount === 0) {
            $violations[] = [
                'reason' => 'missing_procedure_document',
                'topic' => self::TOPIC,
            ];
        }
        if ($sla === null) {
            $violations[] = [
                'reason' => 'missing_breach_sla_row',
                'severity' => IncidentSlaConfig::SEVERITY_BREACH,
                'required_max_hours' => self::GDPR_BREACH_DEADLINE_HOURS,
            ];
        } elseif ($sla->getResponseHours() > self::GDPR_BREACH_DEADLINE_HOURS) {
            $violations[] = [
                'reason' => 'sla_exceeds_gdpr_72h_ceiling',
                'severity' => IncidentSlaConfig::SEVERITY_BREACH,
                'configured_hours' => $sla->getResponseHours(),
                'required_max_hours' => self::GDPR_BREACH_DEADLINE_HOURS,
            ];
        }

        if ($violations === []) {
            return new PolicyWizardCheckResult(
                checkId: self::CHECK_ID,
                score: 100.0,
                passed: true,
                details: [
                    'procedure_documents' => $procedureCount,
                    'breach_sla_hours' => $sla?->getResponseHours(),
                ],
            );
        }

        return new PolicyWizardCheckResult(
            checkId: self::CHECK_ID,
            score: 0.0,
            passed: false,
            details: [
                'procedure_documents' => $procedureCount,
                'breach_sla_hours' => $sla?->getResponseHours(),
                'violations' => $violations,
            ],
            gap: [
                'title' => sprintf('compliance_check.%s.fail_message', self::CHECK_ID),
                'priority' => 'critical',
                'route' => 'app_policy_wizard_index',
                'translation_domain' => 'policy_wizard',
                'items' => array_slice($violations, 0, 5),
            ],
        );
    }
}
