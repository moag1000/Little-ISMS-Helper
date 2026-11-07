<?php

declare(strict_types=1);

namespace App\Service\Location;

use App\Entity\Asset;
use App\Entity\BusinessContinuityPlan;
use App\Entity\BusinessProcess;
use App\Entity\CrisisTeam;
use App\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Event mode of the location map: a power outage, storm or incident hits a
 * country. Lists the sites there, their assets, the business processes those
 * assets support, the BC plans of the processes and the crisis teams to
 * activate (ISO 22301 8.4.2, BSI 200-4). Selection by country only, no free
 * drawing. The crisis teams are those assigned to the affected plans; when
 * none is assigned, every active crisis team of the organisation.
 */
class LocationEventView
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LocationPostureService $posture,
        private readonly LocationGeocoder $geocoder,
    ) {
    }

    /**
     * Countries that have at least one active site, for the event picker.
     *
     * @param list<array<string, mixed>> $sites posture rows
     * @return array<string, int> ISO alpha-2 => number of sites
     */
    public function countries(array $sites): array
    {
        $out = [];
        foreach ($sites as $site) {
            $code = $this->codeOf($site);
            if ($code !== null && ($site['active'] ?? true)) {
                $out[$code] = ($out[$code] ?? 0) + 1;
            }
        }
        ksort($out);

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $sites posture rows
     * @return array{country: string, sites: list<array<string, mixed>>, assets: int,
     *     processes: list<array{id: int, name: string, criticality: ?string}>,
     *     plans: list<array{id: int, name: string, status: string}>,
     *     teams: list<array{id: int, name: string, assigned: bool}>}|null
     */
    public function forCountry(Tenant $tenant, array $sites, string $country): ?array
    {
        $country = strtoupper($country);
        $hit = array_values(array_filter(
            $sites,
            fn (array $s): bool => ($s['active'] ?? true) && $this->codeOf($s) === $country,
        ));
        if ($hit === []) {
            return null;
        }
        $siteIds = array_map(static fn (array $s): int => (int) $s['id'], $hit);
        $locationIds = array_keys(array_filter(
            $this->posture->siteOfLocations($tenant),
            static fn (int $site): bool => in_array($site, $siteIds, true),
        ));

        $assets = (int) $this->em->createQueryBuilder()
            ->select('COUNT(a.id)')
            ->from(Asset::class, 'a')
            ->where('a.tenant = :tenant')
            ->andWhere('a.physicalLocation IN (:locations)')
            ->setParameter('tenant', $tenant)
            ->setParameter('locations', $locationIds)
            ->getQuery()
            ->getSingleScalarResult();

        $processes = $this->em->createQueryBuilder()
            ->select('DISTINCT p.id AS id', 'p.name AS name', 'p.criticality AS criticality')
            ->from(BusinessProcess::class, 'p')
            ->join('p.supportingAssets', 'a')
            ->where('p.tenant = :tenant')
            ->andWhere('a.physicalLocation IN (:locations)')
            ->setParameter('tenant', $tenant)
            ->setParameter('locations', $locationIds)
            ->orderBy('p.name', 'ASC')
            ->getQuery()
            ->getArrayResult();
        $processIds = array_map(static fn (array $p): int => (int) $p['id'], $processes);

        /** @var list<BusinessContinuityPlan> $plans */
        $plans = $processIds === [] ? [] : $this->em->createQueryBuilder()
            ->select('bc')
            ->from(BusinessContinuityPlan::class, 'bc')
            ->where('bc.tenant = :tenant')
            ->andWhere('bc.businessProcess IN (:ids)')
            ->andWhere('bc.status <> :archived')
            ->setParameter('tenant', $tenant)
            ->setParameter('ids', $processIds)
            ->setParameter('archived', 'archived')
            ->orderBy('bc.name', 'ASC')
            ->getQuery()
            ->getResult();

        $teams = [];
        foreach ($plans as $plan) {
            foreach ($plan->getCrisisTeams() as $team) {
                if ($team->isActive()) {
                    $teams[$team->getId()] = ['id' => (int) $team->getId(), 'name' => (string) $team->getTeamName(), 'assigned' => true];
                }
            }
        }
        if ($teams === []) {
            /** @var list<CrisisTeam> $all */
            $all = $this->em->getRepository(CrisisTeam::class)->findBy(['tenant' => $tenant, 'isActive' => true], ['teamName' => 'ASC']);
            foreach ($all as $team) {
                $teams[$team->getId()] = ['id' => (int) $team->getId(), 'name' => (string) $team->getTeamName(), 'assigned' => false];
            }
        }

        return [
            'country' => $country,
            'sites' => $hit,
            'assets' => $assets,
            'processes' => array_map(static fn (array $p): array => [
                'id' => (int) $p['id'],
                'name' => (string) $p['name'],
                'criticality' => $p['criticality'],
            ], $processes),
            'plans' => array_map(static fn (BusinessContinuityPlan $p): array => [
                'id' => (int) $p->getId(),
                'name' => (string) $p->getName(),
                'status' => (string) $p->getStatus(),
            ], $plans),
            'teams' => array_values($teams),
        ];
    }

    /** @param array<string, mixed> $site */
    private function codeOf(array $site): ?string
    {
        $area = $this->geocoder->area($site['country'] ?? null);

        return in_array($area, [null, LocationGeocoder::AREA_EU, LocationGeocoder::AREA_WORLD], true) ? null : $area;
    }
}
