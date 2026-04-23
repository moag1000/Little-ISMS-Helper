<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SupplierCriticalityLevel;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SupplierCriticalityLevel>
 */
class SupplierCriticalityLevelRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SupplierCriticalityLevel::class);
    }

    /**
     * Returns all active levels for a tenant, ordered by sortOrder ASC.
     *
     * @return SupplierCriticalityLevel[]
     */
    public function findActiveByTenant(Tenant $tenant): array
    {
        return $this->createQueryBuilder('scl')
            ->where('scl.tenant = :tenant')
            ->andWhere('scl.isActive = true')
            ->setParameter('tenant', $tenant)
            ->orderBy('scl.sortOrder', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Returns all levels (active and inactive) for admin management.
     *
     * @return SupplierCriticalityLevel[]
     */
    public function findAllByTenant(Tenant $tenant): array
    {
        return $this->createQueryBuilder('scl')
            ->where('scl.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('scl.sortOrder', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find a specific level by code for a tenant.
     */
    public function findByTenantAndCode(Tenant $tenant, string $code): ?SupplierCriticalityLevel
    {
        return $this->findOneBy(['tenant' => $tenant, 'code' => $code]);
    }

    /**
     * Returns the default level for a tenant (first active default, or first active).
     */
    public function findDefaultForTenant(Tenant $tenant): ?SupplierCriticalityLevel
    {
        $default = $this->findOneBy(['tenant' => $tenant, 'isDefault' => true, 'isActive' => true]);
        if ($default !== null) {
            return $default;
        }
        // Fallback: first active level
        $levels = $this->findActiveByTenant($tenant);
        return $levels[0] ?? null;
    }

    /**
     * Idempotent seed: creates the 4 standard levels if the tenant has none yet.
     * Called from TenantCreatedSeedListener (postPersist) and migration backfill.
     */
    public function ensureDefaultsFor(Tenant $tenant): void
    {
        if (count($this->findAllByTenant($tenant)) > 0) {
            return;
        }

        $em = $this->getEntityManager();
        $defaults = [
            ['code' => 'critical', 'labelDe' => 'Kritisch', 'labelEn' => 'Critical', 'sortOrder' => 10, 'color' => 'danger', 'isDefault' => false],
            ['code' => 'high', 'labelDe' => 'Hoch', 'labelEn' => 'High', 'sortOrder' => 20, 'color' => 'warning', 'isDefault' => false],
            ['code' => 'medium', 'labelDe' => 'Mittel', 'labelEn' => 'Medium', 'sortOrder' => 30, 'color' => 'info', 'isDefault' => true],
            ['code' => 'low', 'labelDe' => 'Gering', 'labelEn' => 'Low', 'sortOrder' => 40, 'color' => 'secondary', 'isDefault' => false],
        ];

        foreach ($defaults as $data) {
            $level = (new SupplierCriticalityLevel())
                ->setTenant($tenant)
                ->setCode($data['code'])
                ->setLabelDe($data['labelDe'])
                ->setLabelEn($data['labelEn'])
                ->setSortOrder($data['sortOrder'])
                ->setColor($data['color'])
                ->setIsDefault($data['isDefault'])
                ->setIsActive(true);
            $em->persist($level);
        }
        $em->flush();
    }
}
