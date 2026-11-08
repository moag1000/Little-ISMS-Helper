<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Mcp\McpSession;
use App\Security\Persona\PersonaResolver;
use App\Service\Kpi\KpiCatalog;

/**
 * The management KPIs of the organisation (KpiCatalog: tenant thresholds
 * applied, risk bands 20/12/6, euro KPIs from the risk quantification) —
 * the numbers of the dashboards and the management report.
 */
final class ManagementKpisTool extends AbstractMcpTool
{
    public function __construct(
        private readonly KpiCatalog $kpis,
        private readonly PersonaResolver $personas,
    ) {
    }

    /** Same as the management dashboards in the UI: reviewers (ROLE_AUDITOR) and above. */
    public function isAvailable(McpSession $session): bool
    {
        return $this->personas->reaches($session->user, 'ROLE_AUDITOR');
    }

    public function name(): string
    {
        return 'management_kpis';
    }

    public function description(): string
    {
        return 'Management KPIs grouped by area (control compliance, risk treatment, incidents, training, suppliers, BCM, …) '
            . 'with value, unit and status (good/warning/critical against the organisation\'s thresholds). '
            . 'Use for board summaries and management reviews (ISO 27001 9.1, 9.3).';
    }

    public function inputSchema(McpSession $session): array
    {
        return ['type' => 'object', 'properties' => new \stdClass()];
    }

    public function call(array $arguments, McpSession $session): array
    {
        $kpis = $this->kpis->managementKpis($session->tenant);

        return ['kpis' => $kpis === [] ? new \stdClass() : $kpis, '_audit' => ['count' => count($kpis)]];
    }
}
