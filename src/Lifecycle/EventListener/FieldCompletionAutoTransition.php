<?php

declare(strict_types=1);

namespace App\Lifecycle\EventListener;

use App\Lifecycle\LifecycleTransitionInterface;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\Workflow\Registry;

/**
 * Listens to entity postUpdate events. For each lifecycle-managed entity,
 * reads its `lifecycle.auto_transition_rules` config (entity-class-keyed map)
 * and auto-transitions when all listed required fields are now non-empty.
 *
 * Per-entity config lives in `config/packages/lifecycle.yaml`:
 *
 *     parameters:
 *       lifecycle.auto_transition_rules:
 *         App\Entity\DataBreach:
 *           assess_when_complete:
 *             workflow: data_breach_lifecycle
 *             transition: assess
 *             required_fields: [severity, affectedDataSubjectsCount, dataCategories]
 *
 * Rules that reference workflows not yet registered are silently skipped —
 * the Registry::get() call is wrapped so a missing workflow name does not
 * break the originating write.
 *
 * Auto-transitions fire best-effort: any exception during transition is caught
 * and suppressed. The original postUpdate path is never aborted.
 */
#[AsDoctrineListener(event: Events::postUpdate)]
final class FieldCompletionAutoTransition
{
    /**
     * @param array<class-string, array<string, array{workflow: string, transition: string, required_fields: string[]}>> $rules
     */
    public function __construct(
        private readonly Registry $workflowRegistry,
        private readonly LifecycleTransitionInterface $lifecycleService,
        private readonly array $rules = [],
    ) {}

    public function postUpdate(PostUpdateEventArgs $event): void
    {
        $entity = $event->getObject();
        $entityClass = $entity::class;

        if (!isset($this->rules[$entityClass])) {
            return;
        }

        foreach ($this->rules[$entityClass] as $ruleName => $rule) {
            if (!$this->allFieldsCompleted($entity, $rule['required_fields'])) {
                continue;
            }

            try {
                $workflow = $this->workflowRegistry->get($entity, $rule['workflow']);
            } catch (\Throwable) {
                // Workflow not registered yet — skip gracefully
                continue;
            }

            if (!$workflow->can($entity, $rule['transition'])) {
                continue;
            }

            try {
                $this->lifecycleService->transition(
                    $entity,
                    $rule['workflow'],
                    $rule['transition'],
                    null,
                    'Auto-transition: ' . $ruleName,
                );
            } catch (\Throwable) {
                // Auto-transition is best-effort; never break the original write
            }
        }
    }

    /**
     * @param string[] $fields
     */
    private function allFieldsCompleted(object $entity, array $fields): bool
    {
        foreach ($fields as $field) {
            $getter = 'get' . ucfirst($field);

            if (!method_exists($entity, $getter)) {
                return false;
            }

            $value = $entity->{$getter}();

            if ($value === null || $value === '' || (is_array($value) && $value === [])) {
                return false;
            }
        }

        return true;
    }
}
