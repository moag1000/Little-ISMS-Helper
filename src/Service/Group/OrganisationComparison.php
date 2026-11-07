<?php

declare(strict_types=1);

namespace App\Service\Group;

use App\Entity\Tenant;
use App\Service\Kpi\KpiCatalog;

/**
 * Organisations of a holding side by side: one row per organisation, one
 * column per management KPI, plus framework compliance. Every value comes
 * from {@see KpiCatalog}, computed with the organisation pinned as tenant
 * context ({@see HoldingScope::asTenant()}) — so each cell is exactly the
 * number that organisation's own dashboard shows, traffic light by its own
 * KPI thresholds, and a KPI whose module is off there stays empty.
 *
 * Page, Excel and PDF of the group report "Organisationsvergleich" read the
 * same structure.
 */
class OrganisationComparison
{
    /**
     * Compared KPIs: key => [catalog section, column group]. The group is a
     * section title of the management KPIs (`dashboard.management_kpis.<group>.title`).
     */
    public const array COLUMNS = [
        'isms_health_score' => ['health', 'core'],
        'control_compliance' => ['core', 'core'],
        'critical_risks' => ['risk_management', 'risk_management'],
        'risk_treatment_rate' => ['risk_management', 'risk_management'],
        'overdue_treatments' => ['risk_management', 'risk_management'],
        'risk_exposure_eur' => [KpiCatalog::SECTION_RISK_FINANCE, 'risk_management'],
        'asset_classification_rate' => ['asset_management', 'asset_management'],
        'open_incidents' => ['incident_management', 'incident_management'],
        'overdue_incidents' => ['incident_management', 'incident_management'],
        'bia_coverage' => ['business_continuity', 'business_continuity'],
        'training_completion_rate' => ['training', 'training'],
        'open_audit_findings' => ['audits', 'audits'],
        'supplier_assessment_rate' => ['supplier_management', 'supplier_management'],
        'documents_needing_review' => ['documentation', 'documentation'],
    ];

    /** Units averaged in the group row; every other KPI (counts, euro) is summed. */
    private const array AVERAGED_UNITS = ['%', '/100'];

    public function __construct(
        private readonly KpiCatalog $kpiCatalog,
        private readonly HoldingScope $holdingScope,
    ) {
    }

    /**
     * @param list<Tenant> $tenants the caller's accessible subtree, holding first
     *
     * @return array{
     *     columns: list<array{key: string, group: string, unit: string}>,
     *     frameworks: list<array{key: string, label: string}>,
     *     rows: list<array{tenant: Tenant, kpis: array<string, ?array<string, mixed>>, frameworks: array<string, ?array<string, mixed>>}>,
     *     group: array<string, ?array<string, mixed>>,
     *     statusCounts: array{danger: int, warning: int},
     * }
     */
    public function build(array $tenants): array
    {
        $rows = [];
        $units = [];
        $frameworks = [];
        $statusCounts = ['danger' => 0, 'warning' => 0];
        foreach ($tenants as $tenant) {
            $kpis = $this->holdingScope->asTenant($tenant, fn (): array => $this->kpiCatalog->managementKpis($tenant));

            $cells = [];
            foreach (self::COLUMNS as $key => [$section]) {
                $item = $kpis[$section][$key] ?? null;
                $cells[$key] = is_array($item) && !($item['na'] ?? false) && is_numeric($item['value'] ?? null) ? $item : null;
                if ($cells[$key] !== null) {
                    $units[$key] ??= (string) ($item['unit'] ?? '');
                }
            }

            $fw = [];
            foreach ($kpis['per_framework'] ?? [] as $key => $item) {
                if (is_array($item) && is_numeric($item['value'] ?? null)) {
                    $fw[(string) $key] = $item;
                    $frameworks[(string) $key] ??= (string) ($item['label'] ?? $key);
                }
            }

            foreach ([...array_values($cells), ...array_values($fw)] as $item) {
                $status = $item['status'] ?? null;
                if (is_string($status) && isset($statusCounts[$status])) {
                    ++$statusCounts[$status];
                }
            }
            $rows[] = ['tenant' => $tenant, 'kpis' => $cells, 'frameworks' => $fw];
        }

        // A column nobody has (module off everywhere, euro KPIs hidden for the role) is left out.
        $columns = [];
        foreach (self::COLUMNS as $key => [, $group]) {
            if (isset($units[$key])) {
                $columns[] = ['key' => $key, 'group' => $group, 'unit' => $units[$key]];
            }
        }
        asort($frameworks, SORT_NATURAL | SORT_FLAG_CASE);

        return [
            'columns' => $columns,
            'frameworks' => array_map(static fn (string $k, string $l): array => ['key' => $k, 'label' => $l], array_keys($frameworks), $frameworks),
            'rows' => $rows,
            'group' => $this->groupRow($rows, $columns, array_keys($frameworks)),
            'statusCounts' => $statusCounts,
        ];
    }

    /**
     * Group row: percentages and scores as the plain mean of the organisations
     * that have a value (each organisation counts once), counts and euro summed.
     *
     * @param list<array{tenant: Tenant, kpis: array<string, ?array<string, mixed>>, frameworks: array<string, ?array<string, mixed>>}> $rows
     * @param list<array{key: string, group: string, unit: string}> $columns
     * @param list<string> $frameworkKeys
     *
     * @return array<string, ?array<string, mixed>>
     */
    private function groupRow(array $rows, array $columns, array $frameworkKeys): array
    {
        $group = [];
        foreach ($columns as $column) {
            $values = array_values(array_filter(array_map(
                static fn (array $r): ?float => isset($r['kpis'][$column['key']]) ? (float) $r['kpis'][$column['key']]['value'] : null,
                $rows,
            ), static fn (?float $v): bool => $v !== null));
            $group[$column['key']] = $this->aggregate($values, $column['unit']);
        }
        foreach ($frameworkKeys as $key) {
            $values = array_values(array_filter(array_map(
                static fn (array $r): ?float => isset($r['frameworks'][$key]) ? (float) $r['frameworks'][$key]['value'] : null,
                $rows,
            ), static fn (?float $v): bool => $v !== null));
            $group['fw:' . $key] = $this->aggregate($values, '%');
        }

        return $group;
    }

    /**
     * @param list<float> $values
     * @return array{value: float, unit: string, mode: string}|null
     */
    private function aggregate(array $values, string $unit): ?array
    {
        if ($values === []) {
            return null;
        }
        $average = in_array($unit, self::AVERAGED_UNITS, true);

        return [
            'value' => $average ? round(array_sum($values) / count($values), 1) : array_sum($values),
            'unit' => $unit,
            'mode' => $average ? 'avg' : 'sum',
        ];
    }
}
