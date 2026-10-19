<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\Role;
use App\Entity\User;
use App\Security\Voter\RoleVoter;
use Doctrine\Common\Collections\ArrayCollection;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use PHPUnit\Framework\Attributes\Test;

#[AllowMockObjectsWithoutExpectations]
class RoleVoterTest extends TestCase
{
    private RoleVoter $voter;
    /** @var list<User> */
    private array $holders = [];

    protected function setUp(): void
    {
        $this->holders = [];
        $this->voter = new RoleVoter(VoterTestHelper::createRoleHierarchy());
    }

    private function createUser(array $roles = ['ROLE_USER'], bool $isActive = true, array $permissions = []): User
    {
        $user = $this->createMock(User::class);
        $user->method('getRoles')->willReturn($roles);
        $user->method('isActive')->willReturn($isActive);
        $user->method('hasPermission')->willReturnCallback(function ($perm) use ($permissions) {
            return in_array($perm, $permissions);
        });
        return $user;
    }

    private function createRole(bool $isSystemRole = false): Role
    {
        $role = $this->createMock(Role::class);
        $role->method('isSystemRole')->willReturn($isSystemRole);
        $role->method('getId')->willReturn(42);
        $role->method('getUsers')->willReturnCallback(fn () => new ArrayCollection($this->holders));
        return $role;
    }

    private function createToken(User $user): UsernamePasswordToken
    {
        return new UsernamePasswordToken($user, 'main', $user->getRoles());
    }

