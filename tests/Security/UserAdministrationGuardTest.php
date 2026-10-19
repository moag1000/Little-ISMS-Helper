<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Role;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\TenantRepository;
use App\Security\UserAdministrationGuard;
use App\Service\TenantContext;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;

#[AllowMockObjectsWithoutExpectations]
final class UserAdministrationGuardTest extends TestCase
{
    private Tenant $own;
    private Tenant $foreign;

    protected function setUp(): void
    {
        $this->own = (new Tenant())->setName('own')->setCode('own');
        $this->foreign = (new Tenant())->setName('foreign')->setCode('foreign');
    }

    private function guard(bool $superAdmin): UserAdministrationGuard
    {
        $security = $this->createMock(Security::class);
        $security->method('isGranted')->willReturnCallback(
            static fn (string $attr): bool => $attr === 'ROLE_SUPER_ADMIN' ? $superAdmin : $attr === 'ROLE_ADMIN',
        );
        $tenantContext = $this->createMock(TenantContext::class);
        $tenantContext->method('getCurrentTenant')->willReturn($this->own);
        $tenantContext->method('canAccessTenant')->willReturnCallback(fn (Tenant $t): bool => $t === $this->own);

        return new UserAdministrationGuard(
            $security,
            $tenantContext,
            $this->createMock(TenantRepository::class),
            new NullLogger(),
        );
    }

    #[Test]
    public function privilegedRoleDetection(): void
    {
        self::assertSame(
            ['ROLE_GROUP_CISO', 'ROLE_SUPER_ADMIN'],
            UserAdministrationGuard::privilegedRolesIn(['ROLE_USER', 'ROLE_SUPER_ADMIN', 'ROLE_GROUP_CISO', 'ROLE_ADMIN']),
        );
        self::assertFalse(UserAdministrationGuard::privilegedRolesChanged(['ROLE_ADMIN'], ['ROLE_USER', 'ROLE_MANAGER']));
        self::assertTrue(UserAdministrationGuard::privilegedRolesChanged(['ROLE_ADMIN'], ['ROLE_KONZERN_AUDITOR']));
        self::assertTrue(UserAdministrationGuard::privilegedRolesChanged(['ROLE_SUPER_ADMIN'], ['ROLE_ADMIN']));
    }

    #[Test]
    public function adminMayNotAssignPrivilegedRoleNamesOrForeignTenants(): void
    {
        $guard = $this->guard(false);

        self::assertTrue($guard->mayAssignRoleName('ROLE_ADMIN'));
        self::assertFalse($guard->mayAssignRoleName('ROLE_SUPER_ADMIN'));
        self::assertFalse($guard->mayAssignRoleName('ROLE_ALLOWED_TO_SWITCH'));
        self::assertFalse($guard->mayAssignRoleName(' role_group_ciso '));
        self::assertTrue($guard->canAssignTenant($this->own));
        self::assertFalse($guard->canAssignTenant($this->foreign));
        self::assertFalse($guard->canAssignTenant(null));
    }

    #[Test]
    public function superAdminIsUnrestricted(): void
    {
        $guard = $this->guard(true);

        self::assertTrue($guard->mayAssignRoleName('ROLE_SUPER_ADMIN'));
        self::assertTrue($guard->canAssignTenant($this->foreign));
        self::assertTrue($guard->canAssignTenant(null));

        $user = (new User())->setTenant($this->own);
        $before = $guard->snapshot($user);
        $user->setRoles(['ROLE_SUPER_ADMIN'])->setTenant($this->foreign);
        self::assertSame([], $guard->enforceSubmittedValues($user, $before, false));
        self::assertContains('ROLE_SUPER_ADMIN', $user->getRoles());
    }

    #[Test]
    public function adminViolationsAreReportedAndReverted(): void
    {
        $guard = $this->guard(false);
        $user = (new User())->setRoles(['ROLE_MANAGER'])->setTenant($this->own);
        $before = $guard->snapshot($user);

        $user->setRoles(['ROLE_SUPER_ADMIN'])->setTenant($this->foreign);
        $user->addCustomRole((new Role())->setName('ROLE_KONZERN_AUDITOR'));

        self::assertSame(
            ['user.validation.privileged_role_forbidden', 'user.validation.tenant_not_assignable'],
            $guard->enforceSubmittedValues($user, $before, false),
        );
        self::assertSame(['ROLE_MANAGER'], $user->getStoredRoles());
        self::assertCount(0, $user->getCustomRoles());
        self::assertSame($this->own, $user->getTenant());
    }

    #[Test]
    public function adminCannotAssignSeededSystemHoldingRoleButSuperAdminCan(): void
    {
        $holdingRole = (new Role())->setName('ROLE_GROUP_CISO')->setIsSystemRole(true);

        $user = (new User())->setTenant($this->own);
        $guard = $this->guard(false);
        $before = $guard->snapshot($user);
        $user->addCustomRole($holdingRole);
        self::assertSame(['user.validation.privileged_role_forbidden'], $guard->enforceSubmittedValues($user, $before, false));
        self::assertCount(0, $user->getCustomRoles());

        $guard = $this->guard(true);
        $before = $guard->snapshot($user);
        $user->addCustomRole($holdingRole);
        self::assertSame([], $guard->enforceSubmittedValues($user, $before, false));
        self::assertContains('ROLE_GROUP_CISO', $user->getRoles());
    }

    #[Test]
    public function adminEditCarriesOverHiddenPrivilegedRoleAndNewUserGetsActorTenant(): void
    {
        $guard = $this->guard(false);

        $holding = (new User())->setRoles(['ROLE_GROUP_CISO', 'ROLE_MANAGER'])->setTenant($this->own);
        $before = $guard->snapshot($holding);
        $holding->setRoles(['ROLE_MANAGER']); // what the filtered form writes back
        self::assertSame([], $guard->enforceSubmittedValues($holding, $before, false));
        self::assertContains('ROLE_GROUP_CISO', $holding->getStoredRoles());

        $new = new User();
        $beforeNew = $guard->snapshot($new);
        self::assertSame([], $guard->enforceSubmittedValues($new, $beforeNew, true));
        self::assertSame($this->own, $new->getTenant());
    }
}
