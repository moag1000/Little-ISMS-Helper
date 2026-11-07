<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Suggestion;
use App\Entity\Tenant;
use App\Enum\SuggestionStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Suggestion>
 */
class SuggestionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Suggestion::class);
    }

    public function findOneByDedup(Tenant $tenant, string $kind, string $dedupKey): ?Suggestion
    {
        return $this->findOneBy(['tenant' => $tenant, 'kind' => $kind, 'dedupKey' => $dedupKey]);
    }

    /** @return list<Suggestion> newest first */
    public function findPending(Tenant $tenant, ?string $kind = null): array
    {
        $qb = $this->createQueryBuilder('s')
            ->where('s.tenant = :tenant')
            ->andWhere('s.status = :status')
            ->setParameter('tenant', $tenant)
            ->setParameter('status', SuggestionStatus::Pending)
            ->orderBy('s.createdAt', \SortDirection::Descending)
            ->addOrderBy('s.id', \SortDirection::Descending);
        if ($kind !== null) {
            $qb->andWhere('s.kind = :kind')->setParameter('kind', $kind);
        }

        return $qb->getQuery()->getResult();
    }

    /** @return list<Suggestion> */
    public function findPendingForSubject(Tenant $tenant, string $subjectClass, int $subjectId): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.tenant = :tenant')
            ->andWhere('s.status = :status')
            ->andWhere('s.subjectClass = :class')
            ->andWhere('s.subjectId = :id')
            ->setParameter('tenant', $tenant)
            ->setParameter('status', SuggestionStatus::Pending)
            ->setParameter('class', $subjectClass)
            ->setParameter('id', $subjectId)
            ->orderBy('s.createdAt', \SortDirection::Descending)
            ->getQuery()
            ->getResult();
    }

    /** @return list<Suggestion> decided in the last $days days, newest first */
    public function findDecided(Tenant $tenant, int $days = 90): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.tenant = :tenant')
            ->andWhere('s.status IN (:done)')
            ->andWhere('s.decidedAt >= :since')
            ->setParameter('tenant', $tenant)
            ->setParameter('done', [SuggestionStatus::Accepted, SuggestionStatus::Rejected])
            ->setParameter('since', new \DateTimeImmutable(sprintf('-%d days', $days)))
            ->orderBy('s.decidedAt', \SortDirection::Descending)
            ->getQuery()
            ->getResult();
    }

    /**
     * Dedup keys of this kind already decided against (rejected), for kinds
     * that compute proposals live (SoA mapping suggestions).
     *
     * @return array<string, true>
     */
    public function rejectedKeys(Tenant $tenant, string $kind): array
    {
        $rows = $this->createQueryBuilder('s')
            ->select('s.dedupKey')
            ->where('s.tenant = :tenant')
            ->andWhere('s.kind = :kind')
            ->andWhere('s.status = :status')
            ->setParameter('tenant', $tenant)
            ->setParameter('kind', $kind)
            ->setParameter('status', SuggestionStatus::Rejected)
            ->getQuery()
            ->getSingleColumnResult();

        return array_fill_keys(array_map('strval', $rows), true);
    }

    /**
     * Ids of the records that already have a suggestion of this kind, in any
     * status — AI kinds ask once per record, a rejection is final.
     *
     * @return array<int, true>
     */
    public function subjectIdsWithKind(Tenant $tenant, string $kind): array
    {
        $rows = $this->createQueryBuilder('s')
            ->select('s.subjectId')
            ->where('s.tenant = :tenant')
            ->andWhere('s.kind = :kind')
            ->andWhere('s.subjectId IS NOT NULL')
            ->setParameter('tenant', $tenant)
            ->setParameter('kind', $kind)
            ->getQuery()
            ->getSingleColumnResult();

        return array_fill_keys(array_map('intval', $rows), true);
    }
}
