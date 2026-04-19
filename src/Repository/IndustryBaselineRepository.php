<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\IndustryBaseline;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IndustryBaseline>
 */
class IndustryBaselineRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IndustryBaseline::class);
    }

    /**
     * @return IndustryBaseline[]
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('b')
            ->orderBy('b.industry', 'ASC')
            ->addOrderBy('b.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findByCode(string $code): ?IndustryBaseline
    {
        return $this->findOneBy(['code' => $code]);
    }

    /**
     * @return IndustryBaseline[]
     */
    public function findByIndustry(string $industry): array
    {
        return $this->findBy(['industry' => $industry], ['name' => 'ASC']);
    }
}
