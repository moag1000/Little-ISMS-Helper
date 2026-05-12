<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\IdentityProvider;
use App\Entity\IdentityProviderUserMapping;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IdentityProviderUserMapping>
 *
 * @method IdentityProviderUserMapping|null find($id, $lockMode = null, $lockVersion = null)
 * @method IdentityProviderUserMapping|null findOneBy(array $criteria, array $orderBy = null)
 * @method IdentityProviderUserMapping[]    findAll()
 * @method IdentityProviderUserMapping[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class IdentityProviderUserMappingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IdentityProviderUserMapping::class);
    }

    public function findByProviderAndSub(IdentityProvider $provider, string $sub): ?IdentityProviderUserMapping
    {
        return $this->findOneBy(['identityProvider' => $provider, 'idpUserId' => $sub]);
    }

    /**
     * Find IdP IDs that have ≥$minLogins successful logins but zero active role mappings.
     * Used by RoleMappingWithoutClaimRule AlvaHint.
     *
     * @return list<array{idpId: int}>
     */
    public function findProvidersWithLoginsButNoMappings(Tenant $tenant, int $minLogins = 10): array
    {
        return $this->getEntityManager()->createQuery(
            'SELECT IDENTITY(um.identityProvider) AS idpId
             FROM App\Entity\IdentityProviderUserMapping um
             WHERE um.tenant = :tenant
               AND um.successfulLoginCount >= :min
               AND NOT EXISTS (
                   SELECT 1 FROM App\Entity\IdentityProviderRoleMapping rm
                   WHERE rm.identityProvider = um.identityProvider AND rm.isActive = true
               )
             GROUP BY um.identityProvider'
        )
            ->setParameter('tenant', $tenant)
            ->setParameter('min', $minLogins)
            ->getResult();
    }
}
