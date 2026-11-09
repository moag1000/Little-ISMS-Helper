<?php

declare(strict_types=1);

namespace App\Workflow\Mirror;

use App\Entity\Workflow;
use App\Entity\WorkflowInstance;
use App\Entity\WorkflowStep;
use App\Repository\WorkflowRepository;
use App\Workflow\Loader\RegulatoryWorkflowLoader;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Keeps the database rows of the regulatory approval chains equal to their YAML (S17).
 *
 * The approval chains are defined in config/workflows/regulatory/*.yaml (`metadata.steps`,
 * read by {@see RegulatoryWorkflowLoader}). A running approval still needs a `Workflow` row with
 * `WorkflowStep` rows: `WorkflowInstance.workflow_id` is NOT NULL, and the approver of the current
 * step is read from its `WorkflowStep`. This class is the one writer of those rows: it finds the
 * shared row (no organisation) by `regulatory_metadata.title` + `entity_type`, creates it when it is
 * missing and rewrites its steps in place (step ids stay, so running approvals keep their current step
 * and history). A step the YAML dropped is removed only while no approval stands on it.
 *
 * Used by the Quick-Fix action "Workflow-Definitionen abgleichen", by `app:generate-regulatory-workflows`
 * and by {@see \App\Service\WorkflowService::startWorkflow()} when a chain has no row yet.
 */
final class RegulatoryWorkflowMirror
{
    public function __construct(
        private readonly RegulatoryWorkflowLoader $loader,
        private readonly WorkflowRepository $workflowRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Regulatory chains whose database row is missing or differs from the YAML.
     *
     * @return list<array{workflow: string, title: string, state: 'missing'|'differs', differences: list<string>}>
     */
    public function drift(): array
    {
        $drift = [];
        foreach ($this->definitions() as $name => $definition) {
            $row = $this->findRow($definition);
            if (!$row instanceof Workflow) {
                $drift[] = ['workflow' => $name, 'title' => $definition['title'], 'state' => 'missing', 'differences' => []];
                continue;
            }
            $differences = $this->differences($row, $definition);
            if ($differences !== []) {
                $drift[] = ['workflow' => $name, 'title' => $definition['title'], 'state' => 'differs', 'differences' => $differences];
            }
        }

        return $drift;
    }

    /**
     * Bring every regulatory row (or the one named) in line with its YAML and flush.
     *
     * @return array{created: int, updated: int, unchanged: int, kept_steps: int}
     */
    public function sync(?string $only = null): array
    {
        $result = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'kept_steps' => 0];
        foreach ($this->definitions() as $name => $definition) {
            if ($only !== null && $only !== $name) {
                continue;
            }
            $row = $this->findRow($definition);
            if (!$row instanceof Workflow) {
                $this->entityManager->persist($this->create($definition));
                ++$result['created'];
                continue;
            }
            if ($this->differences($row, $definition) === []) {
                ++$result['unchanged'];
                continue;
            }
            $result['kept_steps'] += $this->apply($row, $definition);
            ++$result['updated'];
        }
        $this->entityManager->flush();

        return $result;
    }

    /**
     * The row of one regulatory chain, created (persisted, not flushed) when it is missing.
     * Null when the name is no regulatory YAML chain.
     */
    public function ensure(string $workflowName): ?Workflow
    {
        $definition = $this->definitions()[$workflowName] ?? null;
        if ($definition === null) {
            return null;
        }
        $row = $this->findRow($definition);
        if ($row instanceof Workflow) {
            return $row;
        }
        $row = $this->create($definition);
        $this->entityManager->persist($row);

        return $row;
    }

    /** @return list<string> the YAML names of the regulatory chains */
    public function workflowNames(): array
    {
        return array_keys($this->definitions());
    }

    /**
     * @return array<string, array{title: string, description: string, entity_type: string, steps: list<array<string, mixed>>}>
     */
    private function definitions(): array
    {
        $definitions = [];
        foreach ($this->loader->getRegisteredRegulatoryWorkflowNames() as $name) {
            $meta = $this->loader->getRegulatoryMetadata($name) ?? [];
            $title = (string) ($meta['title'] ?? '');
            $entityType = (string) ($meta['entity_type'] ?? '');
            if ($title === '' || $entityType === '') {
                continue; // Gate 82 fails on such a YAML; nothing to mirror.
            }
            $definitions[$name] = [
                'title' => $title,
                'description' => (string) ($meta['description'] ?? ''),
                'entity_type' => $entityType,
                'steps' => $this->loader->getStepsForWorkflow($name) ?? [],
            ];
        }
        ksort($definitions);

        return $definitions;
    }

    /** @param array{title: string, entity_type: string} $definition */
    private function findRow(array $definition): ?Workflow
    {
        return $this->workflowRepository->findOneBy([
            'name' => $definition['title'],
            'entityType' => $definition['entity_type'],
            'tenant' => null,
        ], ['id' => \SortDirection::Ascending]);
    }

    /** @param array{title: string, description: string, entity_type: string, steps: list<array<string, mixed>>} $definition */
    private function create(array $definition): Workflow
    {
        $row = new Workflow();
        $row->setName($definition['title']);
        $row->setEntityType($definition['entity_type']);
        $this->apply($row, $definition);

        return $row;
    }

