<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Tenant;
use App\Entity\TisaxCrosswalkEntry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TisaxCrosswalkEntry>
 */
final class TisaxCrosswalkEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TisaxCrosswalkEntry::class);
    }

    /**
     * Confirmed legacy-id → canonical-id map for a tenant.
     *
     * @return array<string, string>
     */
    public function confirmedMapFor(Tenant $tenant): array
    {
        $map = [];
        foreach ($this->findBy(['tenant' => $tenant]) as $entry) {
            $map[$entry->getLegacyId()] = $entry->getCanonicalId();
        }
        return $map;
    }

    /** Upsert a confirmed crosswalk entry for a tenant (idempotent by tenant+legacyId). */
    public function upsert(Tenant $tenant, string $legacyId, string $canonicalId, string $confidence, string $source): TisaxCrosswalkEntry
    {
        $entry = $this->findOneBy(['tenant' => $tenant, 'legacyId' => $legacyId]) ?? new TisaxCrosswalkEntry();
        $entry->setTenant($tenant);
        $entry->setLegacyId($legacyId);
        $entry->setCanonicalId($canonicalId);
        $entry->setConfidence($confidence);
        $entry->setSource($source);
        $this->getEntityManager()->persist($entry);
        return $entry;
    }
}
