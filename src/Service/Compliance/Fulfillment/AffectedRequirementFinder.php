<?php

declare(strict_types=1);

namespace App\Service\Compliance\Fulfillment;

use App\Entity\ComplianceMapping;
use App\Entity\ComplianceRequirement;
use App\Entity\ComplianceRequirementFulfillment;
use App\Entity\Control;
use App\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Which requirements does a control change touch? The inverse of
 * {@see FulfillmentRuleEngine::controlsFor()}, plus the requirements that
 * inherit from those through approved mappings — returned in waves so that a
 * mapping target is recomputed after its source.
 */
class AffectedRequirementFinder
{
    private const MAX_WAVES = 3;

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @param list<Control> $controls controls of one organisation
     * @return list<list<ComplianceRequirement>> waves: direct first, then mapping targets
     */
    public function wavesForControls(array $controls): array
    {
        if ($controls === []) {
            return [];
        }

        $keys = [];
        foreach ($controls as $control) {
            $keys[FulfillmentRuleEngine::normalise((string) $control->getControlId())] = true;
        }
        $keys = array_keys($keys);

        $direct = [];
        foreach ($this->identityRequirements($keys) as $r) {
            $direct[(int) $r->getId()] = $r;
        }
        foreach ($this->catalogueRequirements($keys) as $r) {
            $direct[(int) $r->getId()] = $r;
        }
        foreach ($this->mappedRequirements($controls) as $r) {
            $direct[(int) $r->getId()] = $r;
        }

        return $this->withMappingWaves(array_values($direct));
    }

    /**
     * @param list<ComplianceRequirement> $requirements
     * @return list<list<ComplianceRequirement>>
     */
    public function withMappingWaves(array $requirements): array
    {
        $waves = [];
        $seen = [];
        $wave = [];
        foreach ($requirements as $r) {
            if (!isset($seen[(int) $r->getId()])) {
                $seen[(int) $r->getId()] = true;
                $wave[] = $r;
            }
        }

        for ($i = 0; $wave !== [] && $i < self::MAX_WAVES; $i++) {
            $waves[] = $wave;
            $next = [];
            foreach ($this->mappingNeighbours($wave) as $r) {
                if (!isset($seen[(int) $r->getId()])) {
                    $seen[(int) $r->getId()] = true;
                    $next[] = $r;
                }
            }
            $wave = $next;
        }

        return $waves;
    }

    /**
     * Framework ids the organisation works with (it has at least one fulfilment row).
     *
     * @return array<int, true>
     */
    public function frameworkIdsInUse(Tenant $tenant): array
    {
        $ids = $this->em->createQueryBuilder()
            ->select('DISTINCT IDENTITY(r.framework) AS fw')
            ->from(ComplianceRequirementFulfillment::class, 'f')
            ->join('f.requirement', 'r')
            ->where('f.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getSingleColumnResult();

        return array_fill_keys(array_map('intval', $ids), true);
    }

    /**
     * @param list<string> $keys normalised control ids
     * @return list<ComplianceRequirement>
     */
    private function identityRequirements(array $keys): array
    {
        return $this->em->createQueryBuilder()
            ->select('r')
            ->from(ComplianceRequirement::class, 'r')
            ->join('r.framework', 'fw')
            ->where('fw.code = :iso')
            ->andWhere('r.requirementId IN (:ids)')
            ->setParameter('iso', 'ISO27001')
            ->setParameter('ids', array_map(static fn (string $k): string => 'A.' . $k, $keys))
            ->getQuery()
            ->getResult();
    }

    /**
     * Catalogue rows whose `iso_controls` list names one of the controls. A LIKE
     * on the JSON text finds candidates; the rule engine reads the list exactly.
     *
     * @param list<string> $keys
     * @return list<ComplianceRequirement>
     */
    private function catalogueRequirements(array $keys): array
    {
        $qb = $this->em->createQueryBuilder()->select('r')->from(ComplianceRequirement::class, 'r');
        $or = $qb->expr()->orX();
        foreach (array_values($keys) as $i => $key) {
            $or->add($qb->expr()->like('r.dataSourceMapping', ':k' . $i . 'a'));
            $or->add($qb->expr()->like('r.dataSourceMapping', ':k' . $i . 'b'));
            $qb->setParameter('k' . $i . 'a', '%"' . $key . '"%');
            $qb->setParameter('k' . $i . 'b', '%"A.' . $key . '"%');
        }

        $candidates = $qb->where($or)->getQuery()->getResult();
        $wanted = array_fill_keys($keys, true);

        return array_values(array_filter($candidates, static function (ComplianceRequirement $r) use ($wanted): bool {
            $ids = $r->getDataSourceMapping()['iso_controls'] ?? [];
            foreach (is_array($ids) ? $ids : [$ids] as $id) {
                if (isset($wanted[FulfillmentRuleEngine::normalise((string) $id)])) {
                    return true;
                }
            }

            return false;
        }));
    }

    /**
     * @param list<Control> $controls
     * @return list<ComplianceRequirement>
     */
    private function mappedRequirements(array $controls): array
    {
        $ids = array_values(array_filter(array_map(static fn (Control $c): ?int => $c->getId(), $controls)));
        if ($ids === []) {
            return [];
        }

        return $this->em->createQueryBuilder()
            ->select('DISTINCT r')
            ->from(ComplianceRequirement::class, 'r')
            ->join('r.mappedControls', 'c')
            ->where('c.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();
    }

    /**
     * Requirements that inherit from the given ones through operational
     * mappings (inbound targets, and sources of bidirectional mappings).
     *
     * @param list<ComplianceRequirement> $requirements
     * @return list<ComplianceRequirement>
     */
    private function mappingNeighbours(array $requirements): array
    {
        $mappings = $this->em->createQueryBuilder()
            ->select('m')
            ->from(ComplianceMapping::class, 'm')
            ->where('m.sourceRequirement IN (:reqs) OR (m.bidirectional = true AND m.targetRequirement IN (:reqs))')
            ->andWhere('m.lifecycleState IN (:states)')
            ->setParameter('reqs', $requirements)
            ->setParameter('states', ComplianceMapping::OPERATIONAL_STATES)
            ->getQuery()
            ->getResult();

        $in = [];
        foreach ($requirements as $r) {
            $in[(int) $r->getId()] = true;
        }

        $out = [];
        foreach ($mappings as $m) {
            /** @var ComplianceMapping $m */
            $source = $m->getSourceRequirement();
            $target = $m->getTargetRequirement();
            if ($source !== null && isset($in[(int) $source->getId()]) && $target !== null) {
                $out[(int) $target->getId()] = $target;
            }
            if ($m->isBidirectional() && $target !== null && isset($in[(int) $target->getId()]) && $source !== null) {
                $out[(int) $source->getId()] = $source;
            }
        }

        return array_values($out);
    }
}
