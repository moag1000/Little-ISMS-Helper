<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AuditFreeze;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AuditFreeze>
 *
 * @method AuditFreeze|null find($id, $lockMode = null, $lockVersion = null)
 * @method AuditFreeze|null findOneBy(array $criteria, array $orderBy = null)
 * @method AuditFreeze[]    findAll()
 * @method AuditFreeze[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class AuditFreezeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuditFreeze::class);
    }

    /**
     * List all freezes for a tenant, newest first.
     *
     * @return AuditFreeze[]
     */
    public function findByTenant(Tenant $tenant): array
    {
        return $this->createQueryBuilder('f')
            ->where('f.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('f.stichtag', 'DESC')
            ->addOrderBy('f.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * List with optional filters.
     *
     * @return AuditFreeze[]
     */
    public function findByTenantFiltered(Tenant $tenant, ?string $purpose = null, ?int $year = null): array
    {
        $qb = $this->createQueryBuilder('f')
            ->where('f.tenant = :tenant')
            ->setParameter('tenant', $tenant);

        if ($purpose !== null && $purpose !== '') {
            $qb->andWhere('f.purpose = :purpose')
                ->setParameter('purpose', $purpose);
        }

        if ($year !== null) {
            $qb->andWhere('f.stichtag >= :yearStart AND f.stichtag <= :yearEnd')
                ->setParameter('yearStart', new \DateTimeImmutable(sprintf('%d-01-01', $year)))
                ->setParameter('yearEnd', new \DateTimeImmutable(sprintf('%d-12-31', $year)));
        }

        return $qb->orderBy('f.stichtag', 'DESC')
            ->addOrderBy('f.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Distinct years (as int) for which freezes exist — drives the year filter.
     *
     * @return list<int>
     */
    public function findDistinctYears(Tenant $tenant): array
    {
        $rows = $this->createQueryBuilder('f')
            ->select('f.stichtag')
            ->where('f.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('f.stichtag', 'DESC')
            ->getQuery()
            ->getArrayResult();

        $years = [];
        foreach ($rows as $row) {
            $date = $row['stichtag'];
            if ($date instanceof \DateTimeInterface) {
                $y = (int) $date->format('Y');
                if (!in_array($y, $years, true)) {
                    $years[] = $y;
                }
            }
        }
        return $years;
    }
}
