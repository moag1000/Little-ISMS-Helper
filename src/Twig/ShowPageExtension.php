<?php

declare(strict_types=1);

namespace App\Twig;

use App\Repository\AuditLogRepository;
use App\Service\Detail\EntityLinks;
use App\Service\RoleDashboardService;
use Twig\Attribute\AsTwigFunction;

/**
 * Data for the detail-page skeleton (UI wave E): the evidence chain for the
 * links block and the running approval for the status band. Both are read
 * where the page renders, so a controller does not have to pass them.
 */
final class ShowPageExtension
{
    public function __construct(
        private readonly EntityLinks $entityLinks,
        private readonly RoleDashboardService $roleDashboardService,
        private readonly AuditLogRepository $auditLogRepository,
    ) {
    }

    /** @return list<array<string, mixed>> */
    #[AsTwigFunction('entity_links')]
    public function entityLinks(object $entity): array
    {
        return $this->entityLinks->for($entity);
    }

    /**
     * The approval workflow currently running for the record, or [] when none.
     *
     * @return array<string, mixed>
     */
    #[AsTwigFunction('workflow_state')]
    public function workflowState(object $entity): array
    {
        if (!method_exists($entity, 'getId') || $entity->getId() === null) {
            return [];
        }
        return $this->roleDashboardService->getWorkflowInfoForEntity(self::shortName($entity), (int) $entity->getId());
    }

    /**
     * The newest audit-log entries of the record for the history block, for
     * pages whose controller does not pass them.
     *
     * @return array{logs: list<\App\Entity\AuditLog>, total: int}
     */
    #[AsTwigFunction('show_history')]
    public function showHistory(object $entity, int $limit = 10): array
    {
        if (!method_exists($entity, 'getId') || $entity->getId() === null) {
            return ['logs' => [], 'total' => 0];
        }
        $type = self::shortName($entity);
        $id = (int) $entity->getId();

        return [
            'logs' => $this->auditLogRepository->findRecentByEntity($type, $id, $limit),
            'total' => $this->auditLogRepository->countByEntity($type, $id),
        ];
    }

    /** Audit log and workflow instances key records by the short class name. */
    #[AsTwigFunction('show_entity_type')]
    public function entityType(object $entity): string
    {
        return self::shortName($entity);
    }

    public static function shortName(object $entity): string
    {
        $class = $entity::class;
        $pos = strrpos($class, '\\');

        return $pos === false ? $class : substr($class, $pos + 1);
    }
}
