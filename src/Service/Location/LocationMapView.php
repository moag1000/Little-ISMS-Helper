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
        private readonly LocationLayers $layers,
        private readonly LocationSupplierChains $supplierChains,
    ) {
    }

    /**
     * @return array{
     *     sites: list<array<string, mixed>>, unsaved: int, peers: array<int, array<string, mixed>>,
     *     objectives: array<int, list<array<string, mixed>>>, levers: list<array<string, mixed>>, money: bool,
     *     peerMode: string, layers: list<string>, suppliers: ?array<string, mixed>, sample: array<string, mixed>
     * }
     */
    public function build(Tenant $tenant, ?User $viewer, bool $withSuppliers = true): array
    {
        $sites = $this->posture->forTenant($tenant, $viewer);
        $settings = LocationMapSettings::of($tenant);

        // Old rows have no coordinates yet: place them for display from the
        // offline tables. Stored on the next save or via "Koordinaten ermitteln".
        $unsaved = 0;
        foreach ($sites as &$site) {
            if ($site['lat'] === null && $site['geoSource'] === null) {
                $point = $site['type'] === 'region'
                    ? $this->geocoder->locateArea($site['country'])
                    : $this->geocoder->locate($site['postalCode'], $site['city'], $site['country']);
                if ($point !== null) {
                    [$site['lat'], $site['lng'], $site['geoSource']] = [
                        $settings->display($point->latitude),
                        $settings->display($point->longitude),
                        $point->source,
                    ];
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

        // Peer comparison per tenant setting; other sites' owners are named
        // only when the tenant chose so (§ 87 Abs. 1 Nr. 6 BetrVG).
        if (!$settings->namesOwners()) {
            foreach ($sites as &$site) {
                unset($site['ownerName']);
            }
            unset($site);
        }
        $peers = $settings->comparesPeers() ? LocationPeers::compare($sites) : [];

        // Further layers, each behind its module. Facts per site go into
        // $site['layers'][<layer>], the pin band into $site['bands'][<layer>].
        $layers = ['risk', 'gaps'];
        $facts = [];
        if ($this->modules->isModuleActive('bcm')) {
            $facts[LocationLayers::EMERGENCY] = $this->layers->emergency($tenant, $sites);
        }
        if ($this->modules->isModuleActive('privacy')) {
            $facts[LocationLayers::THIRD_COUNTRY] = $this->layers->thirdCountry($sites);
        }
        if ($this->modules->isModuleActive('audits')) {
            $facts[LocationLayers::AUDIT] = $this->layers->audit($tenant, $sites);
        }
        foreach ($sites as &$site) {
            $site['bands'] = ['risk' => $site['riskBand'], 'gaps' => $site['gapBand']];
            $site['layers'] = [];
            foreach ($facts as $layer => $perSite) {
                $site['layers'][$layer] = $perSite[$site['id']] ?? ['band' => 'none'];
                $site['bands'][$layer] = $site['layers'][$layer]['band'];
            }
        }
        unset($site);
        $layers = array_merge($layers, array_keys($facts));
        $sample = isset($facts[LocationLayers::AUDIT]) ? self::auditSample($sites) : ['size' => 0, 'ids' => []];
        foreach ($sites as &$site) {
            $site['sample'] = in_array($site['id'], $sample['ids'], true);
        }
        unset($site);

        return [
            'sites' => $sites,
            'unsaved' => $unsaved,
            'peers' => $peers,
            'peerMode' => $settings->peerMode,
            'objectives' => $this->objectives->forSites($tenant, $sites),
            'levers' => self::levers($sites),
            'money' => $money,
            'layers' => $layers,
            'suppliers' => $withSuppliers && isset($facts[LocationLayers::THIRD_COUNTRY]) ? $this->supplierChains->forTenant($tenant) : null,
            'sample' => $sample,
        ];
    }

    /**
     * Proposal for the multi-site audit sample (IAF MD 1, 6.1): √n of the
     * active sites, rounded up. Sites without an audit for 36 months come
     * first, then the longest since the last audit, then the highest risk.
     * IAF MD 1 also asks for a random part; the auditor adds it.
     *
     * @param list<array<string, mixed>> $sites rows with layers.audit
     * @return array{size: int, ids: list<int>}
     */
    public static function auditSample(array $sites): array
    {
        $active = array_values(array_filter($sites, static fn (array $s): bool => $s['active'] ?? true));
        if ($active === []) {
            return ['size' => 0, 'ids' => []];
        }
        $size = (int) ceil(sqrt(count($active)));
        usort($active, static function (array $a, array $b): int {
            $ma = $a['layers'][LocationLayers::AUDIT]['months'] ?? PHP_INT_MAX;
            $mb = $b['layers'][LocationLayers::AUDIT]['months'] ?? PHP_INT_MAX;

            return [$mb, $b['score']] <=> [$ma, $a['score']];
        });

        return ['size' => $size, 'ids' => array_map(static fn (array $s): int => (int) $s['id'], array_slice($active, 0, $size))];
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

        $view = $this->build($tenant, $viewer, false);
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
