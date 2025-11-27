<?php

namespace App\Repository;

use DateTime;
use App\Entity\Person;
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
