<?php

declare(strict_types=1);

namespace App\Service\Kpi;

use App\Entity\Tenant;
use App\Repository\Fte\FteTrackingMetricRepository;
use App\Service\Location\LocationPostureService;
use App\Service\ModuleConfigurationService;
use DateInterval;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Catalog sections for the location map (module `locations`) and the
 * efficiency tracking, so the map, dashboards and reports read the same
 * numbers from {@see KpiCatalog} instead of counting on their own.
 */
readonly class LocationKpis
{
    /** One person-day in minutes, as in the FTE board report. */
    private const int MINUTES_PER_DAY = 480;

    public function __construct(
        private LocationPostureService $posture,
        private FteTrackingMetricRepository $fteMetrics,
        private ModuleConfigurationService $modules,
        private Security $security,
    ) {
    }

    /**
     * @return array<string, array<string, array<string, mixed>>> section => key => KPI
     */
    public function sections(Tenant $tenant): array
    {
        $sections = [];
        if ($this->modules->isModuleActive('locations')) {
            $locations = $this->locations($tenant);
            if ($locations !== []) {
                $sections['locations'] = $locations;
            }
        }

        // Same audience as the FTE dashboard the number comes from.
        if (!$this->security->isGranted('ROLE_MANAGER')) {
            return $sections;
        }
        $minutes = $this->fteMetrics->getSavingsAggregate($tenant, new DateInterval('P12M'));
        $sections['efficiency'] = [
            'fte_saved_days' => [
                'label' => 'kpi.fte_saved_days',
                'value' => round($minutes / self::MINUTES_PER_DAY, 1),
                'unit' => '',
                'status' => 'info',
                'na' => $minutes === 0,
                'details' => ['minutes' => $minutes, 'window' => 'P12M'],
            ],
        ];

        return $sections;
    }

    /** @return array<string, array<string, mixed>> */
    private function locations(Tenant $tenant): array
    {
        // Closed sites (inactive) keep their history but no longer count.
        $sites = array_values(array_filter($this->posture->forTenant($tenant), static fn (array $s): bool => $s['active']));
        if ($sites === []) {
            return [];
        }

        $applicable = array_sum(array_column($sites, 'checksApplicable'));
        $gaps = array_sum(array_map(static fn (array $s): int => count($s['gaps']), $sites));
        $critical = count(array_filter($sites, static fn (array $s): bool => $s['riskBand'] === 'critical'));
        $placed = count(array_filter($sites, static fn (array $s): bool => $s['lat'] !== null));
        $details = ['sites' => count($sites), 'placed' => $placed];

        return [
            'location_checks_rate' => [
                'label' => 'kpi.location_checks_rate',
                'value' => $applicable > 0 ? (int) round(100 * ($applicable - $gaps) / $applicable) : null,
                'unit' => '%',
                'status' => 'info',
                'na' => $applicable === 0,
                'details' => $details + ['applicable' => $applicable, 'gaps' => $gaps],
            ],
            'location_open_gaps' => [
                'label' => 'kpi.location_open_gaps',
                'value' => $gaps,
                'unit' => '',
                'status' => $gaps > 0 ? 'warning' : 'good',
                'details' => $details,
            ],
            'location_critical_sites' => [
                'label' => 'kpi.location_critical_sites',
                'value' => $critical,
                'unit' => '',
                'status' => $critical > 0 ? 'danger' : 'good',
                'details' => $details,
            ],
        ];
    }
}
