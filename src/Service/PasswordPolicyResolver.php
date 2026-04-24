<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Tenant;
use App\Repository\SystemSettingsRepository;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Phase 8QW-4 / 8M.4 — Resolver für Passwort-Mindestlänge mit Holding-Floor-Logik.
 *
 * Sicherheitsprinzip: Ein Mandant darf nur strenger sein als seine
 * Ancestor-Tenants (Floor-Pattern = Maximum über die Holding-Kette).
 *
 * Merge-Semantik: Floor (max() über alle Werte in der Kette).
 * Die Holding-Hierarchie setzt ein Minimum. Child-Tenants dürfen die
 * Mindestlänge nur erhöhen (strenger machen), niemals senken.
 *
 * Aktuell liest der Resolver die globale SystemSetting 'security.password_min_length'
 * als absolutes Minimum. Die Holding-Walk-Architektur ist vollständig aktiviert
 * (Phase 8M.4) — sobald Phase 9A tenant-spezifische SystemSettings einführt,
 * genügt es, getTenantSpecificMinLength() zu implementieren.
 *
 * Cache-Invalidation: invalidate(null) löscht den Gesamt-Cache (z.B. nach
 * globaler Setting-Änderung). invalidate($tenant) löscht nur den Tenant-Eintrag.
 *
 * TODO(9A): getTenantSpecificMinLength() mit SystemSettingsRepository::getSettingForTenant()
 * implementieren, sobald SystemSettings.tenant_id verfügbar ist.
 *
 * Request-scoped Array-Cache — eine DB-Query pro Request reicht.
 */
class PasswordPolicyResolver
{
    private const GLOBAL_FLOOR = 8;

    /** @var array<int|string, int> */
    private array $cache = [];

    public function __construct(
        private readonly SystemSettingsRepository $systemSettingsRepository,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Gibt die effektive Passwort-Mindestlänge für den übergebenen Tenant zurück.
     *
     * Logik (Floor-Pattern):
     * 1. Globale Baseline aus SystemSettings 'security.password_min_length'
     * 2. Ancestor-Walk (Vorbereitung für Phase 9A): aktuell kein tenant-spezifischer Wert → kein Einfluss
     * 3. Eigener Tenant-Wert (Phase 9A-Vorbereitung: ebenfalls noch global)
     * 4. Maximum aller Werte = Mindestlänge darf nur höher (strenger) werden
     */
    public function resolveFor(Tenant $tenant): int
    {
        $tenantId = $tenant->getId();
        $cacheKey = $tenantId ?? 'no_id';

        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        // 1. Globale Baseline
        $globalMin = (int) $this->systemSettingsRepository->getSetting(
            'security',
            'password_min_length',
            self::GLOBAL_FLOOR
        );

        // Sanity-Clamp: globale Einstellung nie unter 1
        if ($globalMin < 1) {
            $this->logger->warning('GlobalPasswordMinLength invalid, falling back to GLOBAL_FLOOR', [
                'raw_value' => $globalMin,
                'fallback' => self::GLOBAL_FLOOR,
            ]);
            $globalMin = self::GLOBAL_FLOOR;
        }

        // 2. Ancestor-Walk (Phase 9A preparation).
        //    Wenn Ancestor-Tenants eigene Werte haben (nach Phase 9A),
        //    müssen sie hier in den max()-Pool einfließen.
        $ancestors = $tenant->getAllAncestors();
        $ancestorMax = $globalMin;
        foreach ($ancestors as $ancestor) {
            // Phase 9A: hier tenant-spezifische Einstellung lesen.
            // Aktuell: kein tenant-spezifischer Wert → kein Einfluss auf Floor.
            $ancestorValue = $this->getTenantSpecificMinLength($ancestor) ?? $globalMin;
            if ($ancestorValue > $ancestorMax) {
                $ancestorMax = $ancestorValue;
            }
        }

        // 3. Eigener Tenant-Wert (Phase 9A preparation).
        $ownValue = $this->getTenantSpecificMinLength($tenant) ?? $globalMin;

        // 4. Effektiver Wert = Maximum (strenger ist immer erlaubt, schwächer nie)
        $effective = max($globalMin, $ancestorMax, $ownValue);

        $this->logger->debug('PasswordPolicyResolver resolved min_length', [
            'tenant_id' => $tenantId,
            'global_min' => $globalMin,
            'ancestor_max' => $ancestorMax,
            'own_value' => $ownValue,
            'effective' => $effective,
        ]);

        $this->cache[$cacheKey] = $effective;
        return $effective;
    }

    /**
     * Cache nach Einstellungsänderung invalidieren.
     */
    public function invalidate(?Tenant $tenant = null): void
    {
        if ($tenant === null) {
            $this->cache = [];
            return;
        }
        $cacheKey = $tenant->getId() ?? 'no_id';
        unset($this->cache[$cacheKey]);
    }

    /**
     * Phase 9A Hook: tenant-spezifischen Wert lesen.
     * Aktuell: null (kein tenant-spezifischer Wert implementiert).
     * Phase 9A überschreibt diese Methode oder ergänzt SystemSettings.tenant_id.
     */
    private function getTenantSpecificMinLength(Tenant $tenant): ?int
    {
        // Phase 9A: hier SystemSettingsRepository::getSettingForTenant($tenant, ...) aufrufen.
        return null;
    }
}
