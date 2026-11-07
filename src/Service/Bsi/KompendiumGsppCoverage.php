<?php

declare(strict_types=1);

namespace App\Service\Bsi;

use App\Entity\ComplianceMapping;
use App\Entity\ComplianceRequirement;
use App\Entity\ComplianceRequirementFulfillment;
use App\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * How far the mapping IT-Grundschutz-Kompendium 2023 → Grundschutz++ reaches.
 *
 * Three sources, in this order of trust:
 *  - the BSI mapping (`bsi-it-grundschutz_to_bsi-gspp_official_v1`, BSI draft),
 *  - the app's supplement for requirements the BSI has not mapped
 *    (`bsi-it-grundschutz_to_bsi-gspp_derived_v1`, two independent reviews,
 *    heuristic tier: inheritance proposes these pairs for review),
 *  - requirements both reviews found without counterpart in Grundschutz++
 *    (review fixture, no mapping row).
 *
 * The rest is open. Mapping hub and KPI catalogue read the numbers from here.
 */
class KompendiumGsppCoverage
{
    public const string KOMPENDIUM = 'BSI_GRUNDSCHUTZ';
    public const string GSPP = 'BSI-GSPP';
    public const string REVIEW_FIXTURE = 'fixtures/library/mappings/panel_verdicts/bsi-it-grundschutz_to_bsi-gspp_review_v1.json';

    /** @var array<string, mixed>|false|null memo for the request (false = not loaded) */
    private array|false|null $summary = null;

    /** @var array<int, bool>|null */
    private ?array $sources = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    /**
     * Catalogue coverage, or null while one of the two catalogues is not loaded.
     *
     * @return array{total: int, official: int, derived: int, no_counterpart: int, open: int, with_target: int, percent: int, reviewed_percent: int}|null
     */
    public function summary(): ?array
    {
        if ($this->summary === null) {
            $this->summary = $this->compute() ?? false;
        }

        return $this->summary === false ? null : $this->summary;
    }

    /**
     * The organisation's Kompendium state that carries over: fulfilled
     * Kompendium requirements (applicable, > 0 %) and how many of them have a
     * Grundschutz++ counterpart. Null without catalogues; fulfilled = 0 when the
     * organisation has not recorded any Kompendium fulfilment.
     *
     * @return array{fulfilled: int, transferable: int, percent: int|null}|null
     */
    public function forTenant(Tenant $tenant): ?array
    {
        if ($this->summary() === null) {
            return null;
        }

        $fulfilled = $this->em->createQueryBuilder()
            ->select('r.id')
            ->from(ComplianceRequirementFulfillment::class, 'f')
            ->join('f.requirement', 'r')
            ->join('r.framework', 'fw')
            ->where('f.tenant = :tenant')
            ->andWhere('fw.code = :kompendium')
            ->andWhere('f.applicable = true')
            ->andWhere('f.fulfillmentPercentage > 0')
            ->setParameter('tenant', $tenant)
            ->setParameter('kompendium', self::KOMPENDIUM)
            ->getQuery()
            ->getSingleColumnResult();
        $fulfilled = array_map('intval', $fulfilled);
        if ($fulfilled === []) {
            return ['fulfilled' => 0, 'transferable' => 0, 'percent' => null];
        }

        $mapped = array_flip(array_keys($this->mappedSources()));
        $transferable = count(array_filter($fulfilled, static fn (int $id): bool => isset($mapped[$id])));

        return [
            'fulfilled' => count($fulfilled),
            'transferable' => $transferable,
            'percent' => (int) round(100 * $transferable / count($fulfilled)),
        ];
    }

    /** @return array{total: int, official: int, derived: int, no_counterpart: int, open: int, with_target: int, percent: int, reviewed_percent: int}|null */
    private function compute(): ?array
    {
        $ids = $this->em->createQueryBuilder()
            ->select('r.id', 'r.requirementId')
            ->from(ComplianceRequirement::class, 'r')
            ->join('r.framework', 'fw')
            ->where('fw.code = :kompendium')
            ->setParameter('kompendium', self::KOMPENDIUM)
            ->getQuery()
            ->getArrayResult();
        $total = count($ids);
        if ($total === 0 || !$this->gsppLoaded()) {
            return null;
        }

        $sources = $this->mappedSources();
        $official = count(array_filter($sources, static fn (bool $isOfficial): bool => $isOfficial));
        $derived = count($sources) - $official;

        $none = array_flip($this->noCounterpartIds());
        $noCounterpart = 0;
        foreach ($ids as $row) {
            if (!isset($sources[(int) $row['id']]) && isset($none[(string) $row['requirementId']])) {
                $noCounterpart++;
            }
        }

        $withTarget = $official + $derived;

        return [
            'total' => $total,
            'official' => $official,
            'derived' => $derived,
            'no_counterpart' => $noCounterpart,
            'open' => max(0, $total - $withTarget - $noCounterpart),
            'with_target' => $withTarget,
            'percent' => (int) round(100 * $withTarget / $total),
            'reviewed_percent' => (int) round(100 * ($withTarget + $noCounterpart) / $total),
        ];
    }

    /**
     * Kompendium requirement ids with an operational mapping to Grundschutz++,
     * value true when the BSI mapping covers it.
     *
     * @return array<int, bool>
     */
    private function mappedSources(): array
    {
        if ($this->sources !== null) {
            return $this->sources;
        }
        $rows = $this->em->createQueryBuilder()
            ->select('IDENTITY(m.sourceRequirement) AS sid', 'm.provenanceSource AS prov')
            ->from(ComplianceMapping::class, 'm')
            ->join('m.sourceRequirement', 's')
            ->join('s.framework', 'sf')
            ->join('m.targetRequirement', 't')
            ->join('t.framework', 'tf')
            ->where('sf.code = :kompendium')
            ->andWhere('tf.code = :gspp')
            ->andWhere('m.lifecycleState IN (:states)')
            ->setParameter('kompendium', self::KOMPENDIUM)
            ->setParameter('gspp', self::GSPP)
            ->setParameter('states', ComplianceMapping::OPERATIONAL_STATES)
            ->getQuery()
            ->getArrayResult();

        $out = [];
        foreach ($rows as $row) {
            $sid = (int) $row['sid'];
            $out[$sid] = ($out[$sid] ?? false) || $row['prov'] === IsoToBsiGapService::PROVENANCE_OFFICIAL_CRT;
        }

        return $this->sources = $out;
    }

    private function gsppLoaded(): bool
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(r.id)')
            ->from(ComplianceRequirement::class, 'r')
            ->join('r.framework', 'fw')
            ->where('fw.code = :gspp')
            ->setParameter('gspp', self::GSPP)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    /** @return list<string> */
    private function noCounterpartIds(): array
    {
        $path = $this->projectDir . '/' . self::REVIEW_FIXTURE;
        if (!is_file($path)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) && is_array($data['no_counterpart'] ?? null)
            ? array_values(array_map('strval', $data['no_counterpart']))
            : [];
    }
}
