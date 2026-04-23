<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\RiskApprovalConfig;
use App\Entity\Tenant;
use App\Repository\RiskApprovalConfigRepository;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Read-only Value-Object für aufgelöste Approval-Schwellwerte.
 *
 * Entkoppelt Konsumenten (RiskAcceptanceWorkflowService) von der Entity,
 * damit Phase 8M später child+parent-Ceiling-Merge einfügen kann ohne
 * Konsumenten-Änderung.
 */
final readonly class RiskApprovalConfigView
{
    public function __construct(
        public int $thresholdAutomatic,
        public int $thresholdManager,
        public int $thresholdExecutive,
    ) {
    }

    public static function defaults(): self
    {
        // Synchron mit alten RiskAcceptanceWorkflowService-Konstanten.
        return new self(3, 7, 25);
    }

    public static function fromEntity(RiskApprovalConfig $entity): self
    {
        return new self(
            $entity->getThresholdAutomatic(),
            $entity->getThresholdManager(),
            $entity->getThresholdExecutive(),
        );
    }
}

/**
 * Phase 8L.F1 — Resolver für Approval-Schwellwerte.
 *
 * Einziger Zugang zur Approval-Config. Konsumenten rufen nie direkt das
 * Repository — damit Phase 8M Holding-Ceiling intern in den Resolver
 * einbauen kann, ohne dass sich der Service-Kontrakt ändert.
 *
 * Request-scoped Cache (array) — Config ändert selten, eine DB-Query
 * pro Request reicht. Kein APCu/Redis (Premature Optimization).
 */
class RiskApprovalConfigResolver
{
    /** @var array<int, RiskApprovalConfigView> */
    private array $cache = [];

    public function __construct(
        private readonly RiskApprovalConfigRepository $repository,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function resolveFor(Tenant $tenant): RiskApprovalConfigView
    {
        $tenantId = $tenant->getId();
        if ($tenantId !== null && isset($this->cache[$tenantId])) {
            return $this->cache[$tenantId];
        }

        $entity = $this->repository->findByTenant($tenant);
        if (!$entity instanceof RiskApprovalConfig) {
            $this->logger->warning('No RiskApprovalConfig for tenant — using defaults', [
                'tenant_id' => $tenantId,
            ]);
            $view = RiskApprovalConfigView::defaults();
        } else {
            $view = RiskApprovalConfigView::fromEntity($entity);
        }

        if ($tenantId !== null) {
            $this->cache[$tenantId] = $view;
        }
        return $view;
    }

    /**
     * Cache nach Entity-Update invalidieren (Admin-UI ruft das auf).
     */
    public function invalidate(Tenant $tenant): void
    {
        $tenantId = $tenant->getId();
        if ($tenantId !== null) {
            unset($this->cache[$tenantId]);
        }
    }
}
