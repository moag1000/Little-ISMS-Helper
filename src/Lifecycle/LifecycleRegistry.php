<?php

declare(strict_types=1);

namespace App\Lifecycle;

use Symfony\Component\Workflow\Registry;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * UI metadata of the lifecycles: tone, places and next places of a status.
 *
 * The source is the entity's Symfony workflow (`config/workflows/*.yaml`): its
 * places are the statuses, `metadata.tone` on each place is the pill tone, the
 * transitions give the next statuses (W7 — before, the stages came from a
 * `#[Lifecycle]` attribute no entity carried, so every status outside the
 * 5-stage document flow rendered neutral).
 *
 * Entities without a workflow fall back to {@see STANDARD_5_STAGE}.
 *
 * @phpstan-type LifecycleStage array{transitions: list<string>, tone: string}
 * @phpstan-type LifecycleMap array<string, LifecycleStage>
 */
final class LifecycleRegistry
{
    /** Aurora status-pill tones; `tone` metadata on a place must be one of them. */
    public const array TONES = ['primary', 'accent', 'success', 'warning', 'danger', 'neutral'];

    /**
     * Canonical 5-stage flow per CLAUDE.md (fallback for entities without a workflow).
     * Tones equal the `document_lifecycle` places.
     *
     * @var LifecycleMap
     */
    public const array STANDARD_5_STAGE = [
        'draft' => ['transitions' => ['in_review'], 'tone' => 'neutral'],
        'in_review' => ['transitions' => ['approved', 'draft'], 'tone' => 'primary'],
        'approved' => ['transitions' => ['published'], 'tone' => 'success'],
        'published' => ['transitions' => ['archived'], 'tone' => 'success'],
        'archived' => ['transitions' => ['published'], 'tone' => 'neutral'],
    ];

    /** @var array<string, LifecycleMap> */
    private array $cache = [];

    public function __construct(
        private readonly ?Registry $workflows = null,
    ) {
    }

    /**
     * Valid status STRINGS of the canonical 5-stage flow — i.e. the KEYS of
     * STANDARD_5_STAGE, not the stage maps themselves.
     *
     * Use this (not the const) as the `callback` for `#[Assert\Choice]` on
     * status properties: `Assert\Choice(choices: STANDARD_5_STAGE)` compares the
     * stored value against the map's *values* (the per-stage arrays), so it
     * rejects every legitimate status — including 'draft'.
     *
     * @return list<string>
     */
    public static function standardStageKeys(): array
    {
        return array_keys(self::STANDARD_5_STAGE);
    }

    /**
     * The lifecycle of the entity class: its workflow's places with tone and
     * next places, else the standard 5-stage flow.
     *
     * @return LifecycleMap
     */
    public function getLifecycle(string $entityClass): array
    {
        if (isset($this->cache[$entityClass])) {
            return $this->cache[$entityClass];
        }
        $workflow = $this->workflowFor($entityClass);

        return $this->cache[$entityClass] = $workflow === null
            ? self::STANDARD_5_STAGE
            : self::stagesOf($workflow);
    }

    /**
     * Places of a workflow with their tone and next places.
     *
     * @return LifecycleMap
     */
    public static function stagesOf(WorkflowInterface $workflow): array
    {
        $definition = $workflow->getDefinition();
        $store = $workflow->getMetadataStore();
        $stages = [];
        foreach ($definition->getPlaces() as $place) {
            $tone = $store->getPlaceMetadata($place)['tone'] ?? 'neutral';
            $stages[$place] = [
                'transitions' => [],
                'tone' => \in_array($tone, self::TONES, true) ? $tone : 'neutral',
            ];
        }
        foreach ($definition->getTransitions() as $transition) {
            foreach ($transition->getFroms() as $from) {
                foreach ($transition->getTos() as $to) {
                    if (isset($stages[$from]) && !\in_array($to, $stages[$from]['transitions'], true)) {
                        $stages[$from]['transitions'][] = $to;
                    }
                }
            }
        }

        return $stages;
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
        return $this->getLifecycle($entityClass)[$currentStatus]['transitions'] ?? [];
    }

    /**
     * Aurora tone of the status in the entity's lifecycle; unknown status → neutral.
     */
    public function getTone(string $entityClass, string $status): string
    {
        return $this->getLifecycle($entityClass)[$status]['tone'] ?? 'neutral';
    }

    private function workflowFor(string $entityClass): ?WorkflowInterface
    {
        if ($this->workflows === null) {
            return null;
        }
        $subject = $this->subjectOf($entityClass);
        if ($subject === null) {
            return null;
        }
        foreach ($this->workflows->all($subject) as $workflow) {
            if (str_ends_with($workflow->getName(), '_lifecycle')) {
                return $workflow;
            }
        }

        return null;
    }

    /** A bare instance for the workflow registry's class-based support check. */
    private function subjectOf(string $entityClass): ?object
    {
        if (!class_exists($entityClass)) {
            return null;
        }
        $reflection = new \ReflectionClass($entityClass);
        if (!$reflection->isInstantiable()) {
            return null;
        }

        return $reflection->newInstanceWithoutConstructor();
    }
}
