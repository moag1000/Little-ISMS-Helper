<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\Tenant;
use App\Entity\User;
use App\Security\Voter\UserVoter;
use App\Service\InitialAdminService;
use App\Service\TenantContext;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * Security hotfix (admin scope): UserVoter VIEW/EDIT/DELETE must be limited
 * to the actor's accessible tenant tree (own + descendants) for every actor
 * that is not ROLE_SUPER_ADMIN, and a non-SUPER_ADMIN must never EDIT/DELETE
 * a ROLE_SUPER_ADMIN account.
 */
#[AllowMockObjectsWithoutExpectations]
final class UserVoterTenantScopeTest extends TestCase
{
    private Tenant $tenantA;
    private Tenant $tenantB;
    private Tenant $childOfA;

    protected function setUp(): void
    {
        $this->tenantA = new Tenant();
        $this->tenantA->setName('A');
        $this->tenantA->setCode('a');

        $this->tenantB = new Tenant();
        $this->tenantB->setName('B');
        $this->tenantB->setCode('b');

        $this->childOfA = new Tenant();
        $this->childOfA->setName('A-child');
        $this->childOfA->setCode('a-child');
        $this->childOfA->setParent($this->tenantA);
    }

    /**
     * Voter whose TenantContext reports $current as the actor's tenant and
     * uses the real canAccessTenant() tree semantics (own + descendants).
     */
    private function voterFor(?Tenant $current): UserVoter
    {
        $tenantContext = $this->createMock(TenantContext::class);
        $tenantContext->method('getCurrentTenant')->willReturn($current);
        $tenantContext->method('canAccessTenant')->willReturnCallback(
            static fn (Tenant $candidate): bool => $current instanceof Tenant
                && ($candidate === $current || $candidate->isChildOf($current)),
        );

        return new UserVoter(
            $this->createMock(InitialAdminService::class),
            VoterTestHelper::createRoleHierarchy(),
            $tenantContext,
        );
    }

    private function user(int $id, array $roles, ?Tenant $tenant, array $permissions = []): User
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn($id);
        $user->method('getRoles')->willReturn($roles);
        $user->method('isActive')->willReturn(true);
        $user->method('getTenant')->willReturn($tenant);
        $user->method('hasPermission')->willReturnCallback(
            static fn (string $perm): bool => in_array($perm, $permissions, true),
        );

        return $user;
    }

    private function vote(UserVoter $voter, User $actor, User $target, string $attribute): int
    {
        return $voter->vote(new UsernamePasswordToken($actor, 'main', $actor->getRoles()), $target, [$attribute]);
    }

    #[Test]
    public function adminCanManageUserOfOwnTenant(): void
    {
        $voter = $this->voterFor($this->tenantA);
        $admin = $this->user(1, ['ROLE_ADMIN'], $this->tenantA);
        $target = $this->user(2, ['ROLE_USER'], $this->tenantA);

        foreach ([UserVoter::VIEW, UserVoter::EDIT, UserVoter::DELETE] as $attribute) {
            self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($voter, $admin, $target, $attribute), $attribute);
        }
    }

    #[Test]
    public function adminCannotManageUserOfForeignTenant(): void
    {
        $voter = $this->voterFor($this->tenantA);
        $admin = $this->user(1, ['ROLE_ADMIN'], $this->tenantA);
        $target = $this->user(2, ['ROLE_USER'], $this->tenantB);

        foreach ([UserVoter::VIEW, UserVoter::EDIT, UserVoter::DELETE] as $attribute) {
            self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($voter, $admin, $target, $attribute), $attribute);
        }
    }

    #[Test]
    public function parentTenantAdminCanManageUserOfChildTenant(): void
    {
        $voter = $this->voterFor($this->tenantA);
        $admin = $this->user(1, ['ROLE_ADMIN'], $this->tenantA);
        $target = $this->user(2, ['ROLE_USER'], $this->childOfA);

        foreach ([UserVoter::VIEW, UserVoter::EDIT, UserVoter::DELETE] as $attribute) {
            self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($voter, $admin, $target, $attribute), $attribute);
        }
    }

    #[Test]
    public function childTenantAdminCannotManageUserOfParentTenant(): void
    {
        $voter = $this->voterFor($this->childOfA);
        $admin = $this->user(1, ['ROLE_ADMIN'], $this->childOfA);
        $target = $this->user(2, ['ROLE_USER'], $this->tenantA);

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($voter, $admin, $target, UserVoter::EDIT));
    }

    #[Test]
    public function adminCannotEditOrDeleteSuperAdminEvenInOwnTenant(): void
    {
        $voter = $this->voterFor($this->tenantA);
        $admin = $this->user(1, ['ROLE_ADMIN'], $this->tenantA);
        $super = $this->user(2, ['ROLE_SUPER_ADMIN'], $this->tenantA);

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($voter, $admin, $super, UserVoter::EDIT));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($voter, $admin, $super, UserVoter::DELETE));
        // Viewing a same-tenant SUPER_ADMIN stays allowed.
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($voter, $admin, $super, UserVoter::VIEW));
    }

    #[Test]
    public function usersWithoutTenantAreOnlyVisibleToSuperAdmin(): void
    {
        $tenantless = $this->user(2, ['ROLE_USER'], null);

        $admin = $this->user(1, ['ROLE_ADMIN'], $this->tenantA);
        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->vote($this->voterFor($this->tenantA), $admin, $tenantless, UserVoter::VIEW),
        );

        $super = $this->user(3, ['ROLE_SUPER_ADMIN'], $this->tenantA);
        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->vote($this->voterFor($this->tenantA), $super, $tenantless, UserVoter::EDIT),
        );
    }

    #[Test]
    public function superAdminCanManageAnyTenantIncludingOtherSuperAdmins(): void
    {
        $voter = $this->voterFor($this->tenantA);
        $super = $this->user(1, ['ROLE_SUPER_ADMIN'], $this->tenantA);

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->vote($voter, $super, $this->user(2, ['ROLE_USER'], $this->tenantB), UserVoter::EDIT),
        );
        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->vote($voter, $super, $this->user(3, ['ROLE_SUPER_ADMIN'], $this->tenantB), UserVoter::DELETE),
        );
    }

    #[Test]
    public function selfServiceStaysAllowedWithoutTenant(): void
    {
        // A tenantless admin can still view/edit its own account.
        $voter = $this->voterFor(null);
        $admin = $this->user(1, ['ROLE_ADMIN'], null);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($voter, $admin, $admin, UserVoter::VIEW));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($voter, $admin, $admin, UserVoter::EDIT));
    }

    #[Test]
    public function permissionHolderIsAlsoTenantScoped(): void
    {
        $voter = $this->voterFor($this->tenantA);
        $manager = $this->user(1, ['ROLE_USER'], $this->tenantA, ['user.view', 'user.edit']);

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->vote($voter, $manager, $this->user(2, ['ROLE_USER'], $this->tenantA), UserVoter::EDIT),
        );
        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->vote($voter, $manager, $this->user(3, ['ROLE_USER'], $this->tenantB), UserVoter::VIEW),
        );
    }
}
