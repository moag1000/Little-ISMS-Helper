<?php

declare(strict_types=1);

namespace App\Service\Location;

use App\Entity\BusinessContinuityPlan;
use App\Entity\BusinessProcess;
use App\Entity\InternalAudit;
use App\Entity\Tenant;
use App\Privacy\GdprTransferZones;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The three further layers of the location map, derived from existing records
 * and placed on the same site pins as the posture rows:
 *
 *   - emergency (BCM, ISO 22301 8.4/8.5): critical business processes whose
 *     supporting assets sit at the site, their BC plan and its last test.
 *     danger = a critical process without a usable plan, warn = plan only in
 *     draft or not tested within 12 months, ok = every critical process has
 *     an active plan tested within 12 months, none = no critical process.
 *   - audit (ISO 27001 9.2, IAF MD 1 multi-site): last and next internal
 *     audit linked to a location of the site. ok = audited within 12 months,
 *     warn = audited within 36 months or an audit is planned, danger = no
 *     audit for 36 months and none planned.
 *   - thirdcountry (GDPR Art. 44–49): GDPR transfer zone of the site's
 *     country. ok = EU/EEA or adequacy, warn = USA (DPF) or another third
 *     country (intra-group transfer needs safeguards), none = unknown.
 *
 * Bands use the map's vocabulary ok / warn / danger / none.
 */
class LocationLayers
{
    public const string EMERGENCY = 'emergency';
    public const string AUDIT = 'audit';
    public const string THIRD_COUNTRY = 'thirdcountry';

    /** BIA criticality that needs a tested BC plan. */
    private const array CRITICAL = ['critical', 'high'];
    private const array DONE = ['conducted', 'reported', 'approved', 'closed', 'completed'];
    private const array UPCOMING = ['planned', 'in_progress'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LocationPostureService $posture,
        private readonly LocationGeocoder $geocoder,
    ) {
    }

