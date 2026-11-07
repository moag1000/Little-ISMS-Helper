<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\IsmsRegisterEntry;
use App\Entity\IsmsRegisterLink;
use App\Entity\Tenant;
use App\Enum\RegisterType;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IsmsRegisterEntry>
 */
class IsmsRegisterEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IsmsRegisterEntry::class);
    }

    /** @return list<IsmsRegisterEntry> */
    public function findByType(Tenant $tenant, RegisterType $type, bool $withArchived = false): array
    {
        $qb = $this->createQueryBuilder('e')
            ->where('e.tenant = :tenant')
            ->andWhere('e.type = :type')
            ->setParameter('tenant', $tenant)
            ->setParameter('type', $type)
            ->orderBy('e.title', \SortDirection::Ascending);
        if (!$withArchived) {
            $qb->andWhere('e.archivedAt IS NULL');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Active entries per register type, plus overdue reviews.
     *
     * @return array<string, array{active: int, overdue: int, changed: ?DateTimeImmutable}>
     */
    public function statsByType(Tenant $tenant, ?DateTimeInterface $today = null): array
    {
        $today ??= new DateTimeImmutable('today');
        $rows = $this->createQueryBuilder('e')
            ->select('e.type AS type')
            ->addSelect('COUNT(e.id) AS active')
            ->addSelect('SUM(CASE WHEN e.nextReviewDate < :today THEN 1 ELSE 0 END) AS overdue')
            ->addSelect('MAX(COALESCE(e.updatedAt, e.createdAt)) AS changed')
            ->where('e.tenant = :tenant')
            ->andWhere('e.archivedAt IS NULL')
            ->setParameter('tenant', $tenant)
            ->setParameter('today', $today)
            ->groupBy('e.type')
            ->getQuery()
            ->getArrayResult();

        $stats = [];
        foreach ($rows as $row) {
            $type = $row['type'] instanceof RegisterType ? $row['type']->value : (string) $row['type'];
            $stats[$type] = [
                'active' => (int) $row['active'],
                'overdue' => (int) $row['overdue'],
                'changed' => $row['changed'] !== null ? new DateTimeImmutable((string) $row['changed']) : null,
            ];
        }

        return $stats;
    }

    /**
     * Entries created or changed after the given moment (management review 9.3.2 b).
     *
     * @return list<IsmsRegisterEntry>
     */
    public function changedSince(Tenant $tenant, ?DateTimeInterface $since, RegisterType ...$types): array
    {
        $qb = $this->createQueryBuilder('e')
            ->where('e.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('e.type', \SortDirection::Ascending)
            ->addOrderBy('e.title', \SortDirection::Ascending);
        if ($types !== []) {
            $qb->andWhere('e.type IN (:types)')->setParameter('types', $types);
        }
        if ($since !== null) {
            $qb->andWhere('COALESCE(e.updatedAt, e.createdAt) > :since')->setParameter('since', $since);
        }

        return $qb->getQuery()->getResult();
    }

    /** @return list<string> origins already taken (adopted or archived), for proposal deduplication */
    public function origins(Tenant $tenant, RegisterType $type): array
    {
        $rows = $this->createQueryBuilder('e')
            ->select('e.origin')
            ->where('e.tenant = :tenant')
            ->andWhere('e.type = :type')
            ->andWhere('e.origin IS NOT NULL')
            ->setParameter('tenant', $tenant)
            ->setParameter('type', $type)
            ->getQuery()
            ->getSingleColumnResult();

        return array_values(array_map('strval', $rows));
    }

    /**
     * Entries that link to a record (reverse view on the record's detail page).
     *
     * @return list<IsmsRegisterEntry>
     */
    public function findLinkingTo(Tenant $tenant, string $field, int $refId): array
    {
        return $this->createQueryBuilder('e')
            ->innerJoin(IsmsRegisterLink::class, 'l', 'WITH', 'l.entry = e')
            ->where('e.tenant = :tenant')
            ->andWhere('l.field = :field')
            ->andWhere('l.refId = :refId')
            ->andWhere('e.archivedAt IS NULL')
            ->setParameter('tenant', $tenant)
            ->setParameter('field', $field)
            ->setParameter('refId', $refId)
            ->orderBy('e.title', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();
    }

    /**
     * Open entries with a review date, for the deadline source.
     *
     * @return list<IsmsRegisterEntry>
     */
    public function findOpenWithReview(Tenant $tenant): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.tenant = :tenant')
            ->andWhere('e.archivedAt IS NULL')
            ->andWhere('e.nextReviewDate IS NOT NULL')
            ->setParameter('tenant', $tenant)
            ->orderBy('e.nextReviewDate', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();
    }
}
