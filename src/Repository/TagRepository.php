<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Tag;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Tag>
 */
class TagRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Tag::class);
    }

    /**
     * All tags visible in the tenant scope: global (tenant NULL) + tenant-specific.
     *
     * @return Tag[]
     */
    public function findVisibleFor(?Tenant $tenant): array
    {
        $qb = $this->createQueryBuilder('t')
            ->orderBy('t.type', 'ASC')
            ->addOrderBy('t.name', 'ASC');

        if ($tenant instanceof Tenant) {
            $qb->andWhere('t.tenant = :tenant OR t.tenant IS NULL')
                ->setParameter('tenant', $tenant);
        } else {
            $qb->andWhere('t.tenant IS NULL');
        }

        return $qb->getQuery()->getResult();
    }

    public function findOneByName(?Tenant $tenant, string $name): ?Tag
    {
        return $this->findOneBy(['tenant' => $tenant, 'name' => $name]);
    }

    /**
     * ISB-Review OBS-3: scope tags for ISO 27001 4.3 scope anchoring.
     *
     * @return Tag[]
     */
    public function findScopeTags(?Tenant $tenant): array
    {
        $qb = $this->createQueryBuilder('t')
            ->andWhere('t.type = :type')
            ->setParameter('type', Tag::TYPE_SCOPE)
            ->orderBy('t.frameworkCode', 'ASC')
            ->addOrderBy('t.name', 'ASC');

        if ($tenant instanceof Tenant) {
            $qb->andWhere('t.tenant = :tenant OR t.tenant IS NULL')
                ->setParameter('tenant', $tenant);
        } else {
            $qb->andWhere('t.tenant IS NULL');
        }

        return $qb->getQuery()->getResult();
    }
}
