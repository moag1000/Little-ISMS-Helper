<?php

declare(strict_types=1);

namespace App\Twig;

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
        $type = EntityLinks::typeOf($entity);
        $shortName = $type !== null ? ucfirst($type) : (new \ReflectionClass($entity))->getShortName();

        return $this->roleDashboardService->getWorkflowInfoForEntity($shortName, (int) $entity->getId());
    }
}
