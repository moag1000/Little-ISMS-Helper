<?php

declare(strict_types=1);

namespace App\Twig;

use App\Lifecycle\LifecycleRegistry;
use Twig\Attribute\AsTwigFunction;

/**
 * Twig glue for the LifecycleRegistry (audit-s3 foundation pattern P-4).
 *
 * Exposes two functions:
 *  - `lifecycle_tone(entityClass, statusKey)` — Aurora-v4 status-pill
 *    tone for a given (entityClass, status) pair. Maps semantic registry
 *    values like `info`/`muted` to valid Aurora variants.
 *  - `lifecycle_transitions(entityClass, currentStatus)` — list of
 *    allowed target statuses for bulk-action-bar dropdowns.
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
     * All registered status keys for an entity (e.g. for filter chips).
     *
     * @return list<string>
     */
    #[AsTwigFunction('lifecycle_stages')]
    public function lifecycleStages(string $entityClass): array
    {
        return $this->registry->getStages($entityClass);
    }
}
