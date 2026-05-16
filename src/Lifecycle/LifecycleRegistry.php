<?php

declare(strict_types=1);

namespace App\Lifecycle;

/**
 * Declarative lifecycle registry — Single Source of Truth for status
 * lifecycles across entities (audit-s3 foundation pattern P-4).
 *
 * CLAUDE.md mandates the canonical 5-stage flow
 * `draft → in_review → approved → published → archived` plus the
 * documented side-paths `in_review → draft` and `archived → published`.
 *
 * Per-entity overrides happen via the {@see Lifecycle} attribute on the
 * entity class. Entities without the attribute fall back to
 * {@see STANDARD_5_STAGE}.
 *
 * Tone-values map to Aurora v4 status-pill variants. Aurora supports the
 * variants `primary`, `accent`, `success`, `warning`, `danger`, `neutral`.
 * Semantic tones used here (`info`, `muted`) are translated to valid
 * Aurora variants by the LifecycleExtension before rendering — keeping
 * the registry semantically rich without leaking design-tokens.
 *
 * @phpstan-type LifecycleStage array{transitions: array<int, string>, tone: string}
 * @phpstan-type LifecycleMap array<string, LifecycleStage>
 */
final class LifecycleRegistry
{
    /**
     * Canonical 5-stage flow per CLAUDE.md.
     *
     * Transitions matrix:
     *  - draft     → in_review
     *  - in_review → approved | draft
     *  - approved  → published
     *  - published → archived
     *  - archived  → published
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
     * Transitions matrix:
     *  - open        → in_progress
     *  - in_progress → resolved | open
     *  - resolved    → closed | in_progress
     *  - closed      → (terminal)
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
     * planned → in_progress → completed → verified_effective (END)
     *                              ↓                ↑
     *                              ↓        (success path)
     *                              ↓
     *                          verified_ineffective → triggers Tier-1 AlvaHint
     *                                                  + auto-pre-fills Folge-CAPA.
     *
     * cancelled is a terminal opt-out for actions abandoned before completion.
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
     * Semantic tone for the given status (raw registry value — may be
     * `info`/`muted`/etc.; map to Aurora variants via LifecycleExtension).
     */
    public function getTone(string $entityClass, string $status): string
    {
        $lifecycle = $this->getLifecycle($entityClass);
        return $lifecycle[$status]['tone'] ?? 'neutral';
    }

    public function isValidTransition(string $entityClass, string $from, string $to): bool
    {
        return in_array($to, $this->getAllowedTransitions($entityClass, $from), true);
    }

    public function hasStatus(string $entityClass, string $status): bool
    {
        return array_key_exists($status, $this->getLifecycle($entityClass));
    }
}
