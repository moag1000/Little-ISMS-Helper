<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\FourEyesApprovalRequest;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\FourEyesApprovalRequestStatus;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FourEyesApprovalRequest>
 */
class FourEyesApprovalRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FourEyesApprovalRequest::class);
    }

    /**
     * @return FourEyesApprovalRequest[]
     */
    public function findPendingFor(User $approver, Tenant $tenant): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.tenant = :tenant')
            ->andWhere('r.status = :status')
            ->andWhere('r.expiresAt > :now')
            ->andWhere('r.requestedApprover = :user OR r.requestedApprover IS NULL')
            ->andWhere('r.requestedBy != :user')
            ->setParameter('tenant', $tenant)
            ->setParameter('status', FourEyesApprovalRequestStatus::Pending->value)
            ->setParameter('now', new DateTimeImmutable())
            ->setParameter('user', $approver)
            ->orderBy('r.createdAt', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();
    }

    /**
     * Pending requests past their expiry, every organisation (background run).
     * Expiry happens per entity in FourEyesApprovalService::cleanupExpired(),
     * so each organisation gets its own audit batch.
     *
     * @return list<FourEyesApprovalRequest>
     */
    public function findExpiredPending(): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.status = :pending')
            ->andWhere('r.expiresAt <= :now')
            ->setParameter('pending', FourEyesApprovalRequestStatus::Pending->value)
            ->setParameter('now', new DateTimeImmutable())
            ->getQuery()
            ->getResult();
    }
}
