<?php

namespace App\Repository;

use App\Entity\ISMSObjective;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ISMSObjectiveRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ISMSObjective::class);
    }

    public function findActive(): array
    {
        return $this->createQueryBuilder('o')
            ->where('o.status IN (:statuses)')
            ->setParameter('statuses', ['in_progress', 'not_started'])
            ->orderBy('o.targetDate', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
