<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ProcessingActivity;
use App\Entity\Tenant;
use App\Entity\TransferImpactAssessment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TransferImpactAssessment>
 *
 * All finders are tenant-scoped — E-6: no unguarded findAll().
 */
class TransferImpactAssessmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TransferImpactAssessment::class);
    }

    /**
     * All TIAs for a tenant, ordered newest-first.
     *
     * @return TransferImpactAssessment[]
     */
    public function findByTenant(Tenant $tenant): array
    {
        return $this->createQueryBuilder('tia')
            ->where('tia.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('tia.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * All TIAs linked to a given ProcessingActivity (same tenant implied by FK).
     *
     * @return TransferImpactAssessment[]
     */
    public function findByProcessingActivity(ProcessingActivity $processingActivity): array
    {
        return $this->createQueryBuilder('tia')
            ->where('tia.processingActivity = :pa')
            ->setParameter('pa', $processingActivity)
            ->orderBy('tia.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * TIAs by residual risk rating for a tenant (used on DPO dashboard).
     *
     * @return TransferImpactAssessment[]
     */
    public function findByResidualRisk(Tenant $tenant, string $rating): array
    {
        return $this->createQueryBuilder('tia')
            ->where('tia.tenant = :tenant')
            ->andWhere('tia.residualRiskRating = :rating')
            ->setParameter('tenant', $tenant)
            ->setParameter('rating', $rating)
            ->orderBy('tia.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * TIAs with high residual risk (need attention / suspension).
     *
     * @return TransferImpactAssessment[]
     */
    public function findHighRisk(Tenant $tenant): array
    {
        return $this->findByResidualRisk($tenant, 'high');
    }

    /**
     * TIAs still in draft status (not yet finalised).
     *
     * @return TransferImpactAssessment[]
     */
    public function findDrafts(Tenant $tenant): array
    {
        return $this->createQueryBuilder('tia')
            ->where('tia.tenant = :tenant')
            ->andWhere('tia.status = :status')
            ->setParameter('tenant', $tenant)
            ->setParameter('status', 'draft')
            ->orderBy('tia.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
