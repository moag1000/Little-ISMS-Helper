<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Tenant;
use App\Entity\TenantPolicySetting;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TenantPolicySetting>
 *
 * @method TenantPolicySetting|null find($id, $lockMode = null, $lockVersion = null)
 * @method TenantPolicySetting|null findOneBy(array $criteria, array $orderBy = null)
 * @method TenantPolicySetting[]    findAll()
 * @method TenantPolicySetting[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class TenantPolicySettingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TenantPolicySetting::class);
    }

    public function findOneByTenantAndKey(Tenant $tenant, string $key): ?TenantPolicySetting
    {
        return $this->findOneBy(['tenant' => $tenant, 'key' => $key]);
    }

    /**
     * @return TenantPolicySetting[]
     */
    public function findByTenant(Tenant $tenant): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('s.key', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
