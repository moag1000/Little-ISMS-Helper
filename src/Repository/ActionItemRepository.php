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
    /** ActionItemReference.refType of a task decided in a management review. */
    public const string MANAGEMENT_REVIEW_REF = 'management_review_action';

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
            ->orderBy('a.dueDate', \SortDirection::Ascending)
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
            ->orderBy('a.dueDate', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * Tasks decided in a management review (ISO 27001 9.3.3) — the items
     * that carry a reference to the review — due-date ascending.
     *
     * @return list<ActionItem>
     */
    public function findForManagementReview(Tenant $tenant, int $reviewId): array
    {
        /** @var list<ActionItem> $rows */
        $rows = $this->createQueryBuilder('a')
            ->innerJoin('a.references', 'r')
            ->andWhere('a.tenant = :tenant')
            ->andWhere('r.refType = :refType')
            ->andWhere('r.refId = :refId')
            ->setParameter('tenant', $tenant)
            ->setParameter('refType', self::MANAGEMENT_REVIEW_REF)
            ->setParameter('refId', $reviewId)
            ->orderBy('a.dueDate', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();

        return $rows;
    }
}
