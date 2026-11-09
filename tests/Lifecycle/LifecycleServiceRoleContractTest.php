<?php

declare(strict_types=1);

namespace App\Tests\Lifecycle;

use App\Entity\User;
use App\Lifecycle\Config\LifecycleConfigResolverInterface;
use App\Lifecycle\Exception\TransitionAccessDeniedException;
use App\Lifecycle\LifecycleService;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * S21 durable contract: the role check of a lifecycle step lives in
 * LifecycleService::transition(), so no caller — domain controller, REST, MCP,
 * job, suggestion apply() — can open a step its user may not take.
 *
 * Discovers every workflow YAML under config/workflows/ itself (a new workflow
 * is covered without touching this test) and, for every transition with
 * effective roles that a plain ROLE_USER does not reach:
 *  - a ROLE_USER who is not responsible for the record is refused with
 *    TransitionAccessDeniedException before anything is applied;
 *  - a holder of one of the roles passes the role check (whatever the
 *    state machine says afterwards);
 *  - an automatic call (listener, cron) is not role-checked.
 *
 * Mutation check: drop the `$this->access->assertAllowed(...)` call in
 * LifecycleService, or make TransitionAccess return early → this test fails.
 */
final class LifecycleServiceRoleContractTest extends KernelTestCase
{
    #[Test]
    public function aUserWithoutTheRolesIsRefusedOnEveryRoleGatedTransition(): void
    {
        self::bootKernel();
        $service = self::getContainer()->get(LifecycleService::class);
        $checked = 0;

        foreach ($this->roleGatedTransitions() as [$class, $workflow, $transition, $roles]) {
            $entity = $this->newSubject($class);
            $before = $this->statusOf($entity);
            try {
                $service->transition($entity, $workflow, $transition, $this->user(['ROLE_USER']));
                self::fail(sprintf('%s.%s ran for a plain ROLE_USER (roles %s).', $workflow, $transition, implode(', ', $roles)));
            } catch (TransitionAccessDeniedException $e) {
                self::assertSame($workflow, $e->workflowName);
                self::assertSame($transition, $e->transitionName);
            }
            self::assertSame($before, $this->statusOf($entity), $workflow . '.' . $transition . ' changed the status although refused');
            ++$checked;
        }

        self::assertGreaterThan(100, $checked, 'Fewer role-gated transitions found than expected — discovery broken?');
    }

    #[Test]
    public function aHolderOfTheRolesPassesTheRoleCheck(): void
    {
        self::bootKernel();
        $service = self::getContainer()->get(LifecycleService::class);

        foreach ($this->roleGatedTransitions() as [$class, $workflow, $transition, $roles]) {
            $entity = $this->newSubject($class);
            try {
                $service->transition($entity, $workflow, $transition, $this->user([$roles[0]]));
            } catch (TransitionAccessDeniedException) {
                self::fail(sprintf('%s.%s refused a holder of %s.', $workflow, $transition, $roles[0]));
            } catch (\Throwable) {
                // State machine, four eyes, reason, module or tenant guard may refuse
                // afterwards — only the role check is under test here.
            }
        }
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function automaticCallsAreNotRoleChecked(): void
    {
        self::bootKernel();
        $service = self::getContainer()->get(LifecycleService::class);

        foreach ($this->roleGatedTransitions() as [$class, $workflow, $transition]) {
            try {
                $service->transition($this->newSubject($class), $workflow, $transition, $this->user(['ROLE_USER']), automatic: true);
            } catch (TransitionAccessDeniedException) {
                self::fail(sprintf('%s.%s role-checked an automatic call.', $workflow, $transition));
            } catch (\Throwable) {
                // see above
            }
        }
        $this->addToAssertionCount(1);
    }

    /**
     * @return iterable<array{0: class-string, 1: string, 2: string, 3: list<string>}>
     */
    private function roleGatedTransitions(): iterable
    {
        $resolver = self::getContainer()->get(LifecycleConfigResolverInterface::class);
        $hierarchy = self::getContainer()->get(RoleHierarchyInterface::class);
        $plainUser = $hierarchy->getReachableRoleNames(['ROLE_USER']);

        foreach (glob(\dirname(__DIR__, 2) . '/config/workflows/*.yaml') ?: [] as $file) {
            $workflows = Yaml::parseFile($file)['framework']['workflows'] ?? [];
            foreach ($workflows as $workflow => $definition) {
                $class = $definition['supports'][0] ?? null;
                if (!is_string($class) || !class_exists($class)) {
                    continue;
                }
                foreach (array_keys($definition['transitions'] ?? []) as $transition) {
                    $effective = $resolver->resolve($this->newSubject($class), $workflow, (string) $transition);
                    $roles = array_values(array_filter((array) ($effective['roles'] ?? []), 'is_string'));
                    if ($roles === [] || array_intersect($roles, $plainUser) !== []) {
                        continue; // service-only step, or open to every signed-in user
                    }
                    yield [$class, (string) $workflow, (string) $transition, $roles];
                }
            }
        }
    }

    private function newSubject(string $class): object
    {
        $reflection = new \ReflectionClass($class);
        $constructor = $reflection->getConstructor();

        return $constructor === null || $constructor->getNumberOfRequiredParameters() === 0
            ? $reflection->newInstance()
            : $reflection->newInstanceWithoutConstructor();
    }

    private function statusOf(object $entity): mixed
    {
        try {
            $status = $entity->getStatus();
        } catch (\Error) {
            return null; // typed property not initialised (no constructor run)
        }

        return $status instanceof \BackedEnum ? $status->value : $status;
    }

    /** @param list<string> $roles */
    private function user(array $roles): User
    {
        $user = new User();
        $user->setEmail('s21-' . strtolower(implode('-', $roles)) . '@example.test');
        $user->setRoles($roles);
        $user->setIsActive(true);

        return $user;
    }
}
