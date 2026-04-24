<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\KpiThresholdConfig;
use App\Entity\Tenant;
use App\Repository\KpiThresholdConfigRepository;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Read-only Value-Object für aufgelöste KPI-Schwellwerte.
 *
 * Entkoppelt Konsumenten von der KpiThresholdConfig-Entity und
 * kapselt die Fallback-Kaskade für Holding-Hierarchien.
 */
final readonly class KpiThresholdView
{
    public function __construct(
        public int $goodThreshold,
        public int $warningThreshold,
    ) {
    }
}

/**
 * Phase 8M.3 — Resolver für KPI-Schwellwerte mit Fallback-Kaskade.
 *
 * Ersetzt den direkten Repository-Zugriff via
 * KpiThresholdConfigRepository::getThresholdMap(?Tenant).
 *
 * Merge-Semantik: Fallback (Pick-First), kein numerischer Merge.
 * Reihenfolge: Child-Tenant → nächster Ancestor → … → Root → Service-Default.
 * Der erste Treffer in der Kette gewinnt vollständig.
 *
 * Begründung für Fallback statt Ceiling/Floor:
 * KPI-Thresholds sind organisatorische Soll-Vorgaben, keine Sicherheits-
 * Mindestanforderungen. Ein Holding kann Defaults setzen, aber eine Tochter
 * darf diese vollständig überschreiben (z.B. strengere OKRs).
 *
 * Cache pro (Tenant-ID, kpiKey) — Request-scoped Array-Cache.
 *
 * Cache-Invalidation: Konsumenten (Admin-Controller für KpiThresholdConfig)
 * rufen invalidate(Tenant) auf. Bei Ancestor-Änderung: alle bekannten
 * Child-Caches via invalidate($childTenant) leeren.
 */
class KpiThresholdConfigResolver
{
    /** @var array<string, KpiThresholdView> Cache-Key: "{tenantId}:{kpiKey}" */
    private array $cache = [];

    public function __construct(
        private readonly KpiThresholdConfigRepository $repository,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Löst den KPI-Schwellwert für einen Tenant und KPI-Key auf.
     *
     * Fallback-Kaskade: Child → Ancestors (Parent → Grandparent → … → Root) → Service-Defaults.
     *
     * @param Tenant $tenant        Tenant für den aufgelöst wird
     * @param string $kpiKey        KPI-Schlüssel (z.B. 'assets', 'controls_implemented')
     * @param int    $defaultGood   Service-Default für "good" Threshold (wenn kein DB-Eintrag)
     * @param int    $defaultWarning Service-Default für "warning" Threshold (wenn kein DB-Eintrag)
     */
    public function resolveFor(Tenant $tenant, string $kpiKey, int $defaultGood, int $defaultWarning): KpiThresholdView
    {
        $cacheKey = sprintf('%d:%s', $tenant->getId() ?? 0, $kpiKey);
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        // Fallback-Kaskade: Child zuerst, dann alle Ancestors in Reihenfolge
        $candidates = [$tenant, ...$tenant->getAllAncestors()];
        foreach ($candidates as $candidate) {
            $row = $this->repository->findOneBy(['tenant' => $candidate, 'kpiKey' => $kpiKey]);
            if ($row instanceof KpiThresholdConfig) {
                $this->logger->debug('KpiThresholdConfigResolver: found config', [
                    'tenant_id' => $tenant->getId(),
                    'source_tenant_id' => $candidate->getId(),
                    'kpi_key' => $kpiKey,
                ]);
                return $this->cache[$cacheKey] = new KpiThresholdView(
                    (int) $row->getGoodThreshold(),
                    (int) $row->getWarningThreshold(),
                );
            }
        }

        // Kein Eintrag in der gesamten Hierarchie — Service-Default
        $this->logger->debug('KpiThresholdConfigResolver: no config found, using defaults', [
            'tenant_id' => $tenant->getId(),
            'kpi_key' => $kpiKey,
            'default_good' => $defaultGood,
            'default_warning' => $defaultWarning,
        ]);

        return $this->cache[$cacheKey] = new KpiThresholdView($defaultGood, $defaultWarning);
    }

    /**
     * Cache nach Entity-Update invalidieren (Admin-UI ruft das auf).
     *
     * Löscht alle Einträge für den gegebenen Tenant-Cache-Prefix.
     * Bei Ancestor-Änderung: invalidate() auch für alle Child-Tenants aufrufen.
     */
    public function invalidate(Tenant $tenant): void
    {
        $prefix = ($tenant->getId() ?? 0) . ':';
        foreach (array_keys($this->cache) as $key) {
            if (str_starts_with((string) $key, $prefix)) {
                unset($this->cache[$key]);
            }
        }
    }

    /**
     * Gesamten Cache leeren (z.B. nach Bulk-Import).
     */
    public function invalidateAll(): void
    {
        $this->cache = [];
    }
}
