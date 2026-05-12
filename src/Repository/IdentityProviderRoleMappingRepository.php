<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\IdentityProvider;
use App\Entity\IdentityProviderRoleMapping;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IdentityProviderRoleMapping>
 *
 * @method IdentityProviderRoleMapping|null find($id, $lockMode = null, $lockVersion = null)
 * @method IdentityProviderRoleMapping|null findOneBy(array $criteria, array $orderBy = null)
 * @method IdentityProviderRoleMapping[]    findAll()
 * @method IdentityProviderRoleMapping[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class IdentityProviderRoleMappingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IdentityProviderRoleMapping::class);
    }

    /**
     * Load active mappings for an IdP ordered by priority ASC (lowest = checked first).
     *
     * @return IdentityProviderRoleMapping[]
     */
    public function findActiveByProvider(IdentityProvider $provider): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.identityProvider = :idp')
            ->andWhere('m.isActive = true')
            ->orderBy('m.priority', 'ASC')
            ->setParameter('idp', $provider)
            ->getQuery()
            ->getResult();
    }
}
