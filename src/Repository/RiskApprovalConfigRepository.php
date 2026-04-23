<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RiskApprovalConfig;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RiskApprovalConfig>
 */
class RiskApprovalConfigRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RiskApprovalConfig::class);
    }

    public function findByTenant(Tenant $tenant): ?RiskApprovalConfig
    {
        return $this->findOneBy(['tenant' => $tenant]);
    }
}
