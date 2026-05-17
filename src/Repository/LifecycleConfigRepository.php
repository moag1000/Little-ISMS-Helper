<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\LifecycleConfig;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LifecycleConfig>
 */
class LifecycleConfigRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LifecycleConfig::class);
    }

    /**
     * @return array<string, mixed> map of config_key => decoded value
     */
    public function findOverridesForTransition(Tenant $tenant, string $workflowName, string $transitionName): array
    {
        $rows = $this->createQueryBuilder('lc')
            ->where('lc.tenant = :tenant')
            ->andWhere('lc.workflowName = :wf')
            ->andWhere('lc.transitionName = :tr')
            ->setParameter('tenant', $tenant)
            ->setParameter('wf', $workflowName)
            ->setParameter('tr', $transitionName)
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($rows as $row) {
            $map[$row->getConfigKey()] = $row->getConfigValue();
        }
        return $map;
    }
}
