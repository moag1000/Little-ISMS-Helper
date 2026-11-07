<?php

declare(strict_types=1);

namespace App\Service\Location;

use App\Entity\Asset;
use App\Entity\EntityTag;
use App\Entity\Incident;
use App\Entity\Location;
use App\Entity\Risk;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\IncidentStatus;
use App\Enum\RiskStatus;
use App\Risk\RiskMatrixThresholds;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * One posture row per top-level location (site) for the location map.
 *
 * Everything is derived from data that already exists; nothing is entered
 * twice:
 *   - protection need = highest C/I/A of the assets whose physical location
 *     lies in the site's tree (maximum principle, BSI 200-2 / ISO 27005)
 *   - current risk    = per risk the residual score once the risk is handled,
 *     otherwise the inherent score; the site shows the highest (closed risks
 *     do not count). Risks count when linked to a location of the tree or to
 *     an asset located there.
 *   - six checks on Location, Risk and Incident fields; a check that does not
 *     apply to the site is left out instead of counting as passed.
 *   - levers = open risks whose treatment plan lowers the score: the planned
 *     drop (inherent minus residual target) times the protection need.
 *   - tags = active tags of the site, used for custom peer groups.
 *
 * All queries are scoped to the given tenant explicitly.
 */
class LocationPostureService implements ResetInterface
{
    public const array CHECKS = ['owner', 'deputy', 'access_control', 'camera', 'risks_treated', 'incidents_closed'];

    /** ISO 27001:2022 reference shown next to each check. */
    public const array CHECK_NORM = [
        'owner' => 'A.5.2',
        'deputy' => 'A.5.2',
        'access_control' => 'A.7.2',
        'camera' => 'A.7.4',
        'risks_treated' => '6.1.3',
        'incidents_closed' => 'A.5.26',
    ];

    private const array CONTROLLED_LEVELS = ['controlled', 'secure'];

    /** @var array<string, list<array<string, mixed>>> "<tenant>:<viewer>" => rows, for the request */
    private array $memo = [];

    /** @var array<int, array<int, int>> tenant id => (location id => site id), for the request */
    private array $roots = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LocationGeocoder $geocoder,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forTenant(Tenant $tenant, ?User $viewer = null): array
    {
        return $this->memo[$tenant->getId() . ':' . $viewer?->getId()] ??= $this->compute($tenant, $viewer);
    }

    /**
     * Site (top-level location) of every location of the tenant, so other
     * layers place their records on the same pins.
     *
     * @return array<int, int> location id => site id
     */
    public function siteOfLocations(Tenant $tenant): array
    {
        if (!isset($this->roots[(int) $tenant->getId()])) {
            $this->forTenant($tenant);
        }

        return $this->roots[(int) $tenant->getId()] ?? [];
    }

