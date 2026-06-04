<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AccessReviewCampaign;
use App\Entity\AccessReviewItem;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repository for {@see AccessReviewItem}.
 *
 * All finders are tenant-scoped (E-6 pattern).
 *
 * @extends ServiceEntityRepository<AccessReviewItem>
 *
 * @method AccessReviewItem|null find($id, $lockMode = null, $lockVersion = null)
 * @method AccessReviewItem|null findOneBy(array $criteria, array $orderBy = null)
 * @method AccessReviewItem[]    findAll()
 * @method AccessReviewItem[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class AccessReviewItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AccessReviewItem::class);
    }

    /**
     * All items for a campaign.
     *
     * @return AccessReviewItem[]
     */
    public function findByCampaign(AccessReviewCampaign $campaign): array
    {
        return $this->createQueryBuilder('ari')
            ->andWhere('ari.campaign = :campaign')
            ->setParameter('campaign', $campaign)
            ->orderBy('ari.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Single item scoped to tenant for safe fetch-by-ID.
     */
    public function findOneForTenant(int $id, Tenant $tenant): ?AccessReviewItem
    {
        return $this->createQueryBuilder('ari')
            ->andWhere('ari.id = :id')
            ->andWhere('ari.tenant = :tenant')
            ->setParameter('id', $id)
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Load many items by ID, all scoped to the given tenant.
     * Used by the bulk-decide endpoint to validate IDs in one query.
     *
     * @param list<int> $ids
     * @return AccessReviewItem[]
     */
    public function findManyForTenant(array $ids, Tenant $tenant): array
    {
        if ($ids === []) {
            return [];
        }

        return $this->createQueryBuilder('ari')
            ->andWhere('ari.id IN (:ids)')
            ->andWhere('ari.tenant = :tenant')
            ->setParameter('ids', $ids)
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getResult();
    }

    /**
     * All escalated items for a given campaign.
     *
     * @return AccessReviewItem[]
     */
    public function findEscalatedByCampaign(AccessReviewCampaign $campaign): array
    {
        return $this->createQueryBuilder('ari')
            ->andWhere('ari.campaign = :campaign')
            ->andWhere('ari.decision = :decision')
            ->setParameter('campaign', $campaign)
            ->setParameter('decision', AccessReviewItem::DECISION_ESCALATED)
            ->orderBy('ari.decidedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