    /**
     * Write the YAML onto the row; returns how many dropped steps had to stay because an approval
     * stands on them.
     *
     * @param array{title: string, description: string, entity_type: string, steps: list<array<string, mixed>>} $definition
     */
    private function apply(Workflow $row, array $definition): int
    {
        $row->setDescription($definition['description']);
        $row->setIsActive(true);

        $existing = array_values($row->getSteps()->toArray());
        foreach ($definition['steps'] as $index => $stepDefinition) {
            $step = $existing[$index] ?? null;
            if (!$step instanceof WorkflowStep) {
                $step = new WorkflowStep();
                $row->addStep($step);
            }
            $this->writeStep($step, $stepDefinition, $index);
        }

        $kept = 0;
        foreach (array_slice($existing, count($definition['steps'])) as $surplus) {
            if ($this->isInUse($surplus)) {
                ++$kept;
                continue;
            }
            $row->removeStep($surplus);
        }

        return $kept;
    }

    /** @param array<string, mixed> $definition */
    private function writeStep(WorkflowStep $step, array $definition, int $index): void
    {
        $expected = self::expectedStep($definition, $index);
        $step->setName($expected['name']);
        $step->setDescription($expected['description']);
        $step->setStepOrder($expected['order']);
        $step->setStepType($expected['type']);
        $step->setApproverRole($expected['role']);
        $step->setDaysToComplete($expected['days']);
        $step->setIsRequired($expected['required']);

        $metadata = $step->getMetadata() ?? [];
        unset($metadata['autoProgressConditions'], $metadata['rejectAction'], $metadata['rejectTargetStep']);
        if ($expected['conditions'] !== null) {
            $metadata['autoProgressConditions'] = $expected['conditions'];
        }
        if ($expected['loop_back_to'] !== null) {
            $metadata['rejectAction'] = 'loop_back';
            $metadata['rejectTargetStep'] = $expected['loop_back_to'];
        }
        $step->setMetadata($metadata === [] ? null : $metadata);
    }

    /**
     * @param array{title: string, description: string, entity_type: string, steps: list<array<string, mixed>>} $definition
     * @return list<string>
     */
    private function differences(Workflow $row, array $definition): array
    {
        $differences = [];
        if (!$row->isActive()) {
            $differences[] = 'inactive';
        }
        $steps = array_values($row->getSteps()->toArray());
        if (count($steps) !== count($definition['steps'])) {
            $differences[] = sprintf('steps: %d in the database, %d in the YAML', count($steps), count($definition['steps']));
        }
        foreach ($definition['steps'] as $index => $stepDefinition) {
            $step = $steps[$index] ?? null;
            if (!$step instanceof WorkflowStep) {
                continue;
            }
            $expected = self::expectedStep($stepDefinition, $index);
            $actual = [
                'name' => (string) $step->getName(),
                'role' => (string) $step->getApproverRole(),
                'type' => $step->getStepType(),
                'days' => $step->getDaysToComplete(),
                'required' => $step->isRequired(),
                'conditions' => $step->getMetadata()['autoProgressConditions'] ?? null,
                'loop_back_to' => ($step->getMetadata()['rejectAction'] ?? null) === 'loop_back'
                    ? (int) ($step->getMetadata()['rejectTargetStep'] ?? 0) : null,
            ];
            foreach ($actual as $field => $value) {
                if ($value !== $expected[$field]) {
                    $differences[] = sprintf('step %d %s: %s → %s', $index + 1, $field, self::show($value), self::show($expected[$field]));
                }
            }
        }

        return $differences;
    }

    /**
     * @param array<string, mixed> $definition
     * @return array{name: string, description: string, order: int, type: string, role: string, days: int|null,
     *               required: bool, conditions: array<string, mixed>|null, loop_back_to: int|null}
     */
    private static function expectedStep(array $definition, int $index): array
    {
        $conditions = $definition['auto_progress_conditions'] ?? null;

        return [
            'name' => (string) ($definition['name'] ?? ''),
            'description' => (string) ($definition['description'] ?? ''),
            'order' => (int) ($definition['order'] ?? $index + 1),
            'type' => (string) ($definition['step_type'] ?? 'approval'),
            'role' => (string) ($definition['approver_role'] ?? ''),
            'days' => isset($definition['days_to_complete']) ? (int) $definition['days_to_complete'] : null,
            'required' => (bool) ($definition['is_required'] ?? true),
            'conditions' => is_array($conditions) ? $conditions : null,
            'loop_back_to' => ($definition['reject_action'] ?? null) === 'loop_back' && isset($definition['reject_target_step'])
                ? (int) $definition['reject_target_step'] : null,
        ];
    }

    private function isInUse(WorkflowStep $step): bool
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(wi.id)')
            ->from(WorkflowInstance::class, 'wi')
            ->where('wi.currentStep = :step')
            ->setParameter('step', $step)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    private static function show(mixed $value): string
    {
        return match (true) {
            $value === null => '—',
            is_bool($value) => $value ? 'yes' : 'no',
            is_array($value) => (string) json_encode($value),
            default => (string) $value,
        };
    }
}
