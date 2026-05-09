<?php

declare(strict_types=1);

namespace App\Service\ComplianceWizard\Check\PolicyWizard\Dora;

use App\Entity\IncidentSlaConfig;
use App\Entity\Tenant;
use App\Repository\IncidentSlaConfigRepository;
use App\Service\ComplianceWizard\Check\PolicyWizard\PolicyWizardCheckInterface;
use App\Service\ComplianceWizard\Check\PolicyWizard\PolicyWizardCheckResult;

/**
 * W4-D / DORA Art. 19 — confirms the tenant's per-severity
 * {@see IncidentSlaConfig} rows are configured tight enough to satisfy
 * the DORA major-incident reporting timetable per Commission Delegated
 * Regulation (EU) 2024/1772 + ITS JC 2024 33:
 *
 * - **initial** notification: ≤ 4 hours from classification as major
 *   (Art. 19.4(a)). Mapped against `severity = critical`.
 * - **intermediate** report: ≤ 72 hours (Art. 19.4(b)). Mapped against
 *   `severity = high`.
 * - **final** report: ≤ 1 month (Art. 19.4(c)). Mapped against
 *   `severity = breach` whose `responseHours` covers the wrap-up SLA.
 *
 * The check passes when every required severity row exists AND its
 * `responseHours` is at or below the DORA ceiling.
 */
final class DoraIncidentReportingDeadlinesCheck implements PolicyWizardCheckInterface
{
    public const CHECK_ID = 'dora_incident_reporting_deadlines';
    private const STANDARD = 'dora';

    /**
     * Mapping severity → DORA-required ceiling in hours.
     *
     * @var array<string, int>
     */
    public const REQUIRED_DEADLINES_HOURS = [
        IncidentSlaConfig::SEVERITY_CRITICAL => 4,    // Art. 19.4(a) initial
        IncidentSlaConfig::SEVERITY_HIGH => 72,       // Art. 19.4(b) intermediate
        IncidentSlaConfig::SEVERITY_BREACH => 720,    // Art. 19.4(c) final = 30 days
    ];

    public function __construct(
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

        $configs = $this->slaRepository->findByTenant($tenant);
        $bySeverity = [];
        foreach ($configs as $cfg) {
            $sev = $cfg->getSeverity();
            if ($sev !== null) {
                $bySeverity[$sev] = $cfg;
            }
        }

        $violations = [];
        foreach (self::REQUIRED_DEADLINES_HOURS as $severity => $ceiling) {
            $cfg = $bySeverity[$severity] ?? null;
            if ($cfg === null) {
                $violations[] = [
                    'severity' => $severity,
                    'reason' => 'missing_sla_row',
                    'required_max_hours' => $ceiling,
                ];
                continue;
            }
            $responseHours = $cfg->getResponseHours();
            if ($responseHours > $ceiling) {
                $violations[] = [
                    'severity' => $severity,
                    'reason' => 'sla_exceeds_dora_ceiling',
                    'configured_hours' => $responseHours,
                    'required_max_hours' => $ceiling,
                ];
            }
        }

        if ($violations === []) {
            return new PolicyWizardCheckResult(
                checkId: self::CHECK_ID,
                score: 100.0,
                passed: true,
                details: [
                    'severities_checked' => array_keys(self::REQUIRED_DEADLINES_HOURS),
                ],
            );
        }

        return new PolicyWizardCheckResult(
            checkId: self::CHECK_ID,
            score: 0.0,
            passed: false,
            details: ['violations' => $violations],
            gap: [
                'title' => sprintf('compliance_check.%s.fail_message', self::CHECK_ID),
                'priority' => 'critical',
                'route' => 'app_admin_incident_sla_index',
                'translation_domain' => 'policy_wizard',
                'items' => array_slice($violations, 0, 5),
            ],
        );
    }
}
