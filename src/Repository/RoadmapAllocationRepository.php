<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RoadmapAllocation;
use App\Entity\RoadmapTask;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RoadmapAllocation>
 */
class RoadmapAllocationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RoadmapAllocation::class);
    }

    /**
     * Allocations for a tenant within a year/week window, keyed "taskId-week".
     *
     * @param list<int> $weeks
     * @return array<string, RoadmapAllocation>
     */
    public function findForWindowKeyed(Tenant $tenant, int $year, array $weeks): array
    {
        if ($weeks === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('a')
            ->andWhere('a.tenant = :tenant')
            ->andWhere('a.isoYear = :year')
            ->andWhere('a.isoWeek IN (:weeks)')
            ->setParameter('tenant', $tenant)
            ->setParameter('year', $year)
            ->setParameter('weeks', $weeks)
            ->getQuery()
            ->getResult();

        $out = [];
        foreach ($rows as $alloc) {
            /** @var RoadmapAllocation $alloc */
            $out[$alloc->getRoadmapTask()?->getId() . '-' . $alloc->getIsoWeek()] = $alloc;
        }
        return $out;
    }

    public function findCell(Tenant $tenant, RoadmapTask $task, int $year, int $week): ?RoadmapAllocation
    {
        return $this->findOneBy([
            'tenant' => $tenant,
            'roadmapTask' => $task,
            'isoYear' => $year,
            'isoWeek' => $week,
        ]);
    }
}
