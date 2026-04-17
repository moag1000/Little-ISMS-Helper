<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\FourEyesApprovalRequest;
use App\Entity\Tenant;
use App\Entity\User;
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
            ->setParameter('status', FourEyesApprovalRequest::STATUS_PENDING)
            ->setParameter('now', new DateTimeImmutable())
            ->setParameter('user', $approver)
            ->orderBy('r.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function markExpired(): int
    {
        return $this->createQueryBuilder('r')
            ->update()
            ->set('r.status', ':expired')
            ->where('r.status = :pending')
            ->andWhere('r.expiresAt <= :now')
            ->setParameter('expired', FourEyesApprovalRequest::STATUS_EXPIRED)
            ->setParameter('pending', FourEyesApprovalRequest::STATUS_PENDING)
            ->setParameter('now', new DateTimeImmutable())
            ->getQuery()
            ->execute();
    }
}
