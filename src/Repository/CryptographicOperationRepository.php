<?php

namespace App\Repository;

use DateTime;
use App\Entity\CryptographicOperation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CryptographicOperation>
 */
class CryptographicOperationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CryptographicOperation::class);
    }

    /**
     * Find operations by type
     */
    public function findByType(string $type): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.operationType = :type')
            ->setParameter('type', $type)
            ->orderBy('c.timestamp', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find failed operations
     */
    public function findFailedOperations(): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.status = :status')
            ->setParameter('status', 'failure')
            ->orderBy('c.timestamp', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get operations within date range
     */
    public function findByDateRange(DateTime $start, DateTime $end): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.timestamp >= :start')
            ->andWhere('c.timestamp <= :end')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->orderBy('c.timestamp', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get statistics for cryptographic operations
     */
    public function getStatistics(?DateTime $startDate = null, ?DateTime $endDate = null): array
    {
        $queryBuilder = $this->createQueryBuilder('c');

        if ($startDate instanceof DateTime) {
            $queryBuilder->andWhere('c.timestamp >= :startDate')
               ->setParameter('startDate', $startDate);
        }

        if ($endDate instanceof DateTime) {
            $queryBuilder->andWhere('c.timestamp <= :endDate')
               ->setParameter('endDate', $endDate);
        }

        return [
            'total' => (int) $queryBuilder->select('COUNT(c.id)')->getQuery()->getSingleScalarResult(),
            'by_type' => $this->getCountByType($startDate, $endDate),
            'by_status' => $this->getCountByStatus($startDate, $endDate),
            'recent_failures' => count($this->findFailedOperations()),
        ];
    }

    private function getCountByType(?DateTime $startDate = null, ?DateTime $endDate = null): array
    {
        $queryBuilder = $this->createQueryBuilder('c')
            ->select('c.operationType as type, COUNT(c.id) as count')
            ->groupBy('c.operationType');

        if ($startDate instanceof DateTime) {
            $queryBuilder->andWhere('c.timestamp >= :startDate')
               ->setParameter('startDate', $startDate);
        }

        if ($endDate instanceof DateTime) {
            $queryBuilder->andWhere('c.timestamp <= :endDate')
               ->setParameter('endDate', $endDate);
        }

        $results = $queryBuilder->getQuery()->getResult();

        $counts = [];
        foreach ($results as $result) {
            $counts[$result['type']] = (int) $result['count'];
        }

        return $counts;
    }

    private function getCountByStatus(?DateTime $startDate = null, ?DateTime $endDate = null): array
    {
        $queryBuilder = $this->createQueryBuilder('c')
            ->select('c.status, COUNT(c.id) as count')
            ->groupBy('c.status');

        if ($startDate instanceof DateTime) {
            $queryBuilder->andWhere('c.timestamp >= :startDate')
               ->setParameter('startDate', $startDate);
        }

        if ($endDate instanceof DateTime) {
            $queryBuilder->andWhere('c.timestamp <= :endDate')
               ->setParameter('endDate', $endDate);
        }

        $results = $queryBuilder->getQuery()->getResult();

        $counts = [];
        foreach ($results as $result) {
            $counts[$result['status']] = (int) $result['count'];
        }

        return $counts;
    }
}
