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
     * The mappings carry their provider's tenant (null for a global provider,
     * the parent organisation for a parent's provider). A signed-in user who
     * re-authenticates through such a provider has the tenant filter armed for
     * their own organisation, which would hide every mapping row and silently
     * skip the role sync. The provider itself is already authorised for the
     * user at this point, so its mappings are read without the tenant filter.
     *
     * @return IdentityProviderRoleMapping[]
     */
    public function findActiveByProvider(IdentityProvider $provider): array
    {
        $query = fn (): array => $this->createQueryBuilder('m')
            ->where('m.identityProvider = :idp')
            ->andWhere('m.isActive = true')
            ->orderBy('m.priority', 'ASC')
            ->setParameter('idp', $provider)
            ->getQuery()
            ->getResult();

        $filters = $this->getEntityManager()->getFilters();
        if (!$filters->isEnabled('tenant_filter')) {
            return $query();
        }
        $filters->suspend('tenant_filter');
        try {
            return $query();
        } finally {
            $filters->restore('tenant_filter');
        }
    }
}
