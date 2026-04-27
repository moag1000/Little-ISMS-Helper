<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SampleDataImport;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SampleDataImport>
 */
class SampleDataImportRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SampleDataImport::class);
    }

    /**
     * @return SampleDataImport[]
     */
    public function findByKey(string $sampleKey, Tenant $tenant): array
    {
        return $this->findBy(['sampleKey' => $sampleKey, 'tenant' => $tenant]);
    }

    /**
     * @return array<string, int>  sampleKey => count
     */
    public function countsByKey(Tenant $tenant): array
    {
        $rows = $this->createQueryBuilder('s')
            ->select('s.sampleKey AS k, COUNT(s.id) AS c')
            ->where('s.tenant = :t')->setParameter('t', $tenant)
            ->groupBy('s.sampleKey')
            ->getQuery()->getArrayResult();

        $out = [];
        foreach ($rows as $row) {
            $out[$row['k']] = (int) $row['c'];
        }
        return $out;
    }
}
