<?php

namespace App\Repository;

use App\Entity\BusinessProcess;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BusinessProcess>
 */
class BusinessProcessRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BusinessProcess::class);
    }

    /**
     * Find processes with critical or high criticality
     */
    public function findCriticalProcesses(): array
    {
        return $this->createQueryBuilder('bp')
            ->where('bp.criticality IN (:criticalities)')
            ->setParameter('criticalities', ['critical', 'high'])
            ->orderBy('bp.criticality', 'DESC')
            ->addOrderBy('bp.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find processes with low RTO (high availability requirement)
     */
    public function findHighAvailabilityProcesses(int $maxRto = 4): array
    {
        return $this->createQueryBuilder('bp')
            ->where('bp.rto <= :maxRto')
            ->setParameter('maxRto', $maxRto)
            ->orderBy('bp.rto', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find processes that support a specific asset
     */
    public function findByAsset(int $assetId): array
    {
        return $this->createQueryBuilder('bp')
            ->join('bp.supportingAssets', 'a')
            ->where('a.id = :assetId')
            ->setParameter('assetId', $assetId)
            ->orderBy('bp.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get statistics about business processes
     */
    public function getStatistics(): array
    {
        $qb = $this->createQueryBuilder('bp');

        return [
            'total' => $qb->select('COUNT(bp.id)')
                ->getQuery()
                ->getSingleScalarResult(),

            'critical' => $this->createQueryBuilder('bp')
                ->select('COUNT(bp.id)')
                ->where('bp.criticality = :criticality')
                ->setParameter('criticality', 'critical')
                ->getQuery()
                ->getSingleScalarResult(),

            'high' => $this->createQueryBuilder('bp')
                ->select('COUNT(bp.id)')
                ->where('bp.criticality = :criticality')
                ->setParameter('criticality', 'high')
                ->getQuery()
                ->getSingleScalarResult(),

            'avg_rto' => $this->createQueryBuilder('bp')
                ->select('AVG(bp.rto)')
                ->getQuery()
                ->getSingleScalarResult() ?? 0,

            'avg_mtpd' => $this->createQueryBuilder('bp')
                ->select('AVG(bp.mtpd)')
                ->getQuery()
                ->getSingleScalarResult() ?? 0,
        ];
    }

    /**
     * Find processes with impact scores above threshold
     */
    public function findHighImpactProcesses(int $threshold = 8): array
    {
        return $this->createQueryBuilder('bp')
            ->where('bp.financialImpact >= :threshold OR bp.reputationalImpact >= :threshold OR bp.operationalImpact >= :threshold')
            ->setParameter('threshold', $threshold)
            ->orderBy('bp.financialImpact', 'DESC')
            ->addOrderBy('bp.reputationalImpact', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
