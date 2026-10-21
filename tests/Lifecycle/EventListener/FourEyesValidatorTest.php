<?php

declare(strict_types=1);

namespace App\Tests\Lifecycle\EventListener;

use App\Entity\Asset;
use App\Entity\Tenant;
use App\Entity\User;
use App\Lifecycle\Config\LifecycleConfigResolverInterface;
use App\Lifecycle\EventListener\FourEyesValidator;
use App\Lifecycle\Exception\FourEyesRequiredException;
use App\Lifecycle\EligibleApprovers;
use App\Repository\UserRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Role\RoleHierarchy;
use Symfony\Component\Workflow\Event\TransitionEvent;
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Workflow\Marking;
use Symfony\Component\Workflow\Transition;
use Symfony\Component\Workflow\WorkflowInterface;
use PHPUnit\Framework\Attributes\Test;

class FourEyesValidatorTest extends TestCase
{
    #[Test]
    public function testPassesWhenFourEyesNotRequired(): void
    {
        $validator = $this->makeValidator(metadata: ['four_eyes' => false]);
        $validator->onTransition($this->makeEvent(context: []));
        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function testThrowsWhenApproverMissing(): void
    {
        $this->expectException(FourEyesRequiredException::class);
        $validator = $this->makeValidator(
            metadata: ['four_eyes' => true, 'roles' => ['ROLE_MANAGER']],
            tenantUserCount: 5,
        );
        $validator->onTransition($this->makeEvent(context: ['user' => $this->makeUser(1, ['ROLE_MANAGER'])]));
    }

    #[Test]
    public function testThrowsWhenApproverIsSameUserAndTenantHasMultipleApprovers(): void
    {
        $this->expectException(FourEyesRequiredException::class);
        $u = $this->makeUser(1, ['ROLE_MANAGER']);
        $validator = $this->makeValidator(
            metadata: ['four_eyes' => true, 'roles' => ['ROLE_MANAGER']],
            tenantUserCount: 5,
        );
        $validator->onTransition($this->makeEvent(context: [
            'user' => $u,
            'four_eyes_approver' => $u,
        ]));
    }

    #[Test]
    public function testThrowsWhenApproverLacksRequiredRole(): void
    {
        $this->expectException(FourEyesRequiredException::class);
        $validator = $this->makeValidator(
            metadata: ['four_eyes' => true, 'roles' => ['ROLE_CISO']],
            tenantUserCount: 5,
        );
        $validator->onTransition($this->makeEvent(context: [
            'user' => $this->makeUser(1, ['ROLE_MANAGER']),
            'four_eyes_approver' => $this->makeUser(2, ['ROLE_USER']), // wrong role
        ]));
    }

    #[Test]
    public function testPassesWhenApproverIsDifferentAndHasRequiredRole(): void
    {
        $validator = $this->makeValidator(
            metadata: ['four_eyes' => true, 'roles' => ['ROLE_MANAGER']],
            tenantUserCount: 5,
        );
        $validator->onTransition($this->makeEvent(context: [
            'user' => $this->makeUser(1, ['ROLE_MANAGER']),
            'four_eyes_approver' => $this->makeUser(2, ['ROLE_MANAGER']),
        ]));
        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function testPassesWhenApproverIsSuperAdminEvenIfNotInRequiredRoles(): void
    {
        $validator = $this->makeValidator(
            metadata: ['four_eyes' => true, 'roles' => ['ROLE_DPO']],
            tenantUserCount: 5,
        );
        $validator->onTransition($this->makeEvent(context: [
            'user' => $this->makeUser(1, ['ROLE_DPO']),
            'four_eyes_approver' => $this->makeUser(2, ['ROLE_SUPER_ADMIN']),
        ]));
        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function testSingleUserTenantPermitsSelfApprovalWithReason(): void
    {
        $u = $this->makeUser(1, ['ROLE_MANAGER']);
        $validator = $this->makeValidator(
            metadata: ['four_eyes' => true, 'roles' => ['ROLE_MANAGER']],
            tenantUserCount: 1, // only this user holds ROLE_MANAGER
        );
        $validator->onTransition($this->makeEvent(context: [
            'user' => $u,
            'four_eyes_approver' => $u, // self
            'reason' => 'Offline-Gegenzeichnung durch externen Berater per E-Mail vom 12.05.2026',
        ]));
        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function testSingleUserTenantStillRequiresReason(): void
    {
        $this->expectException(FourEyesRequiredException::class);
        $u = $this->makeUser(1, ['ROLE_MANAGER']);
        $validator = $this->makeValidator(
            metadata: ['four_eyes' => true, 'roles' => ['ROLE_MANAGER']],
            tenantUserCount: 1,
        );
        $validator->onTransition($this->makeEvent(context: [
            'user' => $u,
            'four_eyes_approver' => $u,
            // no reason supplied
        ]));
    }

    #[Test]
    public function testSingleUserTenantWithApproverOmittedPermitsTransitionWithReason(): void
    {
        $u = $this->makeUser(1, ['ROLE_MANAGER']);
        $validator = $this->makeValidator(
            metadata: ['four_eyes' => true, 'roles' => ['ROLE_MANAGER']],
            tenantUserCount: 1,
        );
        $validator->onTransition($this->makeEvent(context: [
            'user' => $u,
            // no four_eyes_approver — single-user escape still applies
            'reason' => 'self-approved by sole tenant user, offline counter-sign on file',
        ]));
        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function personaRoleInheritingManagerSatisfiesManagerRequirement(): void
    {
        // ROLE_DPO inherits ROLE_MANAGER via security.yaml role_hierarchy.
        $validator = $this->makeValidator(
            metadata: ['four_eyes' => true, 'roles' => ['ROLE_MANAGER', 'ROLE_CISO']],
        );
        $validator->onTransition($this->makeEvent(context: [
            'user' => $this->makeUser(1, ['ROLE_MANAGER']),
            'four_eyes_approver' => $this->makeUser(2, ['ROLE_DPO']),
        ]));
        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function adminSatisfiesManagerRequirementThroughHierarchy(): void
    {
        $validator = $this->makeValidator(
            metadata: ['four_eyes' => true, 'roles' => ['ROLE_MANAGER', 'ROLE_CISO']],
        );
        $validator->onTransition($this->makeEvent(context: [
            'user' => $this->makeUser(1, ['ROLE_MANAGER']),
            'four_eyes_approver' => $this->makeUser(2, ['ROLE_ADMIN']),
        ]));
        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function stepAuthorisedApproverIsNotRoleCheckedAgainstTransitionRoles(): void
    {
        // The workflow-instance path already verified the approver against the
        // step definition (approver_role / approver_users). The transition's
        // roles list must not veto such an approver.
        $validator = $this->makeValidator(
            metadata: ['four_eyes' => true, 'roles' => ['ROLE_MANAGER', 'ROLE_CISO']],
        );
        $validator->onTransition($this->makeEvent(context: [
            'user' => $this->makeUser(1, ['ROLE_MANAGER']),
            'four_eyes_approver' => $this->makeUser(2, ['ROLE_AUDITOR']),
            'four_eyes_approver_authorized' => true,
        ]));
        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function auditorWithoutStepAuthorityStillLacksRole(): void
    {
        $validator = $this->makeValidator(
            metadata: ['four_eyes' => true, 'roles' => ['ROLE_MANAGER', 'ROLE_CISO']],
        );
        try {
            $validator->onTransition($this->makeEvent(context: [
                'user' => $this->makeUser(1, ['ROLE_MANAGER']),
                'four_eyes_approver' => $this->makeUser(2, ['ROLE_AUDITOR']),
            ]));
            self::fail('Expected FourEyesRequiredException');
        } catch (FourEyesRequiredException $e) {
            self::assertSame('approver_lacks_role', $e->reasonCode);
        }
    }

    #[Test]
    public function stepAuthorityDoesNotWaiveTheDistinctApproverRule(): void
    {
        $u = $this->makeUser(1, ['ROLE_MANAGER']);
        $validator = $this->makeValidator(
            metadata: ['four_eyes' => true, 'roles' => ['ROLE_MANAGER']],
            tenantUserCount: 3,
        );
        try {
            $validator->onTransition($this->makeEvent(context: [
                'user' => $u,
                'four_eyes_approver' => $u,
                'four_eyes_approver_authorized' => true,
                'reason' => 'ok',
            ]));
            self::fail('Expected FourEyesRequiredException');
        } catch (FourEyesRequiredException $e) {
            self::assertSame('same_user', $e->reasonCode);
        }
    }

    #[Test]
    public function singleApproverEscapeCountsUsersThatReachTheRoleThroughHierarchy(): void
    {
        // The tenant has the initiating MANAGER plus a DPO (who inherits
        // ROLE_MANAGER). There IS a second eligible approver, so the
        // single-user escape must not apply to a self-approval.
        $initiator = $this->makeUser(1, ['ROLE_MANAGER']);
        $dpo = $this->makeUser(2, ['ROLE_DPO']);

        $resolver = $this->createStub(LifecycleConfigResolverInterface::class);
        $resolver->method('resolve')->willReturn(['four_eyes' => true, 'roles' => ['ROLE_MANAGER']]);
        // The count matches stored role names literally — the DPO is only
        // found because the validator expands ROLE_MANAGER to every role
        // reaching it.
        $userRepo = $this->userRepoOver([$initiator, $dpo]);
        $validator = new FourEyesValidator($resolver, $userRepo, $this->roleHierarchy());

        try {
            $validator->onTransition($this->makeEvent(context: [
                'user' => $initiator,
                'four_eyes_approver' => $initiator,
                'reason' => 'self-approval attempt',
            ]));
            self::fail('Expected FourEyesRequiredException');
        } catch (FourEyesRequiredException $e) {
            self::assertSame('same_user', $e->reasonCode);
        }
    }

    /**
     * The escape used to count users reaching the transition roles
     * (ROLE_MANAGER / ROLE_CISO). A step-eligible second approver without
     * those roles (an explicit approver_users entry) was ignored, so the
     * initiator could self-approve although someone else could have.
     */
    #[Test]
    public function stepEligibleSecondApproverBlocksTheSelfApprovalEscape(): void
    {
        $initiator = $this->makeUser(1, ['ROLE_MANAGER']);
        $namedApprover = $this->makeUser(2, ['ROLE_USER']); // in the step's approver_users
        $validator = $this->validatorWithTenantUsers([$initiator, $namedApprover]);

        try {
            $validator->onTransition($this->makeEvent(context: [
                'user' => $initiator,
                'four_eyes_approver' => $initiator,
                'four_eyes_approver_authorized' => true,
                'four_eyes_eligible_approvers' => new EligibleApprovers(['ROLE_ADMIN'], [2]),
                'reason' => 'self-approval attempt',
            ]));
            self::fail('Expected FourEyesRequiredException');
        } catch (FourEyesRequiredException $e) {
            self::assertSame('same_user', $e->reasonCode);
        }
    }

    #[Test]
    public function escapeAppliesWhenNoOtherUserIsEligibleForTheStep(): void
    {
        $initiator = $this->makeUser(1, ['ROLE_DPO']);
        $otherManager = $this->makeUser(2, ['ROLE_MANAGER']); // reaches the transition role, but not the step
        $validator = $this->validatorWithTenantUsers([$initiator, $otherManager]);

        $validator->onTransition($this->makeEvent(context: [
            'user' => $initiator,
            'four_eyes_approver' => $initiator,
            'four_eyes_approver_authorized' => true,
            'four_eyes_eligible_approvers' => new EligibleApprovers(['ROLE_DPO']),
            'reason' => 'Offline counter-signature by the managing director, mail of 12 May',
        ]));
        $this->expectNotToPerformAssertions();
    }

    /**
     * Callers with only a system-generated reason (policy-section host
     * release) disable the escape: a fixed text is no documented offline
     * counter-signature.
     */
    #[Test]
    public function escapeIsRefusedWhenTheCallerDisablesIt(): void
    {
        $u = $this->makeUser(1, ['ROLE_MANAGER']);
        $validator = $this->makeValidator(
            metadata: ['four_eyes' => true, 'roles' => ['ROLE_MANAGER']],
            tenantUserCount: 1,
        );

        try {
            $validator->onTransition($this->makeEvent(context: [
                'user' => $u,
                'four_eyes_approver' => $u,
                'reason' => 'All gated policy sections approved',
                'four_eyes_self_approval_escape' => false,
            ]));
            self::fail('Expected FourEyesRequiredException');
        } catch (FourEyesRequiredException $e) {
            self::assertSame('same_user', $e->reasonCode);
        }
    }

    /**
     * Automatic actors (cron, field-completion listener, auto-progression)
     * never satisfy four-eyes — not even with a distinct, authorised
     * approver in the context.
     */
    #[Test]
    public function automaticActorIsRefusedEvenWithADistinctAuthorisedApprover(): void
    {
        $initiator = $this->makeUser(1, ['ROLE_MANAGER']);
        $approver = $this->makeUser(2, ['ROLE_MANAGER']);
        $validator = $this->makeValidator(['four_eyes' => true, 'roles' => ['ROLE_MANAGER']]);

        try {
            $validator->onTransition($this->makeEvent(context: [
                'user' => $initiator,
                'four_eyes_approver' => $approver,
                'four_eyes_approver_authorized' => true,
                'four_eyes_automatic' => true,
            ]));
            self::fail('Expected FourEyesRequiredException');
        } catch (FourEyesRequiredException $e) {
            self::assertSame('automatic_actor', $e->reasonCode);
        }
    }

    /**
     * The single-approver escape (one eligible user + a reason) is never
     * available to an automatic actor: its fixed reason is no offline
     * counter-signature.
     */
    #[Test]
    public function automaticActorNeverUsesTheSingleApproverEscape(): void
    {
        $u = $this->makeUser(1, ['ROLE_MANAGER']);
        $validator = $this->makeValidator(
            metadata: ['four_eyes' => true, 'roles' => ['ROLE_MANAGER']],
            tenantUserCount: 1,
        );

        try {
            $validator->onTransition($this->makeEvent(context: [
                'user' => $u,
                'four_eyes_approver' => $u,
                'reason' => 'Step automatically approved after time delay of 24 hours',
                'four_eyes_automatic' => true,
            ]));
            self::fail('Expected FourEyesRequiredException');
        } catch (FourEyesRequiredException $e) {
            self::assertSame('automatic_actor', $e->reasonCode);
        }
    }

    #[Test]
    public function automaticActorPassesWhenFourEyesIsNotRequired(): void
    {
        $validator = $this->makeValidator(['four_eyes' => false]);

        $validator->onTransition($this->makeEvent(context: ['user' => null, 'four_eyes_automatic' => true]));
        $this->expectNotToPerformAssertions();
    }

    /**
     * @param list<User> $users active users of the tenant
     */
    private function validatorWithTenantUsers(array $users): FourEyesValidator
    {
        $resolver = $this->createStub(LifecycleConfigResolverInterface::class);
        $resolver->method('resolve')->willReturn(['four_eyes' => true, 'roles' => ['ROLE_MANAGER', 'ROLE_CISO']]);
        return new FourEyesValidator($resolver, $this->userRepoOver($users), $this->roleHierarchy());
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function makeValidator(array $metadata, int $tenantUserCount = 5): FourEyesValidator
    {
        $resolver = $this->createStub(LifecycleConfigResolverInterface::class);
        $resolver->method('resolve')->willReturn($metadata);

        // The active users of the tenant.
        // tenantUserCount=1 → exactly the initiator. >1 → initiator + N stand-ins.
        $users = [];
        for ($i = 1; $i <= $tenantUserCount; $i++) {
            $users[] = $this->makeUser($i, $metadata['roles'] ?? ['ROLE_MANAGER']);
        }

        return new FourEyesValidator($resolver, $this->userRepoOver($users), $this->roleHierarchy());
    }

    /**
     * UserRepository stub whose count queries evaluate the same predicate as
     * the real DQL over an in-memory list of active tenant users: stored
     * role names matched literally, ids, excluded id. findBy() fails the test
     * — the escape must not load the organisation's users.
     *
     * @param list<User> $users
     */
    private function userRepoOver(array $users): UserRepository
    {
        $userRepo = $this->createStub(UserRepository::class);
        $userRepo->method('countActiveByTenant')->willReturn(count($users));
        $userRepo->method('countActiveInTenantHoldingAny')->willReturnCallback(
            static function (Tenant $tenant, array $roleNames, array $userIds = [], ?int $excludeUserId = null) use ($users): int {
                $n = 0;
                foreach ($users as $u) {
                    if ($u->getId() === $excludeUserId) {
                        continue;
                    }
                    if (in_array($u->getId(), $userIds, true) || array_intersect($u->getStoredRoles(), $roleNames) !== []) {
                        ++$n;
                    }
                }

                return $n;
            },
        );
        $userRepo->method('findBy')->willReturnCallback(static function (): never {
            self::fail('The single-approver escape must count, not load the users.');
        });

        return $userRepo;
    }

    /** Real role hierarchy from config/packages/security.yaml. */
    private function roleHierarchy(): RoleHierarchy
    {
        $config = Yaml::parseFile(\dirname(__DIR__, 3) . '/config/packages/security.yaml');

        return new RoleHierarchy(array_map(
            static fn (string|array $reachable): array => (array) $reachable,
            $config['security']['role_hierarchy'],
        ));
    }

    /**
     * @param array<int, string> $roles
     */
    private function makeUser(int $id, array $roles = []): User
    {
        $u = new User();
        // Force the id via reflection (entity uses auto-increment).
        $ref = new \ReflectionProperty(User::class, 'id');
        $ref->setValue($u, $id);
        // User entity exposes setRoles(array) — no addRole() helper.
        $u->setRoles($roles);
        $tenant = new Tenant();
        $tenantRef = new \ReflectionProperty(Tenant::class, 'id');
        $tenantRef->setValue($tenant, 1);
        $u->setTenant($tenant);
        return $u;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function makeEvent(array $context): TransitionEvent
    {
        $asset = new Asset();
        return new TransitionEvent(
            $asset,
            new Marking(['retired' => 1]),
            new Transition('dispose', ['retired'], ['disposed']),
            $this->createStub(WorkflowInterface::class),
            $context,
        );
    }
}
