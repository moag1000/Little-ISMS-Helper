<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\BulkImportBatch;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BulkImportBatch>
 */
class BulkImportBatchRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BulkImportBatch::class);
    }

    /**
     * @return BulkImportBatch[]
     */
    public function findRecentByTenant(Tenant $tenant, int $limit = 20): array
    {
        return $this->createQueryBuilder('b')
            ->andWhere('b.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('b.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return BulkImportBatch[]
     */
    public function findByEntityTypeForTenant(string $entityType, Tenant $tenant, int $limit = 50): array
    {
        return $this->createQueryBuilder('b')
            ->andWhere('b.tenant = :tenant')
            ->andWhere('b.entityType = :entityType')
            ->setParameter('tenant', $tenant)
            ->setParameter('entityType', $entityType)
            ->orderBy('b.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function findByBatchId(string $batchId): ?BulkImportBatch
    {
        return $this->findOneBy(['batchId' => $batchId]);
    }

    public function findBySourceFileHash(string $hash, Tenant $tenant): ?BulkImportBatch
    {
        return $this->findOneBy([
            'sourceFileHash' => $hash,
            'tenant' => $tenant,
        ]);
    }
}
