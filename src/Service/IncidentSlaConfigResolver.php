<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\IncidentSlaConfig;
use App\Entity\Tenant;
use App\Repository\IncidentSlaConfigRepository;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Phase 8L.F2 — Read-only Value-Object für aufgelöste SLA pro Severity.
 */
final readonly class IncidentSlaView
{
    public function __construct(
        public string $severity,
        public int $responseHours,
        public ?int $escalationHours,
        public ?int $resolutionHours,
    ) {
    }

    public static function fromDefault(string $severity): self
    {
        $hours = IncidentSlaConfig::DEFAULTS[$severity] ?? 24;
        return new self($severity, $hours, null, null);
    }

    public static function fromEntity(IncidentSlaConfig $entity): self
    {
        return new self(
            (string) $entity->getSeverity(),
            $entity->getResponseHours(),
            $entity->getEscalationHours(),
            $entity->getResolutionHours(),
        );
    }
}

/**
 * Phase 8L.F2 — Resolver für Incident-SLAs.
 *
 * Einziger Zugang zur SLA-Config. Konsumenten rufen nie direkt Repository.
 * Default-Fallback wenn kein Record da (Robustheit: Incident-Flow darf
 * nie brechen).
 *
 * Cache pro (Tenant-ID, Severity).
 *
 * Phase 8M.2 — Ceiling-Merge pro Severity:
 * Ein Holding-Parent setzt Maximum-Stunden. Child-Tenants dürfen nur
 * NIEDRIGERE (schnellere/strengere) Stunden setzen. Null bedeutet
 * „kein Wert gesetzt" — minNullable() behandelt das korrekt.
 * Merge-Semantik: min(child, parent) für alle drei Hours-Felder.
 *
 * Cache-Invalidation: wie RiskApprovalConfigResolver — bei Ancestor-
 * Änderung müssen Child-Caches ebenfalls invalidiert werden.
 */
class IncidentSlaConfigResolver
{
    /** @var array<string, IncidentSlaView> */
    private array $cache = [];

    public function __construct(
        private readonly IncidentSlaConfigRepository $repository,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function resolveFor(Tenant $tenant, string $severity): IncidentSlaView
    {
        $tenantId = $tenant->getId();
        $cacheKey = sprintf('%d:%s', $tenantId ?? 0, $severity);
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        // Eigene Config des Child-Tenants (oder Default wenn keine vorhanden)
        $childEntity = $this->repository->findByTenantAndSeverity($tenant, $severity);
        if (!$childEntity instanceof IncidentSlaConfig) {
            $this->logger->warning('No IncidentSlaConfig for tenant/severity — using default', [
                'tenant_id' => $tenantId,
                'severity' => $severity,
            ]);
            $view = IncidentSlaView::fromDefault($severity);
        } else {
            $view = IncidentSlaView::fromEntity($childEntity);
        }

        // 8M.2: Ceiling-Merge pro Severity — Ancestor-Walk (Parent zuerst, Root zuletzt).
        // min() stellt sicher, dass Child nicht laxere SLAs als Holding-Parent bekommt.
        foreach ($tenant->getAllAncestors() as $ancestor) {
            $ancestorEntity = $this->repository->findByTenantAndSeverity($ancestor, $severity);
            if (!$ancestorEntity instanceof IncidentSlaConfig) {
                continue;
            }
            $ancestorView = IncidentSlaView::fromEntity($ancestorEntity);
            $view = new IncidentSlaView(
                severity:        $severity,
                responseHours:   min($view->responseHours, $ancestorView->responseHours),
                escalationHours: $this->minNullable($view->escalationHours, $ancestorView->escalationHours),
                resolutionHours: $this->minNullable($view->resolutionHours, $ancestorView->resolutionHours),
            );
            $this->logger->debug('IncidentSlaConfigResolver: ceiling-merge with ancestor', [
                'tenant_id' => $tenantId,
                'ancestor_id' => $ancestor->getId(),
                'severity' => $severity,
            ]);
        }

        return $this->cache[$cacheKey] = $view;
    }

    public function invalidate(Tenant $tenant): void
    {
        $tenantId = $tenant->getId() ?? 0;
        foreach (array_keys($this->cache) as $key) {
            if (str_starts_with((string) $key, $tenantId . ':')) {
                unset($this->cache[$key]);
            }
        }
    }

    /**
     * Ceiling für nullable int: wenn einer der Werte null ist, wird der andere
     * zurückgegeben (null = „kein Limit gesetzt" = unendlich lax).
     * Wenn beide null → null (kein Limit in der Kette gesetzt).
     */
    private function minNullable(?int $a, ?int $b): ?int
    {
        if ($a === null) {
            return $b;
        }
        if ($b === null) {
            return $a;
        }
        return min($a, $b);
    }
}
