<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\BulkImportBatch;
use App\Entity\BulkImportRow;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BulkImportRow>
 */
class BulkImportRowRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BulkImportRow::class);
    }

    /**
     * @return BulkImportRow[]
     */
    public function findByBatch(BulkImportBatch $batch, ?string $statusFilter = null): array
    {
        $qb = $this->createQueryBuilder('r')
            ->andWhere('r.batch = :batch')
            ->setParameter('batch', $batch)
            ->orderBy('r.rowNumber', 'ASC');

        if ($statusFilter !== null) {
            $qb->andWhere('r.status = :status')->setParameter('status', $statusFilter);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @return BulkImportRow[]
     */
    public function findErrorsByBatch(BulkImportBatch $batch): array
    {
        return $this->findByBatch($batch, BulkImportRow::STATUS_ERROR);
    }

    public function countByBatchAndStatus(BulkImportBatch $batch, string $status): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->andWhere('r.batch = :batch')
            ->andWhere('r.status = :status')
            ->setParameter('batch', $batch)
            ->setParameter('status', $status)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
