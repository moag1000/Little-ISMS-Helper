<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;

/**
 * Phase 8L / 8QW — Auto-Seeding-Listener (deaktiviert).
 *
 * Historisch (Phase 8L.F2 + 8QW-5) sollten neue Tenants automatisch
 * Defaults für RiskApprovalConfig, IncidentSlaConfig und
 * SupplierCriticalityLevel bekommen. Die Umsetzung via postPersist führt
 * aber in Multi-Tenant-Tests (z.B. testIndexRespectsMultiTenancyIsolation)
 * zu Cascade-Problemen: während des noch laufenden Flush-Cycles persistiert
 * der Listener weitere Entities, deren Tenant-Reference Doctrine als
 * "neu-aber-nicht-cascaded" interpretiert.
 *
 * Pragmatischer Workaround:
 *   - Existierende Tenants: Defaults via Migration-Backfill geseedet
 *     (siehe Version20260423110000 / 20260423130000 / 20260421320000).
 *   - Neue Tenants: Admin-UI (IncidentSlaConfigController::index,
 *     SupplierCriticalityController::index) rufen ensureDefaultsFor
 *     lazy auf, persistieren sich selbst + flushen.
 *   - RiskApprovalConfig: wird beim ersten Admin-UI-Aufruf angelegt.
 *
 * Für eine saubere Lösung siehe TODO(9A/9B): Post-Commit-Hook oder
 * dedizierter TenantProvisioningService in CorporateStructureService.
 */
#[AsEntityListener(event: Events::postPersist, entity: Tenant::class)]
class TenantCreatedSeedListener
{
    public function postPersist(Tenant $tenant): void
    {
        // Intentionally empty — siehe Klassen-Doc für Rationale.
    }
}
