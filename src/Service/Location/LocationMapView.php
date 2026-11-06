<?php

declare(strict_types=1);

namespace App\Service\Location;

use App\Entity\Location;
use App\Entity\Tenant;
use App\Entity\User;
use App\Security\FieldVisibilityResolver;
use App\Service\Kpi\ObjectiveKpiProgress;
use App\Service\ModuleConfigurationService;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Everything the map, its export, the dashboard tile and the site profile on
 * the location page show, computed once from the posture rows:
 * placement for display, peer comparison, objectives and the biggest levers.
 */
class LocationMapView
{
    public function __construct(
        private readonly LocationPostureService $posture,
        private readonly LocationGeocoder $geocoder,
        private readonly ObjectiveKpiProgress $objectives,
        private readonly ModuleConfigurationService $modules,
        private readonly Security $security,
    ) {
    }

    /**
     * @return array{
     *     sites: list<array<string, mixed>>, unsaved: int, peers: array<int, array<string, mixed>>,
     *     objectives: array<int, list<array<string, mixed>>>, levers: list<array<string, mixed>>, money: bool
     * }
     */
    public function build(Tenant $tenant, ?User $viewer): array
    {
        $sites = $this->posture->forTenant($tenant, $viewer);

        // Old rows have no coordinates yet: place them for display from the
        // offline tables. Stored on the next save or via "Koordinaten ermitteln".
        $unsaved = 0;
        foreach ($sites as &$site) {
            if ($site['lat'] === null && $site['geoSource'] === null) {
                $point = $site['type'] === 'region'
                    ? $this->geocoder->locateArea($site['country'])
                    : $this->geocoder->locate($site['postalCode'], $site['city'], $site['country']);
                if ($point !== null) {
                    [$site['lat'], $site['lng'], $site['geoSource']] = [$point->latitude, $point->longitude, $point->source];
                    ++$unsaved;
                }
            }
        }
        unset($site);

        // Euro values only for roles that may see monetary risk fields.
        $money = $this->modules->isModuleActive('risk_quant')
            && $this->security->isGranted(FieldVisibilityResolver::getFieldRoleMap()['Risk']['ale'] ?? 'ROLE_MANAGER');
        if (!$money) {
            foreach ($sites as &$site) {
                unset($site['ale']);
            }
            unset($site);
        }

        return [
            'sites' => $sites,
            'unsaved' => $unsaved,
            'peers' => LocationPeers::compare($sites),
            'objectives' => $this->objectives->forSites($tenant, $sites),
            'levers' => self::levers($sites),
            'money' => $money,
        ];
    }

    /**
     * Site profile ("Steckbrief") for the location page: the map row of the
     * site this location belongs to, its peer comparison and objectives.
     *
     * @return array{site: array<string, mixed>, peer: ?array<string, mixed>, objectives: list<array<string, mixed>>, partOf: bool}|null
     */
    public function profile(Location $location, ?User $viewer): ?array
    {
        $tenant = $location->getTenant();
        if ($tenant === null) {
            return null;
        }
        $root = $location;
        $guard = 0;
        while (($parent = $root->getParentLocation()) !== null && $parent->getTenant() === $tenant && $guard++ < 50) {
            $root = $parent;
        }

        $view = $this->build($tenant, $viewer);
        foreach ($view['sites'] as $site) {
            if ($site['id'] === $root->getId()) {
                return [
                    'site' => $site,
                    'peer' => $view['peers'][$site['id']] ?? null,
                    'objectives' => $view['objectives'][$site['id']] ?? [],
                    'partOf' => $root !== $location,
                ];
            }
        }

        return null;
    }

    /**
     * The three biggest levers across the given sites.
     *
     * @param list<array<string, mixed>> $sites
     * @return list<array<string, mixed>>
     */
    public static function levers(array $sites, int $limit = 3): array
    {
        $levers = [];
        foreach ($sites as $site) {
            if (!($site['active'] ?? true)) {
                continue;
            }
            foreach ($site['levers'] as $lever) {
                $levers[] = $lever + ['siteId' => $site['id'], 'siteName' => $site['name']];
            }
        }
        usort($levers, static fn (array $a, array $b): int => $b['impact'] <=> $a['impact']);

        return array_slice($levers, 0, $limit);
    }
}
