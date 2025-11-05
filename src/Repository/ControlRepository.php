<?php

namespace App\Repository;

use App\Entity\Control;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ControlRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Control::class);
    }

    public function findApplicableControls(): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.applicable = :applicable')
            ->setParameter('applicable', true)
            ->orderBy('c.controlId', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countByCategory(): array
    {
        return $this->createQueryBuilder('c')
            ->select('c.category, COUNT(c.id) as total, SUM(CASE WHEN c.applicable = true THEN 1 ELSE 0 END) as applicable')
            ->groupBy('c.category')
            ->orderBy('c.category', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function getImplementationStats(): array
    {
        return $this->createQueryBuilder('c')
            ->select('c.implementationStatus, COUNT(c.id) as count')
            ->where('c.applicable = :applicable')
            ->setParameter('applicable', true)
            ->groupBy('c.implementationStatus')
            ->getQuery()
            ->getResult();
    }
}
