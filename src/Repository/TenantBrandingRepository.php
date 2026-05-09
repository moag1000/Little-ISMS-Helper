<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Tenant;
use App\Entity\TenantBranding;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TenantBranding>
 *
 * @method TenantBranding|null find($id, $lockMode = null, $lockVersion = null)
 * @method TenantBranding|null findOneBy(array $criteria, array $orderBy = null)
 * @method TenantBranding[]    findAll()
 * @method TenantBranding[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class TenantBrandingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TenantBranding::class);
    }

    public function findOneByTenant(Tenant $tenant): ?TenantBranding
    {
        return $this->findOneBy(['tenant' => $tenant]);
    }
}
