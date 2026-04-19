<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AppliedBaseline;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AppliedBaseline>
 */
class AppliedBaselineRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AppliedBaseline::class);
    }

    /**
     * @return AppliedBaseline[]
     */
    public function findByTenant(Tenant $tenant): array
    {
        return $this->findBy(['tenant' => $tenant], ['appliedAt' => 'DESC']);
    }

    public function findOneByTenantAndCode(Tenant $tenant, string $code): ?AppliedBaseline
    {
        return $this->findOneBy(['tenant' => $tenant, 'baselineCode' => $code]);
    }
}
