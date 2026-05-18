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
use Symfony\Component\Workflow\Event\TransitionEvent;
use Symfony\Component\Workflow\Marking;
use Symfony\Component\Workflow\Transition;
use Symfony\Component\Workflow\WorkflowInterface;

class FourEyesValidatorTest extends TestCase
{
    public function testPassesWhenFourEyesNotRequired(): void
    {
        $validator = $this->makeValidator(metadata: ['four_eyes' => false]);
        $validator->onTransition($this->makeEvent(context: []));
        $this->expectNotToPerformAssertions();
    }

    public function testThrowsWhenApproverMissing(): void
    {
        $this->expectException(FourEyesRequiredException::class);
        $validator = $this->makeValidator(
            metadata: ['four_eyes' => true, 'roles' => ['ROLE_MANAGER']],
            tenantUserCount: 5,
        );
        $validator->onTransition($this->makeEvent(context: ['user' => $this->makeUser(1, ['ROLE_MANAGER'])]));
    }

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

        return new FourEyesValidator($resolver, $userRepo);
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
        foreach ($roles as $role) {
            $u->addRole($role);
        }
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
