<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ManagementDecision;
use App\Entity\ManagementReview;
use App\Entity\Tenant;
use App\Enum\ManagementDecisionStatus;
use App\Enum\ManagementDecisionType;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Tenant-scoped finders for {@see ManagementDecision}. Every finder takes the
 * tenant explicitly (scheduler and dashboards run outside the request filter).
 *
 * @extends ServiceEntityRepository<ManagementDecision>
 */
class ManagementDecisionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ManagementDecision::class);
    }

    /** @return list<ManagementDecision> newest first */
    public function findByTenant(Tenant $tenant): array
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('d.requestedAt', \SortDirection::Descending)
            ->addOrderBy('d.id', \SortDirection::Descending)
            ->getQuery()
            ->getResult();
    }

    /** @return list<ManagementDecision> oldest due first */
    public function findPending(Tenant $tenant): array
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.tenant = :tenant')
            ->andWhere('d.status = :status')
            ->setParameter('tenant', $tenant)
            ->setParameter('status', ManagementDecisionStatus::Pending->value)
            ->orderBy('d.dueDate', \SortDirection::Ascending)
            ->addOrderBy('d.requestedAt', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();
    }

    /**
     * Decisions taken since a date (approved or rejected), newest first.
     * Automatic acceptances are left out: they are the effect of one approval.
     *
     * @return list<ManagementDecision>
     */
    public function findDecidedSince(Tenant $tenant, DateTimeImmutable $since, int $limit = 50): array
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.tenant = :tenant')
            ->andWhere('d.decidedAt >= :since')
            ->andWhere('d.type <> :auto')
            ->andWhere('d.status IN (:decided)')
            ->setParameter('tenant', $tenant)
            ->setParameter('since', $since)
            ->setParameter('auto', ManagementDecisionType::AutoPolicy)
            ->setParameter('decided', [
                ManagementDecisionStatus::Approved->value,
                ManagementDecisionStatus::Rejected->value,
                ManagementDecisionStatus::Superseded->value,
            ])
            ->orderBy('d.decidedAt', \SortDirection::Descending)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * The approvals of a type (and subject, when given) that are still in force,
     * newest decision first.
     *
     * @return list<ManagementDecision>
     */
    public function findApproved(
        Tenant $tenant,
        ManagementDecisionType $type,
        ?string $subjectType = null,
        ?int $subjectId = null,
    ): array {
        $qb = $this->createQueryBuilder('d')
            ->andWhere('d.tenant = :tenant')
            ->andWhere('d.type = :type')
            ->andWhere('d.status = :approved')
            ->setParameter('tenant', $tenant)
            ->setParameter('type', $type)
            ->setParameter('approved', ManagementDecisionStatus::Approved->value)
            ->orderBy('d.decidedAt', \SortDirection::Descending)
            ->addOrderBy('d.id', \SortDirection::Descending);
        if ($subjectType !== null) {
            $qb->andWhere('d.subjectType = :subjectType')->setParameter('subjectType', $subjectType);
        }
        if ($subjectId !== null) {
            $qb->andWhere('d.subjectId = :subjectId')->setParameter('subjectId', $subjectId);
        }

        return $qb->getQuery()->getResult();
    }

    /** @return list<ManagementDecision> */
    public function findForSubject(Tenant $tenant, string $subjectType, int $subjectId): array
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.tenant = :tenant')
            ->andWhere('d.subjectType = :subjectType')
            ->andWhere('d.subjectId = :subjectId')
            ->setParameter('tenant', $tenant)
            ->setParameter('subjectType', $subjectType)
            ->setParameter('subjectId', $subjectId)
            ->orderBy('d.requestedAt', \SortDirection::Descending)
            ->getQuery()
            ->getResult();
    }

    /** @return list<ManagementDecision> */
    public function findForReview(ManagementReview $review): array
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.managementReview = :review')
            ->setParameter('review', $review)
            ->orderBy('d.requestedAt', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();
    }

    /**
     * Approvals whose validity ends within the window (for the re-approval
     * reminder) — already expired ones included.
     *
     * @return list<ManagementDecision>
     */
    public function findApprovalsEndingBefore(Tenant $tenant, DateTimeImmutable $until): array
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.tenant = :tenant')
            ->andWhere('d.status = :approved')
            ->andWhere('d.validUntil IS NOT NULL')
            ->andWhere('d.validUntil <= :until')
            ->setParameter('tenant', $tenant)
            ->setParameter('approved', ManagementDecisionStatus::Approved->value)
            ->setParameter('until', $until)
            ->orderBy('d.validUntil', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();
    }
}
