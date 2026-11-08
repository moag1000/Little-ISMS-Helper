<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\RiskApprovalConfig;
use App\Entity\Tenant;
use App\Repository\RiskApprovalConfigRepository;
use App\Risk\RiskMatrixThresholds;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Read-only Value-Object für aufgelöste Approval-Schwellwerte.
 *
 * Entkoppelt Konsumenten (RiskAcceptanceWorkflowService) von der Entity,
 * damit später child+parent-Ceiling-Merge einfügen kann ohne
 * Konsumenten-Änderung.
 */
final readonly class RiskApprovalConfigView
{
    public function __construct(
        public int $thresholdAutomatic,
        public int $thresholdManager,
        public int $thresholdExecutive,
        public int $bandCriticalMin = RiskMatrixThresholds::DEFAULT_CRITICAL_MIN,
        public int $bandHighMin = RiskMatrixThresholds::DEFAULT_HIGH_MIN,
        public int $bandMediumMin = RiskMatrixThresholds::DEFAULT_MEDIUM_MIN,
        /** @var array<string, string> level => what it means here */
        public array $probabilityCriteria = [],
        /** @var array<string, string> level => what it means here */
        public array $impactCriteria = [],
    ) {
    }

    public static function defaults(): self
    {
        // Synchron mit alten RiskAcceptanceWorkflowService-Konstanten.
        return new self(3, 7, 25);
    }

    /**
     * The stricter of two configurations: lower thresholds and lower band
     * edges (a risk reaches a higher band sooner). Level descriptions are the
     * subsidiary's own, the holding's where it has none.
     */
    public function stricter(self $other): self
    {
        return new self(
            min($this->thresholdAutomatic, $other->thresholdAutomatic),
            min($this->thresholdManager, $other->thresholdManager),
            min($this->thresholdExecutive, $other->thresholdExecutive),
            min($this->bandCriticalMin, $other->bandCriticalMin),
            min($this->bandHighMin, $other->bandHighMin),
            min($this->bandMediumMin, $other->bandMediumMin),
            $this->probabilityCriteria + $other->probabilityCriteria,
            $this->impactCriteria + $other->impactCriteria,
        );
    }

    /** Approval level for a residual risk score: 'automatic', 'manager' or 'executive'. */
    public function levelFor(int $residualScore): string
    {
        return match (true) {
            $residualScore <= $this->thresholdAutomatic => 'automatic',
            $residualScore <= $this->thresholdManager => 'manager',
            default => 'executive',
        };
    }

    /**
     * Minimum role that may approve an acceptance at this level. Executive
     * level is top management (ISO 27001 6.1.3 f; ROLE_ADMIN inherits it).
     */
    public static function approverRole(string $level): string
    {
        return $level === 'executive' ? 'ROLE_TOP_MGMT' : 'ROLE_MANAGER';
    }

    public static function fromEntity(RiskApprovalConfig $entity): self
    {
        return new self(
            $entity->getThresholdAutomatic(),
            $entity->getThresholdManager(),
            $entity->getThresholdExecutive(),
            $entity->getBandCriticalMin(),
            $entity->getBandHighMin(),
            $entity->getBandMediumMin(),
            $entity->getProbabilityCriteria() ?? [],
            $entity->getImpactCriteria() ?? [],
        );
    }
}

/**
 * Resolver für Approval-Schwellwerte.
 *
 * Einziger Zugang zur Approval-Config. Konsumenten rufen nie direkt das
 * Repository — damit Holding-Ceiling intern in den Resolver
 * einbauen kann, ohne dass sich der Service-Kontrakt ändert.
 *
 * Request-scoped Cache (array) — Config ändert selten, eine DB-Query
 * pro Request reicht. Kein APCu/Redis (Premature Optimization).
 *
 * Ceiling-Merge mit Holding-Hierarchie:
 * Ein Holding-Parent setzt Maximum-Schwellwerte. Child-Tenants dürfen
 * nur NIEDRIGERE (strengere) Werte setzen, nie höhere (laxere).
 * Merge-Semantik: min(child, parent) für die drei Threshold-Felder und die
 * drei Stufengrenzen der Risikoskala ({@see RiskApprovalConfigView::stricter()}).
 *
 * Cache-Invalidation: Admin-Controller ruft invalidate() für den
 * betroffenen Tenant auf. Da Ancestor-Werte in den Child-Cache
 * einfließen, MUSS bei Änderung eines Ancestor-Tenants auch der
 * Child-Cache invalidiert werden. Empfehlung: nach Speichern einer
 * Ancestor-Config alle bekannten Subsidiaries invalidieren.
 */
class RiskApprovalConfigResolver implements ResetInterface
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

        // Eigene Config des Child-Tenants (oder Defaults wenn keine vorhanden)
        $childEntity = $this->repository->findByTenant($tenant);
        if (!$childEntity instanceof RiskApprovalConfig) {
            $this->logger->debug('No RiskApprovalConfig for tenant — using defaults', [
                'tenant_id' => $tenantId,
            ]);
            $view = RiskApprovalConfigView::defaults();
        } else {
            $view = RiskApprovalConfigView::fromEntity($childEntity);
        }

        // Ceiling-Merge — Ancestor-Walk (Parent zuerst, Root zuletzt).
        // min() stellt sicher, dass Child nicht laxer als Holding-Parent werden kann.
        foreach ($tenant->getAllAncestors() as $ancestor) {
            $ancestorEntity = $this->repository->findByTenant($ancestor);
            if (!$ancestorEntity instanceof RiskApprovalConfig) {
                continue;
            }
            $ancestorView = RiskApprovalConfigView::fromEntity($ancestorEntity);
            $view = $view->stricter($ancestorView);
            $this->logger->debug('RiskApprovalConfigResolver: ceiling-merge with ancestor', [
                'tenant_id' => $tenantId,
                'ancestor_id' => $ancestor->getId(),
            ]);
        }

        if ($tenantId !== null) {
            $this->cache[$tenantId] = $view;
        }
        return $view;
    }

    /**
     * Cache nach Entity-Update invalidieren (Admin-UI ruft das auf).
     *
     * Wichtig: Wird ein Ancestor-Tenant geändert, müssen auch alle
     * Child-Tenant-Caches invalidiert werden, da deren aufgelöste Werte
     * vom Ancestor abhängen.
     */
    /** Workers: forget cached configurations between messages (kernel.reset). */
    public function reset(): void
    {
        $this->cache = [];
    }

    public function invalidate(Tenant $tenant): void
    {
        $tenantId = $tenant->getId();
        if ($tenantId !== null) {
            unset($this->cache[$tenantId]);
        }
    }
}