    public function reset(): void
    {
        $this->memo = [];
        $this->roots = [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function compute(Tenant $tenant, ?User $viewer): array
    {
        /** @var list<Location> $locations */
        $locations = $this->em->getRepository(Location::class)->findBy(['tenant' => $tenant], ['name' => 'ASC']);
        $this->roots[(int) $tenant->getId()] = [];
        if ($locations === []) {
            return [];
        }

        $byId = [];
        foreach ($locations as $location) {
            $byId[$location->getId()] = $location;
        }
        $rootOf = [];
        foreach ($locations as $location) {
            $rootOf[$location->getId()] = $this->rootId($location, $byId);
        }
        $this->roots[(int) $tenant->getId()] = $rootOf;

        $sites = [];
        foreach ($locations as $location) {
            if ($rootOf[$location->getId()] === $location->getId()) {
                $sites[$location->getId()] = $this->emptySite($location);
            }
        }
        foreach ($locations as $location) {
            $this->addLocationFacts($sites[$rootOf[$location->getId()]], $location, $viewer);
        }

        $assetSite = $this->collectAssets($tenant, $rootOf, $sites);
        $this->collectRisks($tenant, $rootOf, $assetSite, $sites);
        $this->collectIncidents($tenant, $assetSite, $sites);
        $tags = $this->siteTags(array_keys($sites));

        $settings = LocationMapSettings::of($tenant);
        $rows = [];
        foreach ($sites as $id => $site) {
            $rows[] = $this->finish($site, $settings) + ['tags' => $tags[$id] ?? []];
        }
        return $rows;
    }

    /** @param array<int, Location> $byId */
    private function rootId(Location $location, array $byId): int
    {
        $current = $location;
        $guard = 0;
        while (($parent = $current->getParentLocation()) !== null && isset($byId[$parent->getId()]) && $guard++ < 50) {
            $current = $parent;
        }
        return (int) $current->getId();
    }

    /** @return array<string, mixed> */
    private function emptySite(Location $root): array
    {
        return [
            'location' => $root,
            'cia' => [0, 0, 0],
            'assets' => 0,
            'risks' => [],
            'incidents' => [],
            'levels' => [],
            'access_missing' => 0,
            'camera_missing' => 0,
            'mine' => false,
            'children' => 0,
        ];
    }

    /** @param array<string, mixed> $site */
    private function addLocationFacts(array &$site, Location $location, ?User $viewer): void
    {
        if ($location !== $site['location']) {
            ++$site['children'];
        }
        $level = $location->getSecurityLevel();
        $site['levels'][] = $level;
        if (in_array($level, self::CONTROLLED_LEVELS, true)
            && !$location->requiresBadgeAccess()
            && trim((string) $location->getAccessControlSystem()) === '') {
            ++$site['access_missing'];
        }
        if ($level === 'secure' && !$location->isCameraMonitored()) {
            ++$site['camera_missing'];
        }
        if ($viewer !== null && $location->getResponsiblePersonUser()?->getId() === $viewer->getId()) {
            $site['mine'] = true;
        }
    }

    /**
     * @param array<int, int> $rootOf
     * @param array<int, array<string, mixed>> $sites
     * @return array<int, int> asset id => site id
     */
    private function collectAssets(Tenant $tenant, array $rootOf, array &$sites): array
    {
        $rows = $this->em->createQueryBuilder()
            ->select('a.id AS id', 'IDENTITY(a.physicalLocation) AS loc')
            ->addSelect('a.confidentialityValue AS c', 'a.integrityValue AS i', 'a.availabilityValue AS av')
            ->from(Asset::class, 'a')
            ->where('a.tenant = :tenant')
            ->andWhere('a.physicalLocation IS NOT NULL')
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getArrayResult();

        $assetSite = [];
        foreach ($rows as $row) {
            $siteId = $rootOf[(int) $row['loc']] ?? null;
            if ($siteId === null) {
                continue;
            }
            $assetSite[(int) $row['id']] = $siteId;
            $site = &$sites[$siteId];
            ++$site['assets'];
            $site['cia'] = [
                max($site['cia'][0], (int) $row['c']),
                max($site['cia'][1], (int) $row['i']),
                max($site['cia'][2], (int) $row['av']),
            ];
            unset($site);
        }
        return $assetSite;
    }

    /**
     * @param array<int, int> $rootOf
     * @param array<int, int> $assetSite
     * @param array<int, array<string, mixed>> $sites
     */
    private function collectRisks(Tenant $tenant, array $rootOf, array $assetSite, array &$sites): void
    {
        /** @var list<Risk> $risks */
        $risks = $this->em->createQueryBuilder()
            ->select('r')
            ->from(Risk::class, 'r')
            ->leftJoin('r.asset', 'a')
            ->where('r.tenant = :tenant')
            ->andWhere('r.location IS NOT NULL OR a.physicalLocation IS NOT NULL')
            ->andWhere('r.status IS NULL OR r.status <> :closed')
            ->setParameter('tenant', $tenant)
            ->setParameter('closed', RiskStatus::Closed)
            ->getQuery()
            ->getResult();

        foreach ($risks as $risk) {
            $siteIds = [];
            if ($risk->getLocation() !== null && isset($rootOf[$risk->getLocation()->getId()])) {
                $siteIds[] = $rootOf[$risk->getLocation()->getId()];
            }
            $assetId = $risk->getAsset()?->getId();
            if ($assetId !== null && isset($assetSite[$assetId])) {
                $siteIds[] = $assetSite[$assetId];
            }
            foreach (array_unique($siteIds) as $siteId) {
                $sites[$siteId]['risks'][] = $risk;
            }
        }
    }

    /**
     * @param array<int, int> $assetSite
     * @param array<int, array<string, mixed>> $sites
     */
    private function collectIncidents(Tenant $tenant, array $assetSite, array &$sites): void
    {
        if ($assetSite === []) {
            return;
        }
        $rows = $this->em->createQueryBuilder()
            ->select('i.id AS id', 'a.id AS asset')
            ->from(Incident::class, 'i')
            ->join('i.affectedAssets', 'a')
            ->where('i.tenant = :tenant')
            ->andWhere('i.status NOT IN (:done)')
            ->setParameter('tenant', $tenant)
            ->setParameter('done', [IncidentStatus::Resolved->value, IncidentStatus::Closed->value])
            ->getQuery()
            ->getArrayResult();

        foreach ($rows as $row) {
            $siteId = $assetSite[(int) $row['asset']] ?? null;
            if ($siteId !== null) {
                $sites[$siteId]['incidents'][(int) $row['id']] = true;
            }
        }
    }

    /**
     * @param list<int> $siteIds
     * @return array<int, list<string>> site id => tag names
     */
    private function siteTags(array $siteIds): array
    {
        if ($siteIds === []) {
            return [];
        }
        $rows = $this->em->createQueryBuilder()
            ->select('et.entityId AS id', 't.name AS name')
            ->from(EntityTag::class, 'et')
            ->join('et.tag', 't')
            ->where('et.entityClass = :class')
            ->andWhere('et.entityId IN (:ids)')
            ->andWhere('et.taggedUntil IS NULL')
            ->setParameter('class', Location::class)
            ->setParameter('ids', $siteIds)
            ->getQuery()
            ->getArrayResult();

        $tags = [];
        foreach ($rows as $row) {
            $tags[(int) $row['id']][] = (string) $row['name'];
        }
        return $tags;
    }

    /**
     * Open risks whose treatment plan lowers the score, biggest effect first.
     *
     * @param list<Risk> $risks
     * @return list<array{id: int|null, title: string, from: int, to: int, impact: int}>
     */
    private function levers(array $risks, int $protection): array
    {
        $levers = [];
        foreach ($risks as $risk) {
            $from = $risk->getInherentRiskLevel();
            $to = $risk->getResidualRiskLevel();
            if ($risk->getStatus()?->isHandled() === true || $to >= $from) {
                continue;
            }
            $levers[] = [
                'id' => $risk->getId(),
                'title' => (string) $risk->getTitle(),
                'from' => $from,
                'to' => $to,
                'impact' => ($from - $to) * max(1, $protection),
            ];
        }
        usort($levers, static fn (array $a, array $b): int => $b['impact'] <=> $a['impact']);
        return array_slice($levers, 0, 3);
    }

    public static function currentScore(Risk $risk): int
    {
        return $risk->getStatus()?->isHandled() === true
            ? $risk->getResidualRiskLevel()
            : $risk->getInherentRiskLevel();
    }

    /**
     * @param array<string, mixed> $site
     * @return array<string, mixed>
     */
    private function finish(array $site, LocationMapSettings $settings): array
    {
        /** @var Location $root */
        $root = $site['location'];
        /** @var list<Risk> $risks */
        $risks = $site['risks'];
        usort($risks, static fn (Risk $a, Risk $b): int => self::currentScore($b) <=> self::currentScore($a));

        $score = $risks !== [] ? self::currentScore($risks[0]) : 0;
        $protection = max($site['cia']);
        $controlled = array_intersect($site['levels'], self::CONTROLLED_LEVELS) !== [];
        $unhandled = count(array_filter($risks, static fn (Risk $r): bool => $r->getStatus()?->isHandled() !== true));

        $checks = [
            'owner' => $root->getResponsiblePersonUser() !== null
                || $root->getResponsiblePersonRef() !== null,
            'deputy' => ($controlled || $protection >= 3) ? !$root->getResponsibleDeputyPersons()->isEmpty() : null,
            'access_control' => $controlled ? $site['access_missing'] === 0 : null,
            'camera' => in_array('secure', $site['levels'], true) ? $site['camera_missing'] === 0 : null,
            'risks_treated' => $risks !== [] ? $unhandled === 0 : null,
            'incidents_closed' => $site['assets'] > 0 ? $site['incidents'] === [] : null,
        ];
        if ($root->isRegion()) {
            // No building: physical access checks do not apply.
            $checks['access_control'] = null;
            $checks['camera'] = null;
        }
        foreach ($settings->disabledChecks as $check) {
            // Switched off by the tenant admin: neither a gap nor a pass.
            $checks[$check] = null;
        }
        $applicable = array_filter($checks, static fn (?bool $ok): bool => $ok !== null);
        $gaps = array_keys(array_filter($applicable, static fn (bool $ok): bool => !$ok));

        return [
            'id' => $root->getId(),
            'name' => (string) $root->getName(),
            'code' => $root->getCode(),
            'type' => $root->getLocationType(),
            'area' => $root->isRegion() ? $this->geocoder->area($root->getCountry()) : null,
            'postalCode' => $root->getPostalCode(),
            'city' => $root->getCity(),
            'country' => $root->getCountry(),
            'lat' => $settings->display($root->getLatitude()),
            'lng' => $settings->display($root->getLongitude()),
            'geoSource' => $root->getGeoSource(),
            'securityLevel' => $root->getSecurityLevel(),
            'children' => $site['children'],
            'protection' => $protection,
            'cia' => $site['cia'],
            'assets' => $site['assets'],
            'risks' => count($risks),
            'highRisks' => count(array_filter($risks, static fn (Risk $r): bool => self::currentScore($r) >= RiskMatrixThresholds::HIGH_MIN)),
            'score' => $score,
            'riskBand' => $risks !== [] ? RiskMatrixThresholds::classify($score) : 'none',
            'checks' => $checks,
            'checksApplicable' => count($applicable),
            'gaps' => $gaps,
            'gapBand' => match (true) {
                count($gaps) >= 3 => 'danger',
                $gaps !== [] => 'warn',
                default => 'ok',
            },
            // The owner check always applies, so the rate is always defined.
            'checksRate' => (int) round(100 * (count($applicable) - count($gaps)) / count($applicable)),
            'openIncidents' => count($site['incidents']),
            'levers' => $this->levers($risks, $protection),
            'ale' => array_sum(array_map(static fn (Risk $r): int => (int) $r->getAnnualLossExpectancy(), $risks)),
            'topRisks' => array_map(static fn (Risk $r): array => [
                'id' => $r->getId(),
                'title' => (string) $r->getTitle(),
                'score' => self::currentScore($r),
            ], array_slice($risks, 0, 3)),
            'ownerName' => $root->getResponsiblePersonUser()?->getFullName()
                ?? $root->getResponsiblePersonRef()?->getDisplayName(),
            'mine' => $site['mine'],
            'active' => $root->isActive() !== false,
        ];
    }
}
