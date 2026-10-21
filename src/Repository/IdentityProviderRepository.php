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

    /**
     * Every ENABLED provider with this slug, whatever its organisation. The
     * legacy start URL (slug only) is served only when this returns exactly
     * one provider (#1059 I-5).
     *
     * @return list<IdentityProvider>
     */
    public function findEnabledBySlug(string $slug): array
    {
        return $this->findBy(['slug' => $slug, 'enabled' => true]);
    }

    /**
     * Is $slug used by a GLOBAL provider other than $exceptId? A tenant-owned
     * provider must not take such a slug (#1059 I-5).
     */
    public function isSlugUsedByGlobalProvider(string $slug, ?int $exceptId): bool
    {
        $qb = $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.slug = :slug')
            ->andWhere('p.tenant IS NULL')
            ->setParameter('slug', $slug);
        if ($exceptId !== null) {
            $qb->andWhere('p.id <> :id')->setParameter('id', $exceptId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    public function findOneBySlugAnywhere(string $slug): ?IdentityProvider
    {
        return $this->findOneBy(['slug' => $slug]);
    }

    /**
     * Every enabled provider, regardless of tenant. Used by anonymous-login
     * resolution where the visitor's tenant is not yet known but a configured
     * email-domain binding may still match a tenant-scoped IdP.
     *
     * @return list<IdentityProvider>
     */
    public function findAllEnabled(): array
    {
        return array_values($this->findBy(['enabled' => true], ['tenant' => 'ASC', 'name' => 'ASC']));
    }
}
