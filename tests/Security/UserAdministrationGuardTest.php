<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Role;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\TenantRepository;
use App\Security\RoleCeiling;
use App\Security\RoleNames;
use App\Security\UserAdministrationGuard;
use App\Service\AuditLogger;
use App\Tests\Security\Voter\VoterTestHelper;
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

    private ?User $actor = null;

    /**
     * @param list<string>|null $actorRoles defaults to ROLE_SUPER_ADMIN / ROLE_ADMIN
     */
    private function guard(bool $superAdmin, ?array $actorRoles = null, ?AuditLogger $auditLogger = null): UserAdministrationGuard
    {
        $security = $this->createMock(Security::class);
        $security->method('isGranted')->willReturnCallback(
            static fn (string $attr): bool => $attr === 'ROLE_SUPER_ADMIN' ? $superAdmin : $attr === 'ROLE_ADMIN',
        );
        $this->actor = (new User())->setEmail('actor@example.com')
            ->setRoles($actorRoles ?? ($superAdmin ? ['ROLE_SUPER_ADMIN'] : ['ROLE_ADMIN']))
            ->setTenant($this->own);
        (new \ReflectionProperty(User::class, 'id'))->setValue($this->actor, 4711);
        $security->method('getUser')->willReturn($this->actor);
        $tenantContext = $this->createMock(TenantContext::class);
        $tenantContext->method('getCurrentTenant')->willReturn($this->own);
        $tenantContext->method('canAccessTenant')->willReturnCallback(fn (Tenant $t): bool => $t === $this->own);

        return new UserAdministrationGuard(
            $security,
            $tenantContext,
            $this->createMock(TenantRepository::class),
            new NullLogger(),
            new RoleCeiling(VoterTestHelper::createRoleHierarchy()),
            $auditLogger ?? $this->createStub(AuditLogger::class),
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

    #[Test]
    public function userFormHidesPrivilegedAndInertReservedCustomRolesFromAdmins(): void
    {
        $holding = (new Role())->setName('ROLE_GROUP_CISO')->setIsSystemRole(true);
        $persona = (new Role())->setName('ROLE_CISO')->setIsSystemRole(true);
        $inert = (new Role())->setName('ROLE_ADMIN')->setIsSystemRole(false);
        $ordinary = (new Role())->setName('Auditors')->setIsSystemRole(false);

        $admin = $this->guard(false);
        self::assertFalse($admin->mayOfferCustomRole($holding));
        self::assertFalse($admin->mayOfferCustomRole($inert));
        self::assertTrue($admin->mayOfferCustomRole($persona));
        self::assertTrue($admin->mayOfferCustomRole($ordinary));

        $super = $this->guard(true);
        self::assertTrue($super->mayOfferCustomRole($holding));
        self::assertTrue($super->mayOfferCustomRole($inert));
    }

    #[Test]
    public function hiddenCustomRolesOfTheTargetAreCarriedOverNotRevoked(): void
    {
        $holding = (new Role())->setName('ROLE_GROUP_CISO')->setIsSystemRole(true);
        $user = (new User())->setRoles(['ROLE_USER'])->setTenant($this->own);
        $user->addCustomRole($holding);
        $guard = $this->guard(false);
        $before = $guard->snapshot($user);

        // The filtered form does not offer the role, so the submit drops it.
        $user->removeCustomRole($holding);

        self::assertSame([], $guard->enforceSubmittedValues($user, $before, false));
        self::assertTrue($user->getCustomRoles()->contains($holding));
    }

    #[Test]
    public function nobodyGrantsThemselvesTopManagementNotEvenASuperAdmin(): void
    {
        foreach ([false, true] as $superAdmin) {
            $guard = $this->guard($superAdmin);
            $self = $this->actor;
            $before = $guard->snapshot($self);
            $self->setRoles([...$self->getStoredRoles(), 'ROLE_TOP_MGMT']);

            self::assertSame(['user.validation.self_grant_forbidden'], $guard->enforceSubmittedValues($self, $before, false));
            self::assertNotContains('ROLE_TOP_MGMT', $self->getRoles());
            self::assertFalse($guard->mayAssignRoleName('ROLE_TOP_MGMT', $self));
            self::assertNotContains('ROLE_TOP_MGMT', $guard->grantableRoles(['ROLE_TOP_MGMT'], $self));

            // Someone else may receive it from the admin.
            $other = (new User())->setRoles(['ROLE_USER'])->setTenant($this->own);
            $beforeOther = $guard->snapshot($other);
            $other->setRoles(['ROLE_TOP_MGMT']);
            self::assertSame([], $guard->enforceSubmittedValues($other, $beforeOther, false));
        }
    }

    #[Test]
    public function managerWithUserRightsGrantsNothingAboveManager(): void
    {
        $guard = $this->guard(false, ['ROLE_MANAGER']);
        $user = (new User())->setRoles(['ROLE_USER'])->setTenant($this->own);
        $before = $guard->snapshot($user);
        $user->setRoles(['ROLE_ADMIN']);

        self::assertSame(['user.validation.privileged_role_forbidden'], $guard->enforceSubmittedValues($user, $before, false));
        self::assertSame(['ROLE_USER'], $user->getStoredRoles());
        self::assertSame(['ROLE_AUDITOR', 'ROLE_MANAGER'], array_values($guard->grantableRoles(['ROLE_AUDITOR', 'ROLE_MANAGER', 'ROLE_CISO', 'ROLE_ADMIN', 'ROLE_TOP_MGMT'])));

        // Revoking a role above the ceiling is refused the same way (hidden → carried over).
        $admin = (new User())->setRoles(['ROLE_ADMIN'])->setTenant($this->own);
        $beforeAdmin = $guard->snapshot($admin);
        $admin->setRoles(['ROLE_USER']);
        self::assertSame([], $guard->enforceSubmittedValues($admin, $beforeAdmin, false));
        self::assertContains('ROLE_ADMIN', $admin->getStoredRoles());
    }

    #[Test]
    public function ssoMappingOffersOnlyKnownNonPrivilegedRolesWithinTheCeiling(): void
    {
        $admin = $this->guard(false)->ssoMappableRoles();
        self::assertContains('ROLE_MANAGER', $admin);
        self::assertContains('ROLE_TOP_MGMT', $admin);
        foreach (RoleNames::PRIVILEGED as $privileged) {
            self::assertNotContains($privileged, $admin);
            self::assertNotContains($privileged, $this->guard(true)->ssoMappableRoles());
        }
        self::assertNotContains('ROLE_ADMIN', $this->guard(false, ['ROLE_MANAGER'])->ssoMappableRoles());
    }

    #[Test]
    public function grantOrRevokeOfTopManagementIsAnAuditEvent(): void
    {
        $audit = $this->createMock(AuditLogger::class);
        $audit->expects(self::exactly(3))->method('logCustom')->with(
            'user.sensitive_role_changed',
            'User',
            self::anything(),
            self::anything(),
            self::callback(static fn (array $new): bool => in_array($new['role'], ['ROLE_TOP_MGMT', 'ROLE_ADMIN', 'ROLE_DPO'], true)),
            self::anything(),
        );
        $guard = $this->guard(false, null, $audit);
        $user = (new User())->setEmail('l@example.com')->setRoles(['ROLE_ADMIN'])->setTenant($this->own);
        $before = $guard->snapshot($user);
        $user->setRoles(['ROLE_TOP_MGMT', 'ROLE_DPO', 'ROLE_ISB']);

        // TOP_MGMT and DPO granted (S28: admins hand out ROLE_DPO, audited), ADMIN revoked; ISB is not audited
        $guard->auditSensitiveRoleChanges($user, $before);
    }
}
