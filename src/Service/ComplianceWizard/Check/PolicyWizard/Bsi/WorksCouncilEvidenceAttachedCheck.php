<?php

declare(strict_types=1);

namespace App\Service\ComplianceWizard\Check\PolicyWizard\Bsi;

use App\Entity\Tenant;
use App\Service\ComplianceWizard\Check\PolicyWizard\PolicyWizardCheckInterface;
use App\Service\ComplianceWizard\Check\PolicyWizard\PolicyWizardCheckResult;
use App\Service\PolicyWizard\WorksCouncilEvidenceCheck;

/**
 * W5 Gap-C / BSI / German Works-Council (Betriebsverfassungsgesetz § 87
 * Abs. 1 Nr. 6) — confirms every published policy that touches workplace-
 * monitoring or personal-data processing has a corresponding Betriebsrats-
 * Beteiligungsnachweis attached.
 *
 * Spec: `docs/plans/policy-wizard/07-phase4-sprint-reconciliation.md`
 * line 261-262 (Auditor "Auditor-specific gaps" Works-Council, lines
 * 124-129) — the auditor wants positive evidence that the works-council
 * was consulted before the policy was put into force.
 *
 * Vacuous-pass semantics: when no relevant PolicyTemplate is seeded for
 * the tenant (greenfield + no workplace-monitoring topics yet) the check
 * passes with score 100 because there is nothing to evidence.
 */
final class WorksCouncilEvidenceAttachedCheck implements PolicyWizardCheckInterface
{
    public const string CHECK_ID = 'works_council_evidence_attached';
    private const string STANDARD = 'bsi';

    public function __construct(
        private readonly WorksCouncilEvidenceCheck $inventory,
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

        $report = $this->inventory->inspect($tenant);
        $evaluated = $report['evaluated_documents'];

        if ($evaluated === 0) {
            return new PolicyWizardCheckResult(
                checkId: self::CHECK_ID,
                score: 100.0,
                passed: true,
                details: [
                    'evaluated_documents' => 0,
                    'reason' => 'no_workplace_monitoring_documents',
                ],
            );
        }

        $covered = $report['covered'];
        $missing = $report['missing'];
        if ($missing === []) {
            return new PolicyWizardCheckResult(
                checkId: self::CHECK_ID,
                score: 100.0,
                passed: true,
                details: [
                    'evaluated_documents' => $evaluated,
                    'covered' => $covered,
                ],
            );
        }

        $score = round(($covered / $evaluated) * 100, 1);
        $missingTopics = array_values(array_unique(array_filter(
            array_map(static fn (array $r): ?string => $r['topic'] ?? null, $missing),
            static fn (?string $t): bool => $t !== null && $t !== '',
        )));

        return new PolicyWizardCheckResult(
            checkId: self::CHECK_ID,
            score: $score,
            passed: false,
            details: [
                'evaluated_documents' => $evaluated,
                'covered' => $covered,
                'missing_count' => count($missing),
            ],
            gap: [
                'title' => sprintf('compliance_check.%s.fail_message', self::CHECK_ID),
                'priority' => 'high',
                'route' => 'app_policy_wizard_index',
                'translation_domain' => 'policy_wizard',
                'items' => array_slice($missingTopics, 0, 5),
            ],
        );
    }
}
