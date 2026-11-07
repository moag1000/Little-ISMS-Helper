<?php

declare(strict_types=1);

namespace App\Service\Kpi;

use App\Entity\ISMSObjective;
use App\Entity\Tenant;
use App\Repository\ISMSObjectiveRepository;
use App\Service\Location\LocationPostureService;
use DateTimeImmutable;

/**
 * Current value of an ISMS objective (ISO 27001 6.2) that is linked to a KPI
 * of the catalog: computed from the catalog, never typed in. Objectives
 * without a KPI keep their manual current value.
 *
 * A location KPI can be scoped to one site; the value is then that site's
 * value. Unscoped location objectives (e.g. "checks passed ≥ 90 % at all
 * sites") are shown on every site's panel against the site's own value.
 */
class ObjectiveKpiProgress
{
    /** Keys an objective can be linked to: the threshold KPIs plus a few counts. */
    public const array KEYS = [
        'control_compliance', 'control_compliance_weighted', 'implementation_readiness', 'isms_health_score',
        'risk_treatment_rate', 'asset_classification_rate', 'training_completion_rate', 'supplier_assessment_rate',
        'bia_coverage', 'location_checks_rate', 'location_open_gaps', 'location_critical_sites', 'overdue_treatments', 'risk_exposure_eur', 'fte_saved_days',
    ];

    /** Keys where a lower value is better: progress = target / current. */
    private const array LOWER_IS_BETTER = [
        'location_open_gaps', 'location_critical_sites', 'overdue_treatments', 'risk_exposure_eur',
    ];

    /** @var array<int, array<int, array<string, mixed>>> tenant id => site id => posture row */
    private array $sites = [];

    public function __construct(
        private readonly KpiCatalog $catalog,
        private readonly LocationPostureService $posture,
        private readonly ISMSObjectiveRepository $objectives,
    ) {
    }

    public function current(ISMSObjective $objective): ?float
    {
        $key = $objective->getKpiKey();
        if ($key === null) {
            return null;
        }
        $location = $objective->getKpiLocation();
        $tenant = $objective->getTenant();
        if ($location !== null && $tenant instanceof Tenant && str_starts_with($key, 'location_')) {
            $site = $this->siteRows($tenant)[(int) $location->getId()] ?? null;

            return $site !== null ? self::siteValue($key, $site) : null;
        }
        $value = $this->catalog->get($key)['value'] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * Progress in percent from a computed current value and the target.
     */
    public static function progress(string $key, ?float $current, mixed $target): ?int
    {
        if ($current === null || !is_numeric($target)) {
            return null;
        }
        $target = (float) $target;
        if (in_array($key, self::LOWER_IS_BETTER, true)) {
            return $current <= $target ? 100 : (int) round(100 * max(0.0, $target) / $current);
        }

        return $target > 0 ? (int) min(100, round(100 * $current / $target)) : 100;
    }

    /**
     * Location objectives per site for the map panel.
     *
     * @param list<array<string, mixed>> $rows posture rows
     *
     * @return array<int, list<array{id: int|null, title: string, target: string, current: ?float, monthsLeft: ?int}>>
     */
    public function forSites(Tenant $tenant, array $rows): array
    {
        $result = [];
        foreach ($this->objectives->findBy(['tenant' => $tenant]) as $objective) {
            $key = (string) $objective->getKpiKey();
            if (!str_starts_with($key, 'location_') || in_array($objective->getStatus(), ['achieved', 'cancelled'], true)) {
                continue;
            }
            $scope = $objective->getKpiLocation()?->getId();
            foreach ($rows as $site) {
                if ($scope !== null && $scope !== $site['id']) {
                    continue;
                }
                $result[(int) $site['id']][] = [
                    'id' => $objective->getId(),
                    'title' => (string) $objective->getTitle(),
                    'target' => (string) $objective->getTargetValue(),
                    'current' => self::siteValue($key, $site),
                    'monthsLeft' => self::monthsLeft($objective),
                ];
            }
        }

        return $result;
    }

    /** @param array<string, mixed> $site */
    public static function siteValue(string $key, array $site): ?float
    {
        return match ($key) {
            'location_checks_rate' => $site['checksRate'] !== null ? (float) $site['checksRate'] : null,
            'location_open_gaps' => (float) count($site['gaps']),
            'location_critical_sites' => $site['riskBand'] === 'critical' ? 1.0 : 0.0,
            default => null,
        };
    }

    private static function monthsLeft(ISMSObjective $objective): ?int
    {
        $target = $objective->getTargetDate();
        if ($target === null) {
            return null;
        }
        $diff = (new DateTimeImmutable('today'))->diff(DateTimeImmutable::createFromInterface($target));

        return $diff->invert === 1 ? 0 : $diff->y * 12 + $diff->m;
    }

    /** @return array<int, array<string, mixed>> */
    private function siteRows(Tenant $tenant): array
    {
        return $this->sites[(int) $tenant->getId()] ??= array_column($this->posture->forTenant($tenant), null, 'id');
    }
}
