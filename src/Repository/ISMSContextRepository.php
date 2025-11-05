<?php

namespace App\Repository;

use App\Entity\ISMSContext;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ISMSContextRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ISMSContext::class);
    }

    public function getCurrentContext(): ?ISMSContext
    {
        return $this->createQueryBuilder('c')
            ->orderBy('c.updatedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
