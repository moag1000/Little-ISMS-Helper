<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AccessReviewCampaign;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repository for {@see AccessReviewCampaign}.
 *
 * All finders are tenant-scoped (E-6 pattern — no unguarded findAll in production code).
 *
 * @extends ServiceEntityRepository<AccessReviewCampaign>
 *
 * @method AccessReviewCampaign|null find($id, $lockMode = null, $lockVersion = null)
 * @method AccessReviewCampaign|null findOneBy(array $criteria, array $orderBy = null)
 * @method AccessReviewCampaign[]    findAll()
 * @method AccessReviewCampaign[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class AccessReviewCampaignRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AccessReviewCampaign::class);
    }

    /**
     * All campaigns for a tenant, newest-first.
     *
     * @return AccessReviewCampaign[]
     */
    public function findByTenant(Tenant $tenant): array
    {
        return $this->createQueryBuilder('arc')
            ->andWhere('arc.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('arc.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Open (not yet closed) campaigns for a tenant.
     *
     * @return AccessReviewCampaign[]
     */
    public function findOpenByTenant(Tenant $tenant): array
    {
        return $this->createQueryBuilder('arc')
            ->andWhere('arc.tenant = :tenant')
            ->andWhere('arc.status = :status')
            ->setParameter('tenant', $tenant)
            ->setParameter('status', AccessReviewCampaign::STATUS_OPEN)
            ->orderBy('arc.dueDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Single campaign scoped to tenant — prevents cross-tenant access.
     */
    public function findOneForTenant(int $id, Tenant $tenant): ?AccessReviewCampaign
    {
        return $this->createQueryBuilder('arc')
            ->andWhere('arc.id = :id')
            ->andWhere('arc.tenant = :tenant')
            ->setParameter('id', $id)
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
