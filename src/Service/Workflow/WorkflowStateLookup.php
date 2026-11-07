<?php

declare(strict_types=1);

namespace App\Service\Workflow;

use App\Entity\WorkflowInstance;
use App\Repository\WorkflowInstanceRepository;
use Doctrine\Persistence\Proxy;

/**
 * The running approval of a record, in the shape the status band and the
 * list-row workflow pill render: id, title, status, due_date, stuck, step_name.
 *
 * Lists ask for a whole page at once ({@see forMany()}), so a list costs one
 * query instead of one per row.
 */
final readonly class WorkflowStateLookup
{
    public function __construct(
        private WorkflowInstanceRepository $workflowInstanceRepository,
    ) {
    }

    /**
     * @param iterable<object> $entities records of one type (e.g. a ListPage)
     *
     * @return array<int, array<string, mixed>> entity id => state; records without a running approval are absent
     */
    public function forMany(iterable $entities): array
    {
        $ids = [];
        $class = null;
        foreach ($entities as $entity) {
            if (!method_exists($entity, 'getId') || $entity->getId() === null) {
                continue;
            }
            $class ??= self::classOf($entity);
            $ids[] = (int) $entity->getId();
        }
        if ($class === null) {
            return [];
        }

        $type = (new \ReflectionClass($class))->getShortName();
        $now = new \DateTimeImmutable();
        $out = [];
        foreach ($this->workflowInstanceRepository->findActiveForEntities([$type, $class], $ids) as $instance) {
            $out[$instance->getEntityId()] ??= self::state($instance, $type, $now);
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public function for(object $entity): array
    {
        if (!method_exists($entity, 'getId') || $entity->getId() === null) {
            return [];
        }

        return $this->forMany([$entity])[(int) $entity->getId()] ?? [];
    }

    /**
     * Real entity class, also for a Doctrine proxy. Instances store its short
     * name ('Risk', 'DataBreach'); a few older rows carry the FQCN.
     */
    private static function classOf(object $entity): string
    {
        return $entity instanceof Proxy ? (string) get_parent_class($entity) : $entity::class;
    }

    /** @return array<string, mixed> */
    private static function state(WorkflowInstance $instance, string $type, \DateTimeImmutable $now): array
    {
        $dueDate = $instance->getDueDate();

        return [
            'id' => $instance->getId(),
            'title' => $instance->getWorkflow()?->getName() ?? $type . ' Workflow',
            'status' => $instance->getStatus(),
            'due_date' => $dueDate,
            'stuck' => $dueDate !== null && $dueDate < $now,
            'step_name' => $instance->getCurrentStep()?->getName(),
        ];
    }
}
