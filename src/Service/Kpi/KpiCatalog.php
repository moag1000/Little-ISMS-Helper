<?php

declare(strict_types=1);

namespace App\Service\Kpi;

use App\Entity\Tenant;
use App\Entity\User;
use App\Form\KpiThresholdConfigType;
use App\Security\FieldVisibilityResolver;
use App\Service\DashboardStatisticsService;
use App\Service\KpiThresholdConfigResolver;
use App\Service\ModuleConfigurationService;
use App\Service\TenantRecords;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * The one place dashboards, reports and snapshots read management KPIs from
 * (UI wave D). Every KPI has a stable key (`control_compliance`,
 * `risk_exposure_eur`, …); a dashboard is a selection of keys, not its own
 * calculation.
 *
 * On top of {@see DashboardStatisticsService::getManagementKPIs()} it
 *  - applies the organisation's KPI thresholds (admin → KPI-Schwellwerte,
 *    inherited down the holding tree) to the traffic-light status, so the
 *    setting takes effect on every dashboard and report, not on none;
 *  - adds the euro KPIs from the quantitative risk fields (module
 *    `risk_quant`), only for roles allowed to see monetary risk fields;
 *  - adds the location KPIs (module `locations`) and the person-days saved
 *    from the FTE tracking ({@see LocationKpis}).
 *
 * Twig: `kpi('risk_exposure_eur')`, `management_kpis()` ({@see \App\Twig\KpiExtension}).
 */
class KpiCatalog
{
    /**
     * Threshold-driven KPIs: key => [section, default good, default warning].
     * Keys are exactly {@see KpiThresholdConfigType::KPI_KEYS}.
     */
    public const array THRESHOLDS = [
        'control_compliance' => ['core', 80, 60],
        'control_compliance_weighted' => ['core', 80, 60],
        'implementation_readiness' => ['management', 80, 60],
        'isms_health_score' => ['health', 80, 60],
        'risk_treatment_rate' => ['risk_management', 90, 70],
        'asset_classification_rate' => ['asset_management', 90, 70],
        'training_completion_rate' => ['training', 90, 70],
        'supplier_assessment_rate' => ['supplier_management', 90, 70],
        'bia_coverage' => ['business_continuity', 90, 70],
        'location_checks_rate' => ['locations', 90, 70],
    ];

    /** Per-framework and BSI coverage are compliance percentages: they follow `control_compliance`. */
    private const array SECTION_THRESHOLD = [
        'per_framework' => 'control_compliance',
        'bsi_stufen' => 'control_compliance',
    ];

    public const string SECTION_RISK_FINANCE = 'risk_finance';

    /** @var array<int, array<string, mixed>> per tenant id, for the request */
    private array $memo = [];

    public function __construct(
        private readonly DashboardStatisticsService $statistics,
        private readonly KpiThresholdConfigResolver $thresholds,
        private readonly RiskExposure $riskExposure,
        private readonly ModuleConfigurationService $modules,
        private readonly Security $security,
        private readonly TenantRecords $tenantRecords = new TenantRecords(),
        private readonly ?LocationKpis $locationKpis = null,
    ) {
    }

    /**
     * Management KPIs grouped by section, thresholds applied.
     *
     * @param Tenant|null $tenant explicit tenant for callers without a user (cron); defaults
     *                            to the user's tenant, then the TenantContext tenant
     * @param bool $withTrend add the 30-day trend from the KPI snapshots
     *
     * @return array<string, mixed>
     */
    public function managementKpis(?Tenant $tenant = null, bool $withTrend = false): array
    {
        $user = $this->security->getUser();
        $tenant = $this->tenantRecords->resolve($tenant ?? ($user instanceof User ? $user->getTenant() : null));
        if (!$tenant instanceof Tenant) {
            return [];
        }

        $kpis = $this->memo[(int) $tenant->getId()] ??= $this->build($tenant);

        return $withTrend ? $this->statistics->addTrendData($kpis, $tenant) : $kpis;
    }

    /**
     * One KPI by key for the current user's organisation, or null when its
     * module is off / no data source exists / the role may not see it.
     *
     * @return array<string, mixed>|null
     */
    public function get(string $key): ?array
    {
        foreach ($this->managementKpis() as $section => $items) {
            if ($section !== 'active_modules' && is_array($items) && isset($items[$key]) && is_array($items[$key])) {
                return $items[$key];
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function build(Tenant $tenant): array
    {
        $kpis = $this->statistics->getManagementKPIs($tenant);
        if ($kpis === []) {
            return [];
        }
        $kpis = array_merge($kpis, $this->locationKpis?->sections($tenant) ?? []);

        foreach (self::THRESHOLDS as $key => [$section, $good, $warning]) {
            if (isset($kpis[$section][$key]) && is_numeric($kpis[$section][$key]['value'] ?? null)) {
                $kpis[$section][$key]['status'] = $this->status($tenant, $key, $kpis[$section][$key]['value'], $good, $warning);
            }
        }
        foreach (self::SECTION_THRESHOLD as $section => $key) {
            [, $good, $warning] = self::THRESHOLDS[$key];
            foreach ($kpis[$section] ?? [] as $itemKey => $item) {
                if (is_array($item) && is_numeric($item['value'] ?? null)) {
                    $kpis[$section][$itemKey]['status'] = $this->status($tenant, $key, $item['value'], $good, $warning);
                }
            }
        }

        $finance = $this->riskFinance($tenant);
        if ($finance !== []) {
            $kpis[self::SECTION_RISK_FINANCE] = $finance;
        }

        return $kpis;
    }

    private function status(Tenant $tenant, string $key, int|float|string $value, int $good, int $warning): string
    {
        $t = $this->thresholds->resolveFor($tenant, $key, $good, $warning);
        $v = (float) $value;

        return $v >= $t->goodThreshold ? 'good' : ($v >= $t->warningThreshold ? 'warning' : 'danger');
    }

    /** @return array<string, array<string, mixed>> */
    private function riskFinance(Tenant $tenant): array
    {
        $role = FieldVisibilityResolver::getFieldRoleMap()['Risk']['ale'] ?? 'ROLE_MANAGER';
        if (!$this->modules->isModuleActive('risk_quant') || !$this->security->isGranted($role)) {
            return [];
        }

        $exposure = $this->riskExposure->forTenant($tenant);
        $details = ['quantified' => $exposure['quantified'], 'open' => $exposure['open']];

        return [
            'risk_exposure_eur' => [
                'label' => 'kpi.risk_exposure_eur',
                'value' => $exposure['total'],
                'unit' => '€',
                'status' => 'info',
                'na' => $exposure['quantified'] === 0,
                'details' => $details,
            ],
            'risk_top5_eur' => [
                'label' => 'kpi.risk_top5_eur',
                'value' => array_sum(array_column($exposure['top'], 'ale')),
                'unit' => '€',
                'status' => 'info',
                'na' => $exposure['quantified'] === 0,
                'details' => $details + ['top' => $exposure['top']],
            ],
        ];
    }
}
