<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Location;
use App\Entity\Tenant;
use App\Entity\User;
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
     * Find Locations visible to the given User within the given Tenant.
     *
     * Visibility = tenant-scoped + active. The user's role hierarchy
     * (ROLE_USER baseline) is enforced by the upstream voter / IsGranted
     * attribute on the calling controller; this query only applies the
     * tenant boundary so cross-tenant rows are never returned.
     *
     * Used by the Policy-Wizard W2 organisation_scope step to populate
     * the "in-scope sites" multi-select. Sorted by name for predictable
     * picker UX.
     *
     * @return list<Location>
     */
    public function findVisibleForUserAndTenant(User $user, Tenant $tenant): array
    {
        // Defensive: ROLE_USER is the baseline for any access at all.
        // The wizard route already gates on POLICY_WIZARD_RUN_FULL which
        // implies ROLE_USER, but we mirror the contract here so callers
        // outside the wizard cannot accidentally bypass it.
        if (!in_array('ROLE_USER', $user->getRoles(), true)
            && !in_array('ROLE_AUDITOR', $user->getRoles(), true)
            && !in_array('ROLE_MANAGER', $user->getRoles(), true)
            && !in_array('ROLE_ADMIN', $user->getRoles(), true)
            && !in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true)
        ) {
            return [];
        }

        /** @var list<Location> $rows */
        $rows = $this->createQueryBuilder('l')
            ->andWhere('l.tenant = :tenant')
            ->andWhere('l.active = :active')
            ->setParameter('tenant', $tenant)
            ->setParameter('active', true)
            ->orderBy('l.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
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
