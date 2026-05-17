<?php

declare(strict_types=1);

namespace App\Lifecycle;

/**
 * UI-helper metadata only. Transition logic lives in Symfony Workflow
 * config (`config/workflows/*.yaml`). This class survives to keep the
 * LifecycleExtension Twig helper (tone/label rendering) working.
 *
 * The static stage maps (STANDARD_5_STAGE, FINDING_4_STAGE, CAPA_STAGES)
 * are retained solely to back the `#[Lifecycle]` attribute pattern used
 * on entity classes — they inform the Twig helpers (tone, transitions,
 * stages) without containing any transition-enforcement logic.
 *
 * @phpstan-type ToneMap array<string, string>
 * @phpstan-type LifecycleStage array{transitions: array<int, string>, tone: string}
 * @phpstan-type LifecycleMap array<string, LifecycleStage>
 */
final class LifecycleRegistry
{
    /**
     * Canonical 5-stage flow per CLAUDE.md.
     *
     * @var LifecycleMap
     */
    public const array STANDARD_5_STAGE = [
        'draft' => [
            'transitions' => ['in_review'],
            'tone' => 'neutral',
        ],
        'in_review' => [
            'transitions' => ['approved', 'draft'],
            'tone' => 'info',
        ],
        'approved' => [
            'transitions' => ['published'],
            'tone' => 'success',
        ],
        'published' => [
            'transitions' => ['archived'],
            'tone' => 'primary',
        ],
        'archived' => [
            'transitions' => ['published'],
            'tone' => 'muted',
        ],
    ];

    /**
     * AuditFinding / CorrectiveAction discovery-style lifecycle.
     *
     * @var LifecycleMap
     */
    public const array FINDING_4_STAGE = [
        'open' => [
            'transitions' => ['in_progress'],
            'tone' => 'warning',
        ],
        'in_progress' => [
            'transitions' => ['resolved', 'open'],
            'tone' => 'info',
        ],
        'resolved' => [
            'transitions' => ['closed', 'in_progress'],
            'tone' => 'success',
        ],
        'closed' => [
            'transitions' => [],
            'tone' => 'muted',
        ],
    ];

    /**
     * Corrective Action lifecycle (ISO 27001 Cl. 10.1).
     *
     * @var LifecycleMap
     */
    public const array CAPA_STAGES = [
        'planned' => [
            'transitions' => ['in_progress', 'cancelled'],
            'tone' => 'info',
        ],
        'in_progress' => [
            'transitions' => ['completed', 'cancelled'],
            'tone' => 'warning',
        ],
        'completed' => [
            'transitions' => ['verified_effective', 'verified_ineffective'],
            'tone' => 'primary',
        ],
        'verified_effective' => [
            'transitions' => [],
            'tone' => 'success',
        ],
        'verified_ineffective' => [
            'transitions' => [],
            'tone' => 'danger',
        ],
        'cancelled' => [
            'transitions' => [],
            'tone' => 'muted',
        ],
    ];

    /** @var ToneMap */
    private const array TONE_MAP = [
        'draft' => 'neutral',
        'in_review' => 'info',
        'approved' => 'success',
        'published' => 'primary',
        'archived' => 'muted',
        'deleted' => 'danger',
    ];

    /**
     * Returns the lifecycle map for the given entity class.
     * Defaults to {@see STANDARD_5_STAGE} if no `#[Lifecycle]` attribute
     * is set on the class.
     *
     * @return LifecycleMap
     */
    public function getLifecycle(string $entityClass): array
    {
        if (!class_exists($entityClass)) {
            return self::STANDARD_5_STAGE;
        }
        $reflection = new \ReflectionClass($entityClass);
        $attributes = $reflection->getAttributes(Lifecycle::class);
        if ($attributes !== []) {
            return $attributes[0]->newInstance()->stages;
        }
        return self::STANDARD_5_STAGE;
    }

    /**
     * @return list<string>
     */
    public function getStages(string $entityClass): array
    {
        return array_keys($this->getLifecycle($entityClass));
    }

    /**
     * @return list<string>
     */
    public function getAllowedTransitions(string $entityClass, string $currentStatus): array
    {
        $lifecycle = $this->getLifecycle($entityClass);
        $entry = $lifecycle[$currentStatus] ?? null;
        if ($entry === null) {
            return [];
        }
        return array_values($entry['transitions']);
    }

    /**
     * Semantic tone for the given status. Checks the entity-class lifecycle
     * map first, then falls back to the status-name TONE_MAP for generic
     * status keys not covered by a specific lifecycle.
     */
    public function getTone(string $entityClass, string $status): string
    {
        $lifecycle = $this->getLifecycle($entityClass);
        if (isset($lifecycle[$status]['tone'])) {
            return $lifecycle[$status]['tone'];
        }
        return self::TONE_MAP[$status] ?? 'neutral';
    }

    /**
     * Simple tone lookup by status key only (no entity context needed).
     */
    public function tone(string $status): string
    {
        return self::TONE_MAP[$status] ?? 'neutral';
    }
}
