<?php

declare(strict_types=1);

namespace App\Tests\Lifecycle\EventListener;

use App\Entity\Asset;
use App\Entity\Tenant;
use App\Entity\User;
use App\Lifecycle\Config\LifecycleConfigResolverInterface;
use App\Lifecycle\EventListener\FourEyesValidator;
use App\Lifecycle\Exception\FourEyesRequiredException;
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
        $userRepo = $this->createStub(UserRepository::class);
        // Raw role LIKE-query only finds the literal ROLE_MANAGER holder.
        $userRepo->method('findByRoleInTenant')->willReturnCallback(
            static fn (string $role): array => $role === 'ROLE_MANAGER' ? [$initiator] : [],
        );
        $userRepo->method('findBy')->willReturn([$initiator, $dpo]);
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
     * @param array<string, mixed> $metadata
     */
    private function makeValidator(array $metadata, int $tenantUserCount = 5): FourEyesValidator
    {
        $resolver = $this->createStub(LifecycleConfigResolverInterface::class);
        $resolver->method('resolve')->willReturn($metadata);

        $userRepo = $this->createStub(UserRepository::class);
        // Build the user list that findByRoleInTenant returns.
        // tenantUserCount=1 → exactly the initiator. >1 → initiator + N stand-ins.
        $users = [];
        for ($i = 1; $i <= $tenantUserCount; $i++) {
            $users[] = $this->makeUser($i, $metadata['roles'] ?? ['ROLE_MANAGER']);
        }
        $userRepo->method('findByRoleInTenant')->willReturn($users);
        $userRepo->method('findBy')->willReturn($users);

        return new FourEyesValidator($resolver, $userRepo, $this->roleHierarchy());
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
