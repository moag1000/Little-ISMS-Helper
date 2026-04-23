<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\IncidentSlaConfig;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IncidentSlaConfig>
 */
class IncidentSlaConfigRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IncidentSlaConfig::class);
    }

    /**
     * @return IncidentSlaConfig[] Alle Severity-Configs eines Tenants.
     */
    public function findByTenant(Tenant $tenant): array
    {
        return $this->findBy(['tenant' => $tenant], ['severity' => 'ASC']);
    }

    public function findByTenantAndSeverity(Tenant $tenant, string $severity): ?IncidentSlaConfig
    {
        return $this->findOneBy(['tenant' => $tenant, 'severity' => $severity]);
    }

    /**
     * Seed-Defaults anlegen — idempotent.
     */
    public function ensureDefaultsFor(Tenant $tenant): void
    {
        if ($tenant->getId() === null) {
            return;
        }
        $existing = array_map(fn (IncidentSlaConfig $c) => $c->getSeverity(), $this->findByTenant($tenant));
        $em = $this->getEntityManager();
        foreach (IncidentSlaConfig::DEFAULTS as $severity => $hours) {
            if (in_array($severity, $existing, true)) {
                continue;
            }
            $row = new IncidentSlaConfig();
            $row->setTenant($tenant)->setSeverity($severity)->setResponseHours($hours);
            $em->persist($row);
        }
        // Kein flush() — Aufrufer (TenantCreatedSeedListener::postFlush oder
        // Admin-Controller) flusht explizit. So kein nested-flush-Problem.
    }
}
