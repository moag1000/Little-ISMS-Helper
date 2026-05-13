<?php

declare(strict_types=1);

namespace App\Repository;

use DateTime;
use App\Entity\Person;
use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Person>
 */
class PersonRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Person::class);
    }

    /**
     * Find active persons
     */
    public function findActive(): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.active = :active')
            ->setParameter('active', true)
            ->orderBy('p.fullName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find persons by type
     */
    public function findByType(string $type): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.personType = :type')
            ->setParameter('type', $type)
            ->orderBy('p.fullName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find person by badge ID
     */
    public function findByBadgeId(string $badgeId): ?Person
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.badgeId = :badgeId')
            ->setParameter('badgeId', $badgeId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find persons with expiring access (within X days)
     */
    public function findWithExpiringAccess(int $days = 30): array
    {
        $futureDate = new DateTime()->modify("+$days days");

        return $this->createQueryBuilder('p')
            ->andWhere('p.active = :active')
            ->andWhere('p.accessValidUntil IS NOT NULL')
            ->andWhere('p.accessValidUntil <= :futureDate')
            ->andWhere('p.accessValidUntil >= :now')
            ->setParameter('active', true)
            ->setParameter('futureDate', $futureDate)
            ->setParameter('now', new DateTime())
            ->orderBy('p.accessValidUntil', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find persons with expired access
     */
    public function findWithExpiredAccess(): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.accessValidUntil IS NOT NULL')
            ->andWhere('p.accessValidUntil < :now')
            ->setParameter('now', new DateTime())
            ->orderBy('p.accessValidUntil', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find persons by company
     */
    public function findByCompany(string $company): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.company LIKE :company')
            ->setParameter('company', '%' . $company . '%')
            ->orderBy('p.fullName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Search persons by name
     */
    public function searchByName(string $name): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.fullName LIKE :name')
            ->setParameter('name', '%' . $name . '%')
            ->orderBy('p.fullName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get statistics
     */
    public function getStatistics(): array
    {
        $all = $this->findAll();
        $active = $this->findActive();

        return [
            'total' => count($all),
            'active' => count($active),
            'inactive' => count($all) - count($active),
            'expiring_soon' => count($this->findWithExpiringAccess(30)),
            'expired' => count($this->findWithExpiredAccess()),
            'by_type' => $this->getCountByType(),
        ];
    }

    /**
     * Active Persons in a tenant, sorted by full name ASC.
     *
     * Default Person-picker pool for governance roles
     * (Asset/Risk/Control/Document owner, CISO/ISB/BCM-Officer
     * holders). Returns an empty list when tenant is null so callers
     * can render an empty-state without branching first.
     *
     * @return list<Person>
     */
    public function findActiveByTenant(?Tenant $tenant): array
    {
        if (!$tenant instanceof Tenant) {
            return [];
        }

        /** @var list<Person> $rows */
        $rows = $this->createQueryBuilder('p')
            ->andWhere('p.tenant = :tenant')
            ->andWhere('p.active = :active')
            ->setParameter('tenant', $tenant)
            ->setParameter('active', true)
            ->orderBy('p.fullName', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * Active Persons in a tenant, sorted by personType-match-DESC then
     * full-name ASC. When `$preferredType` is null falls back to
     * {@see findActiveByTenant()}.
     *
     * Useful when a UI wants to surface a specific role-holder type
     * first (e.g. external `consultant` Persons at the top of a DPO
     * picker) without filtering out the rest of the roster.
     *
     * @return list<Person>
     */
    public function findRoleHoldersByTenant(?Tenant $tenant, ?string $preferredType = null): array
    {
        if (!$tenant instanceof Tenant) {
            return [];
        }

        if ($preferredType === null || $preferredType === '') {
            return $this->findActiveByTenant($tenant);
        }

        /** @var list<Person> $rows */
        $rows = $this->createQueryBuilder('p')
            ->select('p, CASE WHEN p.personType = :preferred THEN 0 ELSE 1 END AS HIDDEN matchOrder')
            ->andWhere('p.tenant = :tenant')
            ->andWhere('p.active = :active')
            ->setParameter('tenant', $tenant)
            ->setParameter('active', true)
            ->setParameter('preferred', $preferredType)
            ->orderBy('matchOrder', 'ASC')
            ->addOrderBy('p.fullName', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * Resolve a Person whose `linkedUser` matches the given User id.
     *
     * Backwards-compat shim: when a legacy form submits a User.id where
     * a Person.id is now expected, the validator can call this to map
     * the value back to a Person without losing the user's intent.
     */
    public function findOneByLinkedUserId(int $userId): ?Person
    {
        if ($userId <= 0) {
            return null;
        }

        /** @var Person|null $row */
        $row = $this->createQueryBuilder('p')
            ->andWhere('p.linkedUser = :uid')
            ->setParameter('uid', $userId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $row;
    }

    /**
     * Active Users in tenant that are not linked to any Person yet.
     *
     * @return User[]
     */
    public function findUsersAvailableToLink(?Tenant $tenant): array
    {
        if (!$tenant instanceof Tenant) {
            return [];
        }

        $em = $this->getEntityManager();
        $qb = $em->createQueryBuilder();

        $qb->select('u')
            ->from(User::class, 'u')
            ->leftJoin(Person::class, 'p', 'ON', 'p.linkedUser = u')
            ->where('u.tenant = :tenant')
            ->andWhere('u.isActive = :active')
            ->andWhere('p.id IS NULL')
            ->setParameter('tenant', $tenant)
            ->setParameter('active', true)
            ->orderBy('u.lastName', 'ASC')
            ->addOrderBy('u.firstName', 'ASC');

        return $qb->getQuery()->getResult();
    }

    private function getCountByType(): array
    {
        $results = $this->createQueryBuilder('p')
            ->select('p.personType as type, COUNT(p.id) as count')
            ->groupBy('p.personType')
            ->getQuery()
            ->getResult();

        $counts = [];
        foreach ($results as $result) {
            $counts[$result['type']] = (int) $result['count'];
        }

        return $counts;
    }
}
