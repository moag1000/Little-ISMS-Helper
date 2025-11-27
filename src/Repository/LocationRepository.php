<?php

namespace App\Repository;

use App\Entity\Location;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Location>
 */
class LocationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Location::class);
    }

    /**
     * Find active locations
     */
    public function findActive(): array
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.active = :active')
            ->setParameter('active', true)
            ->orderBy('l.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find locations by type
     */
    public function findByType(string $type): array
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.locationType = :type')
            ->setParameter('type', $type)
            ->orderBy('l.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find high security locations
     */
    public function findHighSecurity(): array
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.securityLevel IN (:levels)')
            ->setParameter('levels', ['secure', 'high_security'])
            ->orderBy('l.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find locations requiring badge access
     */
    public function findRequiringBadgeAccess(): array
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.requiresBadgeAccess = :required')
            ->setParameter('required', true)
            ->orderBy('l.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find locations requiring escort
     */
    public function findRequiringEscort(): array
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.requiresEscort = :required')
            ->setParameter('required', true)
            ->orderBy('l.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find top-level locations (no parent)
     */
    public function findTopLevel(): array
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.parentLocation IS NULL')
            ->orderBy('l.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find child locations of a parent
     */
    public function findChildren(Location $location): array
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.parentLocation = :parent')
            ->setParameter('parent', $location)
            ->orderBy('l.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Search locations by name or code
     */
    public function search(string $query): array
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.name LIKE :query OR l.code LIKE :query')
            ->setParameter('query', '%' . $query . '%')
            ->orderBy('l.name', 'ASC')
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
            'high_security' => count($this->findHighSecurity()),
            'requiring_badge' => count($this->findRequiringBadgeAccess()),
            'requiring_escort' => count($this->findRequiringEscort()),
            'by_type' => $this->getCountByType(),
            'by_security_level' => $this->getCountBySecurityLevel(),
        ];
    }

    private function getCountByType(): array
    {
        $results = $this->createQueryBuilder('l')
            ->select('l.locationType as type, COUNT(l.id) as count')
            ->groupBy('l.locationType')
            ->getQuery()
            ->getResult();

        $counts = [];
        foreach ($results as $result) {
            $counts[$result['type']] = (int) $result['count'];
        }

        return $counts;
    }

    private function getCountBySecurityLevel(): array
    {
        $results = $this->createQueryBuilder('l')
            ->select('l.securityLevel as level, COUNT(l.id) as count')
            ->groupBy('l.securityLevel')
            ->getQuery()
            ->getResult();

        $counts = [];
        foreach ($results as $result) {
            $counts[$result['level']] = (int) $result['count'];
        }

        return $counts;
    }
}
