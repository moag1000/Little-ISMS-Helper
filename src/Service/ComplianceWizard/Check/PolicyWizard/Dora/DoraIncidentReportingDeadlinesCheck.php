<?php

declare(strict_types=1);

namespace App\Service\ComplianceWizard\Check\PolicyWizard\Dora;

use App\Entity\IncidentSlaConfig;
use App\Entity\Tenant;
use App\Service\IncidentSlaConfigResolver;
use App\Service\ComplianceWizard\Check\PolicyWizard\PolicyWizardCheckInterface;
use App\Service\ComplianceWizard\Check\PolicyWizard\PolicyWizardCheckResult;

/**
 * DORA Art. 19 — confirms the tenant's per-severity
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
 * The check passes when the effective `responseHours` per severity (own
 * row, else the IncidentSlaConfig default, tightened by the holding chain —
 * see {@see IncidentSlaConfigResolver}) is at or below the DORA ceiling.
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
        private readonly IncidentSlaConfigResolver $slaResolver,
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

        $violations = [];
        foreach (self::REQUIRED_DEADLINES_HOURS as $severity => $ceiling) {
            $responseHours = $this->slaResolver->resolveFor($tenant, $severity)->responseHours;
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
                'route' => 'app_admin_incident_sla_config',
                'translation_domain' => 'policy_wizard',
                'items' => array_slice($violations, 0, 5),
            ],
        );
    }
}