    #[Test]
    public function testAdminCanViewRole(): void
    {
        $user = $this->createUser(['ROLE_ADMIN']);
        $role = $this->createRole();
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $role, [RoleVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    #[Test]
    public function testOrganisationAdminCannotWriteGlobalRoles(): void
    {
        $token = $this->createToken($this->createUser(['ROLE_ADMIN']));

        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($token, null, [RoleVoter::CREATE]));
        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($token, $this->createRole(), [RoleVoter::EDIT]));
        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($token, $this->createRole(), [RoleVoter::DELETE]));
    }

    #[Test]
    public function testPermissionHolderCannotWriteGlobalRoles(): void
    {
        $user = $this->createUser(['ROLE_USER'], true, ['role.create', 'role.edit', 'role.delete']);
        $token = $this->createToken($user);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($token, null, [RoleVoter::CREATE]));
        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($token, $this->createRole(), [RoleVoter::EDIT]));
        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($token, $this->createRole(), [RoleVoter::DELETE]));
    }

    #[Test]
    public function testSuperAdminCanWriteRolesButNeverDeleteSystemRoles(): void
    {
        $token = $this->createToken($this->createUser(['ROLE_SUPER_ADMIN']));

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->voter->vote($token, null, [RoleVoter::CREATE]));
        $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->voter->vote($token, $this->createRole(), [RoleVoter::EDIT]));
        $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->voter->vote($token, $this->createRole(), [RoleVoter::DELETE]));
        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($token, $this->createRole(true), [RoleVoter::DELETE]));
    }

    #[Test]
    public function adminCannotEditOrDeleteGlobalSystemRole(): void
    {
        // Security hotfix: system roles are global (all tenants) — only the
        // instance operator may change them.
        $token = $this->createToken($this->createUser(['ROLE_ADMIN']));
        $role = $this->createRole(true);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($token, $role, [RoleVoter::EDIT]));
        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($token, $role, [RoleVoter::DELETE]));
        $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->voter->vote($token, $role, [RoleVoter::VIEW]));
    }

    #[Test]
    public function superAdminCanEditSystemRole(): void
    {
        $token = $this->createToken($this->createUser(['ROLE_SUPER_ADMIN']));

        $this->assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($token, $this->createRole(true), [RoleVoter::EDIT]),
        );
    }

    #[Test]
    public function testUserWithPermissionCanViewRole(): void
    {
        $user = $this->createUser(['ROLE_USER'], true, ['role.view']);
        $role = $this->createRole();
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $role, [RoleVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    #[Test]
    public function viewWithoutSubjectIsDecidedByThePermission(): void
    {
        // #[IsGranted(RoleVoter::VIEW)] on the role list / compare pages votes
        // without a subject; the voter must not abstain there.
        $allowed = $this->createToken($this->createUser(['ROLE_USER'], true, ['role.view']));
        $denied = $this->createToken($this->createUser(['ROLE_USER'], true, []));

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->voter->vote($allowed, null, [RoleVoter::VIEW]));
        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($denied, null, [RoleVoter::VIEW]));
        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $this->voter->vote($allowed, null, [RoleVoter::EDIT]));
    }

    #[Test]
    public function testUserWithoutPermissionCannotViewRole(): void
    {
        $user = $this->createUser(['ROLE_USER'], true, []);
        $role = $this->createRole();
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $role, [RoleVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    #[Test]
    public function testUserCannotEditSystemRole(): void
    {
        $user = $this->createUser(['ROLE_USER'], true, ['role.edit']);
        $role = $this->createRole(true);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $role, [RoleVoter::EDIT]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    #[Test]
    public function testUserCannotDeleteSystemRole(): void
    {
        $user = $this->createUser(['ROLE_USER'], true, ['role.delete']);
        $role = $this->createRole(true);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $role, [RoleVoter::DELETE]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    #[Test]
    public function testInactiveUserCannotPerformActions(): void
    {
        $user = $this->createUser(['ROLE_USER'], false, ['role.view']);
        $role = $this->createRole();
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $role, [RoleVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    #[Test]
    public function editOrDeleteByNonSuperAdminIsDeniedAsGlobal(): void
    {
        // Roles are global: neither an organisation admin nor a holder of the
        // role.edit / role.delete permission may change one — not even when
        // every holder is inside the actor's own organisation (this subsumes
        // the 3.15.3 holder-scope rule).
        $this->holders = [$this->createUser()];
        $role = $this->createRole(false);

        foreach ([['ROLE_ADMIN'], ['ROLE_USER']] as $roles) {
            $token = $this->createToken($this->createUser($roles, true, ['role.view', 'role.edit', 'role.delete']));
            $vote = new Vote();
            $this->assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($token, $role, [RoleVoter::EDIT], $vote));
            $this->assertContains(RoleVoter::REASON_OUT_OF_SCOPE, $vote->reasons);
            $this->assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($token, $role, [RoleVoter::DELETE]));
            $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->voter->vote($token, $role, [RoleVoter::VIEW]));
        }
    }

    #[Test]
    public function actorHoldingTheRoleItselfCannotEditIt(): void
    {
        $actor = $this->createUser(['ROLE_ADMIN']);
        $this->holders = [$actor];

        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($this->createToken($actor), $this->createRole(false), [RoleVoter::EDIT]));
    }

    #[Test]
    public function missingPermissionCarriesNoOutOfScopeReason(): void
    {
        $token = $this->createToken($this->createUser(['ROLE_USER'], true, ['role.view']));
        $vote = new Vote();

        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($token, $this->createRole(false), [RoleVoter::EDIT], $vote));
        $this->assertNotContains(RoleVoter::REASON_OUT_OF_SCOPE, $vote->reasons);
    }

    #[Test]
    public function superAdminMayEditCustomRoleHeldInForeignTenant(): void
    {
        $this->holders = [$this->createUser()];
        $token = $this->createToken($this->createUser(['ROLE_SUPER_ADMIN']));

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->voter->vote($token, $this->createRole(false), [RoleVoter::EDIT]));
    }

    #[Test]
    public function superAdminCannotDeleteSystemRoleAndGetsTheGlobalReason(): void
    {
        $token = $this->createToken($this->createUser(['ROLE_SUPER_ADMIN']));
        $vote = new Vote();

        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($token, $this->createRole(true), [RoleVoter::DELETE], $vote));
        $this->assertContains(RoleVoter::REASON_OUT_OF_SCOPE, $vote->reasons);
    }
}
