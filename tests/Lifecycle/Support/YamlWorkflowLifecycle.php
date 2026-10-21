<?php

declare(strict_types=1);

namespace App\Tests\Lifecycle\Support;

use App\Entity\User;
use App\Lifecycle\Exception\ReasonRequiredException;
use App\Lifecycle\InvalidTransitionException;
use App\Lifecycle\LifecycleTransitionInterface;
use Symfony\Component\Workflow\DefinitionBuilder;
use Symfony\Component\Workflow\Exception\LogicException as WorkflowLogicException;
use Symfony\Component\Workflow\MarkingStore\MethodMarkingStore;
use Symfony\Component\Workflow\StateMachine;
use Symfony\Component\Workflow\Transition;
use Symfony\Component\Yaml\Yaml;

/**
 * Unit-test double for {@see LifecycleTransitionInterface} that applies the
 * REAL state machine defined in config/workflows/*.yaml (places, from/to,
 * reason_required) without a kernel. Unit tests of services that route
 * status changes through the lifecycle facade use it so an illegal
 * transition fails the test the same way it fails in production.
 *
 * Records every applied transition in {@see $applied} for assertions.
 * Guards that need the container (tenant, module, four-eyes) are not
 * evaluated here — those have their own tests under tests/Lifecycle/.
 */
final class YamlWorkflowLifecycle implements LifecycleTransitionInterface
{
    /** @var list<array{workflow: string, transition: string, user: ?User, reason: ?string, approver: ?User, approver_authorized: bool}> */
    public array $applied = [];

    /** @var array<string, array{machine: StateMachine, reasonRequired: array<string, bool>}> */
    private array $machines = [];

    public function transition(
        object $entity,
        string $workflowName,
        string $transitionName,
        ?User $user = null,
        ?string $reason = null,
        ?User $fourEyesApprover = null,
        bool $approverAuthorityVerified = false,
        ?\App\Lifecycle\EligibleApprovers $eligibleApprovers = null,
        bool $allowSelfApprovalEscape = true,
        bool $automatic = false,
    ): void {
        $workflow = $this->machine($workflowName);
        $from = (string) $entity->getStatus();

        if (($workflow['reasonRequired'][$transitionName] ?? false) && trim((string) $reason) === '') {
            throw new ReasonRequiredException($workflowName, $transitionName);
        }

        try {
            $workflow['machine']->apply($entity, $transitionName, ['reason' => $reason]);
        } catch (WorkflowLogicException $e) {
            throw new InvalidTransitionException(
                message: $e->getMessage(),
                entityClass: $entity::class,
                fromStatus: $from,
                toStatus: '<unknown>',
                previous: $e,
            );
        }

        $this->applied[] = [
            'workflow' => $workflowName,
            'transition' => $transitionName,
            'user' => $user,
            'reason' => $reason,
            'approver' => $fourEyesApprover,
            'approver_authorized' => $approverAuthorityVerified,
        ];
    }

    /** @return list<string> transition names applied so far */
    public function appliedTransitions(): array
    {
        return array_map(static fn (array $a): string => $a['transition'], $this->applied);
    }

    /** @return array{machine: StateMachine, reasonRequired: array<string, bool>} */
    private function machine(string $workflowName): array
    {
        if (isset($this->machines[$workflowName])) {
            return $this->machines[$workflowName];
        }

        $config = null;
        foreach (glob(\dirname(__DIR__, 3) . '/config/workflows/*.yaml') ?: [] as $file) {
            $parsed = Yaml::parseFile($file);
            if (isset($parsed['framework']['workflows'][$workflowName])) {
                $config = $parsed['framework']['workflows'][$workflowName];
                break;
            }
        }
        if ($config === null) {
            throw new \LogicException(sprintf('Workflow "%s" not found in config/workflows.', $workflowName));
        }

        $builder = new DefinitionBuilder($config['places']);
        $builder->setInitialPlaces([$config['initial_marking']]);
        $reasonRequired = [];
        foreach ($config['transitions'] as $name => $t) {
            foreach ((array) $t['from'] as $from) {
                $builder->addTransition(new Transition($name, $from, $t['to']));
            }
            $reasonRequired[$name] = (bool) ($t['metadata']['reason_required'] ?? false);
        }

        return $this->machines[$workflowName] = [
            'machine' => new StateMachine(
                $builder->build(),
                new MethodMarkingStore(true, $config['marking_store']['property'] ?? 'status'),
                null,
                $workflowName,
            ),
            'reasonRequired' => $reasonRequired,
        ];
    }
}
