<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ActionItem;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ActionItem>
 */
class ActionItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ActionItem::class);
    }

    /**
     * All action items for a tenant, due-date ascending.
     *
     * @return list<ActionItem>
     */
    public function findByTenant(Tenant $tenant): array
    {
        /** @var list<ActionItem> $rows */
        $rows = $this->createQueryBuilder('a')
            ->andWhere('a.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('a.dueDate', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * Open (not done/dismissed) action items for a tenant, due-date ascending.
     *
     * @return list<ActionItem>
     */
    public function findOpenByTenant(Tenant $tenant): array
    {
        /** @var list<ActionItem> $rows */
        $rows = $this->createQueryBuilder('a')
            ->andWhere('a.tenant = :tenant')
            ->andWhere('a.status NOT IN (:closed)')
            ->setParameter('tenant', $tenant)
            ->setParameter('closed', [ActionItem::STATUS_DONE, ActionItem::STATUS_DISMISSED])
            ->orderBy('a.dueDate', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }
}