    /**
     * Facts of one layer per site.
     *
     * @param list<array<string, mixed>> $sites posture rows
     * @return array<int, array<string, mixed>> site id => facts incl. 'band'
     */
    public function emergency(Tenant $tenant, array $sites, ?DateTimeInterface $now = null): array
    {
        $now = DateTimeImmutable::createFromInterface($now ?? new DateTimeImmutable());
        $rootOf = $this->posture->siteOfLocations($tenant);
        $rows = $this->em->createQueryBuilder()
            ->select('p.id AS id', 'p.name AS name', 'p.criticality AS criticality', 'IDENTITY(a.physicalLocation) AS loc')
            ->from(BusinessProcess::class, 'p')
            ->join('p.supportingAssets', 'a')
            ->where('p.tenant = :tenant')
            ->andWhere('a.physicalLocation IS NOT NULL')
            ->andWhere('p.criticality IN (:critical)')
            ->setParameter('tenant', $tenant)
            ->setParameter('critical', self::CRITICAL)
            ->getQuery()
            ->getArrayResult();

        $processes = [];
        foreach ($rows as $row) {
            $siteId = $rootOf[(int) $row['loc']] ?? null;
            if ($siteId !== null) {
                $processes[$siteId][(int) $row['id']] = ['id' => (int) $row['id'], 'name' => (string) $row['name'], 'criticality' => $row['criticality']];
            }
        }
        $plans = $this->plans($tenant, array_unique(array_merge([], ...array_map('array_keys', array_values($processes)))));

        $out = [];
        foreach ($sites as $site) {
            $list = [];
            $missing = $untested = 0;
            foreach ($processes[$site['id']] ?? [] as $pid => $process) {
                $plan = $plans[$pid] ?? null;
                $state = match (true) {
                    $plan === null => 'missing',
                    $plan['status'] !== 'active' && $plan['status'] !== 'under_review' => 'draft',
                    $plan['tested'] === null || $plan['tested'] < $now->modify('-12 months') => 'untested',
                    default => 'ok',
                };
                $missing += $state === 'missing' ? 1 : 0;
                $untested += in_array($state, ['draft', 'untested'], true) ? 1 : 0;
                $list[] = $process + [
                    'state' => $state,
                    'planId' => $plan['id'] ?? null,
                    'planName' => $plan['name'] ?? null,
                    'tested' => $plan !== null && $plan['tested'] !== null ? $plan['tested']->format('Y-m-d') : null,
                    'alternativeSite' => $plan['alt'] ?? null,
                ];
            }
            usort($list, static fn (array $a, array $b): int => [$a['state'] === 'ok', $a['name']] <=> [$b['state'] === 'ok', $b['name']]);
            $out[$site['id']] = [
                'band' => match (true) {
                    $list === [] => 'none',
                    $missing > 0 => 'danger',
                    $untested > 0 => 'warn',
                    default => 'ok',
                },
                'processes' => $list,
                'missing' => $missing,
                'untested' => $untested,
            ];
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $sites posture rows
     * @return array<int, array<string, mixed>> site id => facts incl. 'band'
     */
    public function audit(Tenant $tenant, array $sites, ?DateTimeInterface $now = null): array
    {
        $now = DateTimeImmutable::createFromInterface($now ?? new DateTimeImmutable());
        $today = $now->setTime(0, 0);
        $rootOf = $this->posture->siteOfLocations($tenant);
        $rows = $this->em->createQueryBuilder()
            ->select('ia.id AS id', 'ia.title AS title', 'ia.status AS status', 'ia.plannedDate AS planned')
            ->addSelect('ia.actualDate AS actual', 'ia.reportDate AS reported', 'IDENTITY(ia.location) AS loc')
            ->from(InternalAudit::class, 'ia')
            ->where('ia.tenant = :tenant')
            ->andWhere('ia.location IS NOT NULL')
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getArrayResult();

        $last = $next = [];
        foreach ($rows as $row) {
            $siteId = $rootOf[(int) $row['loc']] ?? null;
            if ($siteId === null) {
                continue;
            }
            $entry = ['id' => (int) $row['id'], 'title' => (string) $row['title']];
            if (in_array($row['status'], self::DONE, true)) {
                $date = $row['actual'] ?? $row['reported'] ?? $row['planned'];
                if ($date instanceof DateTimeInterface && (!isset($last[$siteId]) || $date > $last[$siteId]['date'])) {
                    $last[$siteId] = $entry + ['date' => $date];
                }
            } elseif (in_array($row['status'], self::UPCOMING, true) && $row['planned'] instanceof DateTimeInterface
                && $row['planned'] >= $today && (!isset($next[$siteId]) || $row['planned'] < $next[$siteId]['date'])) {
                $next[$siteId] = $entry + ['date' => $row['planned']];
            }
        }

        $out = [];
        foreach ($sites as $site) {
            $l = $last[$site['id']] ?? null;
            $n = $next[$site['id']] ?? null;
            $months = $l !== null ? self::monthsBetween($l['date'], $now) : null;
            $out[$site['id']] = [
                'band' => match (true) {
                    $months !== null && $months < 12 => 'ok',
                    ($months !== null && $months < 36) || $n !== null => 'warn',
                    default => 'danger',
                },
                'last' => $l !== null ? ['id' => $l['id'], 'title' => $l['title'], 'date' => $l['date']->format('Y-m-d')] : null,
                'next' => $n !== null ? ['id' => $n['id'], 'title' => $n['title'], 'date' => $n['date']->format('Y-m-d')] : null,
                'months' => $months,
            ];
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $sites posture rows
     * @return array<int, array{band: string, zone: string, code: ?string}>
     */
    public function thirdCountry(array $sites): array
    {
        $out = [];
        foreach ($sites as $site) {
            $area = $this->geocoder->area($site['country']);
            $zone = match ($area) {
                LocationGeocoder::AREA_EU => GdprTransferZones::EEA,
                null, LocationGeocoder::AREA_WORLD => GdprTransferZones::UNKNOWN,
                default => GdprTransferZones::zoneOf($area),
            };
            $out[$site['id']] = [
                'band' => match ($zone) {
                    GdprTransferZones::EEA, GdprTransferZones::ADEQUACY => 'ok',
                    GdprTransferZones::DPF, GdprTransferZones::THIRD => 'warn',
                    default => 'none',
                },
                'zone' => $zone,
                'code' => in_array($area, [null, LocationGeocoder::AREA_EU, LocationGeocoder::AREA_WORLD], true) ? null : $area,
            ];
        }

        return $out;
    }

    /**
     * Non-archived BC plans of the business processes whose supporting assets
     * sit at the given site (or one of its sub-locations), for the exercise
     * prefill from the map.
     *
     * @return list<BusinessContinuityPlan>
     */
    public function plansAtSite(Tenant $tenant, int $siteId): array
    {
        $locations = array_keys(array_filter(
            $this->posture->siteOfLocations($tenant),
            static fn (int $site): bool => $site === $siteId,
        ));
        if ($locations === []) {
            return [];
        }

        return $this->em->createQueryBuilder()
            ->select('DISTINCT bc')
            ->from(BusinessContinuityPlan::class, 'bc')
            ->join('bc.businessProcess', 'p')
            ->join('p.supportingAssets', 'a')
            ->where('bc.tenant = :tenant')
            ->andWhere('a.physicalLocation IN (:locations)')
            ->andWhere('bc.status <> :archived')
            ->setParameter('tenant', $tenant)
            ->setParameter('locations', $locations)
            ->setParameter('archived', 'archived')
            ->orderBy('bc.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Whole months from $from to $to (never negative).
     */
    public static function monthsBetween(DateTimeInterface $from, DateTimeInterface $to): int
    {
        $diff = $from->diff($to);

        return $diff->invert === 1 ? 0 : $diff->y * 12 + $diff->m;
    }

    /**
     * Best plan per process: active before under review before draft, then the
     * most recent test. Archived plans do not count.
     *
     * @param list<int> $processIds
     * @return array<int, array{id: int, name: string, status: string, tested: ?DateTimeImmutable, alt: ?string}>
     */
    private function plans(Tenant $tenant, array $processIds): array
    {
        if ($processIds === []) {
            return [];
        }
        $rows = $this->em->createQueryBuilder()
            ->select('bc.id AS id', 'bc.name AS name', 'bc.status AS status', 'bc.lastTested AS tested')
            ->addSelect('bc.alternativeSite AS alt', 'IDENTITY(bc.businessProcess) AS process')
            ->from(BusinessContinuityPlan::class, 'bc')
            ->where('bc.tenant = :tenant')
            ->andWhere('bc.businessProcess IN (:ids)')
            ->andWhere('bc.status <> :archived')
            ->setParameter('tenant', $tenant)
            ->setParameter('ids', $processIds)
            ->setParameter('archived', 'archived')
            ->getQuery()
            ->getArrayResult();

        $rank = ['active' => 0, 'under_review' => 1, 'draft' => 2];
        $best = [];
        foreach ($rows as $row) {
            $pid = (int) $row['process'];
            $tested = $row['tested'] instanceof DateTimeInterface ? DateTimeImmutable::createFromInterface($row['tested']) : null;
            $candidate = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'status' => (string) $row['status'],
                'tested' => $tested,
                'alt' => trim((string) $row['alt']) !== '' ? trim((string) $row['alt']) : null,
            ];
            $current = $best[$pid] ?? null;
            if ($current === null
                || [$rank[$candidate['status']] ?? 3, -($tested?->getTimestamp() ?? 0)]
                    < [$rank[$current['status']] ?? 3, -($current['tested']?->getTimestamp() ?? 0)]) {
                $best[$pid] = $candidate;
            }
        }

        return $best;
    }
}
