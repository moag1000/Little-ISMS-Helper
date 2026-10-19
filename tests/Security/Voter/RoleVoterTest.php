<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\Role;
use App\Entity\User;
use App\Security\Voter\RoleVoter;
use App\Security\Voter\UserVoter;
use Doctrine\Common\Collections\ArrayCollection;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use PHPUnit\Framework\Attributes\Test;

#[AllowMockObjectsWithoutExpectations]
class RoleVoterTest extends TestCase
{
    private RoleVoter $voter;
    private AccessDecisionManagerInterface&MockObject $accessDecisionManager;
    /** Result of UserVoter::EDIT for every holder in these unit tests. */
    private bool $holderEditable = true;
    /** @var list<User> */
    private array $holders = [];

    protected function setUp(): void
    {
        $this->holderEditable = true;
        $this->holders = [];
        $this->accessDecisionManager = $this->createMock(AccessDecisionManagerInterface::class);
        $this->accessDecisionManager->method('decide')
            ->willReturnCallback(function ($token, array $attributes): bool {
                self::assertSame([UserVoter::EDIT], $attributes);

                return $this->holderEditable;
            });
        $this->voter = new RoleVoter(VoterTestHelper::createRoleHierarchy(), $this->accessDecisionManager);
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
    public function testAdminCanCreateRole(): void
    {
        $user = $this->createUser(['ROLE_ADMIN']);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, null, [RoleVoter::CREATE]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    #[Test]
    public function testAdminCanEditCustomRole(): void
    {
        $user = $this->createUser(['ROLE_ADMIN']);
        $role = $this->createRole(false);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $role, [RoleVoter::EDIT]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    #[Test]
    public function testAdminCanDeleteCustomRole(): void
    {
        $user = $this->createUser(['ROLE_ADMIN']);
        $role = $this->createRole(false);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $role, [RoleVoter::DELETE]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
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
    public function editOrDeleteDeniedWhenAHolderIsNotEditableByActor(): void
    {
        // Security hotfix: custom roles are global — a role with a holder the
        // actor may not edit per UserVoter::EDIT is off limits.
        $this->holders = [$this->createUser()];
        $this->holderEditable = false;
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
    public function editAllowedWhenAllHoldersAreEditableByActor(): void
    {
        $this->holders = [$this->createUser(), $this->createUser()];
        $token = $this->createToken($this->createUser(['ROLE_ADMIN']));

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->voter->vote($token, $this->createRole(false), [RoleVoter::EDIT]));
    }

    #[Test]
    public function actorHoldingTheRoleItselfDoesNotBlockIt(): void
    {
        $actor = $this->createUser(['ROLE_ADMIN']);
        $this->holders = [$actor];
        $this->holderEditable = false;
        $this->accessDecisionManager->expects($this->never())->method('decide');

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->voter->vote($this->createToken($actor), $this->createRole(false), [RoleVoter::EDIT]));
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
        $this->holderEditable = false;
        $this->accessDecisionManager->expects($this->never())->method('decide');
        $token = $this->createToken($this->createUser(['ROLE_SUPER_ADMIN']));

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->voter->vote($token, $this->createRole(false), [RoleVoter::EDIT]));
    }
}
