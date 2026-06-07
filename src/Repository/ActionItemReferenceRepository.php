<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ActionItem;
use App\Entity\ActionItemReference;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ActionItemReference>
 */
class ActionItemReferenceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ActionItemReference::class);
    }

    /**
     * Whether an action item already references the given source target within a
     * tenant — used by the source-conversion service to stay idempotent.
     */
    public function existsForTarget(Tenant $tenant, string $refType, int $refId): bool
    {
        $count = (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->andWhere('r.tenant = :tenant')
            ->andWhere('r.refType = :refType')
            ->andWhere('r.refId = :refId')
            ->setParameter('tenant', $tenant)
            ->setParameter('refType', $refType)
            ->setParameter('refId', $refId)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }

    /**
     * Resolve all references pointing at one ActionItem.
     *
     * @return list<ActionItemReference>
     */
    public function findForActionItem(ActionItem $actionItem): array
    {
        /** @var list<ActionItemReference> $rows */
        $rows = $this->createQueryBuilder('r')
            ->andWhere('r.actionItem = :ai')
            ->setParameter('ai', $actionItem)
            ->getQuery()
            ->getResult();

        return $rows;
    }
}
