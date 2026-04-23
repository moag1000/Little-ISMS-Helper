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

        $entity = $this->repository->findByTenantAndSeverity($tenant, $severity);
        if (!$entity instanceof IncidentSlaConfig) {
            $this->logger->warning('No IncidentSlaConfig for tenant/severity — using default', [
                'tenant_id' => $tenantId,
                'severity' => $severity,
            ]);
            $view = IncidentSlaView::fromDefault($severity);
        } else {
            $view = IncidentSlaView::fromEntity($entity);
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
}
