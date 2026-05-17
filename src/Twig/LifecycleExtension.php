<?php

declare(strict_types=1);

namespace App\Twig;

use App\Lifecycle\LifecycleRegistry;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Attribute\AsTwigFunction;

/**
 * Twig glue for the LifecycleRegistry (audit-s3 foundation pattern P-4).
 *
 * Exposes three functions:
 *  - `lifecycle_tone(entityClass, statusKey)` — Aurora-v4 status-pill
 *    tone for a given (entityClass, status) pair. Maps semantic registry
 *    values like `info`/`muted` to valid Aurora variants.
 *  - `lifecycle_transitions(entityClass, currentStatus)` — list of
 *    allowed target statuses for bulk-action-bar dropdowns.
 *  - `lifecycle_can(entity, workflowName, transitionName)` — true when
 *    the current user is allowed to perform the transition (lifecycle X.3).
 */
final class LifecycleExtension
{
    /**
     * Semantic registry tone → Aurora status-pill variant. Aurora v4
     * supports only `primary | accent | success | warning | danger |
     * neutral`. Bootstrap-style `info` is folded into `primary`, the
     * archived `muted` reads as `neutral` (no-emphasis).
     */
    private const array TONE_TO_AURORA = [
        'primary' => 'primary',
        'accent' => 'accent',
        'success' => 'success',
        'warning' => 'warning',
        'danger' => 'danger',
        'neutral' => 'neutral',
        'info' => 'primary',
        'muted' => 'neutral',
    ];

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
        $tone = $this->registry->getTone($entityClass, $statusKey);
        return self::TONE_TO_AURORA[$tone] ?? 'neutral';
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
