<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ImportSession;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ImportSession>
 *
 * @method ImportSession|null find($id, $lockMode = null, $lockVersion = null)
 * @method ImportSession|null findOneBy(array $criteria, array $orderBy = null)
 * @method ImportSession[]    findAll()
 * @method ImportSession[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ImportSessionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ImportSession::class);
    }

    /**
     * Paginate sessions for a tenant, newest first.
     *
     * @return ImportSession[]
     */
    public function findByTenantPaginated(Tenant $tenant, int $limit, int $offset): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('s.uploadedAt', 'DESC')
            ->addOrderBy('s.id', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();
    }

    public function countByTenant(Tenant $tenant): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->andWhere('s.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
