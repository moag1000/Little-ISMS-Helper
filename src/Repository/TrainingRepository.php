<?php

namespace App\Repository;

use App\Entity\Training;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class TrainingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Training::class);
    }

    public function findUpcoming(): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.status IN (:statuses)')
            ->andWhere('t.scheduledDate >= :today')
            ->setParameter('statuses', ['planned', 'scheduled'])
            ->setParameter('today', new \DateTime())
            ->orderBy('t.scheduledDate', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
