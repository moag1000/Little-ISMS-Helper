<?php

declare(strict_types=1);

namespace App\Twig;

use App\Lifecycle\LifecycleRegistry;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Attribute\AsTwigFunction;

/**
 * Twig glue for the LifecycleRegistry.
 *
 * Exposes three functions:
 *  - `lifecycle_tone(entityClass, statusKey)` — Aurora-v4 status-pill
 *    tone of the status: `metadata.tone` of the place in the entity's
 *    workflow (config/workflows/*.yaml).
 *  - `lifecycle_transitions(entityClass, currentStatus)` — list of
 *    allowed target statuses for bulk-action-bar dropdowns.
 *  - `lifecycle_can(entity, workflowName, transitionName)` — true when
 *    the current user is allowed to perform the transition (lifecycle X.3).
 */
final class LifecycleExtension
{
    public function __construct(
        private readonly LifecycleRegistry $registry,
        private readonly Security $security,
    ) {
    }

    /**
     * Aurora status-pill tone for the given (entity-class, status) pair.
     */
    #[AsTwigFunction('lifecycle_tone')]
    public function lifecycleTone(string $entityClass, string $statusKey): string
    {
        return $this->registry->getTone($entityClass, $statusKey);
    }

    /**
     * Allowed target statuses for the bulk-action-bar / status-change
     * dropdown.
     *
     * @return list<string>
     */
    #[AsTwigFunction('lifecycle_transitions')]
    public function lifecycleTransitions(string $entityClass, string $currentStatus): array
    {
        return $this->registry->getAllowedTransitions($entityClass, $currentStatus);
    }

    /**
     * Returns true when the current user may perform the given lifecycle
     * transition on the entity. Delegates to LifecycleVoter via the
     * Symfony Security component.
     *
     * Usage in Twig:
     *   {% if lifecycle_can(document, 'document_lifecycle', 'submit_for_review') %}
     *       {# show button #}
     *   {% endif %}
     */
    #[AsTwigFunction('lifecycle_can')]
    public function lifecycleCan(object $entity, string $workflowName, string $transitionName): bool
    {
        return $this->security->isGranted(
            sprintf('lifecycle.%s.%s', $workflowName, $transitionName),
            $entity,
        );
    }
}
