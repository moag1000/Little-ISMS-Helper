<?php

namespace App\Repository;

use App\Entity\InternalAudit;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class InternalAuditRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InternalAudit::class);
    }

    public function findUpcoming(): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.status = :status')
            ->andWhere('a.plannedDate >= :today')
            ->setParameter('status', 'planned')
            ->setParameter('today', new \DateTime())
            ->orderBy('a.plannedDate', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
