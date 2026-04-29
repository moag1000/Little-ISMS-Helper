<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\IdentityProvider;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IdentityProvider>
 */
class IdentityProviderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IdentityProvider::class);
    }

    /**
     * Returns enabled providers visible to a given tenant.
     * Includes global providers (tenant=null) + tenant-scoped ones.
     *
     * @return list<IdentityProvider>
     */
    public function findEnabledForTenant(?Tenant $tenant): array
    {
        $qb = $this->createQueryBuilder('p')
            ->where('p.enabled = :en')
            ->setParameter('en', true);

        if ($tenant === null) {
            $qb->andWhere('p.tenant IS NULL');
        } else {
            $qb->andWhere('p.tenant IS NULL OR p.tenant = :t')
               ->setParameter('t', $tenant);
        }

        $qb->orderBy('p.tenant', 'ASC')->addOrderBy('p.name', 'ASC');

        return array_values($qb->getQuery()->getResult());
    }

    public function findOneBySlugForTenant(string $slug, ?Tenant $tenant): ?IdentityProvider
    {
        $qb = $this->createQueryBuilder('p')
            ->where('p.slug = :s')
            ->setParameter('s', $slug);
        if ($tenant === null) {
            $qb->andWhere('p.tenant IS NULL');
        } else {
            $qb->andWhere('p.tenant IS NULL OR p.tenant = :t')
               ->setParameter('t', $tenant);
        }

        return $qb->setMaxResults(1)->getQuery()->getOneOrNullResult();
    }

    public function findOneBySlugAnywhere(string $slug): ?IdentityProvider
    {
        return $this->findOneBy(['slug' => $slug]);
    }
}
