<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\RiskApprovalConfig;
use App\Entity\Tenant;
use App\Repository\IncidentSlaConfigRepository;
use App\Repository\SupplierCriticalityLevelRepository;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;

/**
 * Phase 8L — Default-Configs bei neuem Tenant anlegen.
 *
 * Idempotent: legt nur an was fehlt. Läuft als postPersist auf Tenant,
 * sodass neue Mandanten automatisch Risk-Approval + Incident-SLA-Defaults
 * bekommen ohne dass TenantService / CorporateStructureService selbst
 * seeden muss.
 *
 * Phase 8QW-5: Erweitert um SupplierCriticalityLevel-Defaults (4 Stufen).
 */
#[AsEntityListener(event: Events::postPersist, entity: Tenant::class)]
class TenantCreatedSeedListener
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly IncidentSlaConfigRepository $incidentSlaRepo,
        private readonly SupplierCriticalityLevelRepository $supplierCriticalityRepo,
    ) {
    }

    public function postPersist(Tenant $tenant): void
    {
        // 1. Risk-Approval-Config (3/7/25 Defaults)
        $existing = $this->entityManager->getRepository(RiskApprovalConfig::class)->findOneBy(['tenant' => $tenant]);
        if ($existing === null) {
            $config = (new RiskApprovalConfig())
                ->setTenant($tenant)
                ->setThresholdAutomatic(3)
                ->setThresholdManager(7)
                ->setThresholdExecutive(25);
            $this->entityManager->persist($config);
            $this->entityManager->flush();
        }

        // 2. Incident-SLA-Defaults (5 Severities).
        $this->incidentSlaRepo->ensureDefaultsFor($tenant);

        // 3. Supplier-Kritikalitätsstufen (4 Defaults: critical/high/medium/low).
        $this->supplierCriticalityRepo->ensureDefaultsFor($tenant);
    }
}
