<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\Tenant;
use App\Entity\User;
use App\Security\TenantOwnership;
use App\Security\Voter\UserVoter;
use App\Service\InitialAdminService;
use App\Service\TenantContext;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * UserVoter across the holding tree (#1048 finding 3):
 *  - ROLE_GROUP_CISO / ROLE_KONZERN_AUDITOR read users of subsidiaries
 *    (still subject to the user.view permission), never write them, never
 *    up or sideways.
 *  - This holds even with the user.edit / user.delete permission: only
 *    ROLE_ADMIN administers users below its own organisation (3.15.3
 *    hotfix, pinned in UserVoterTenantScopeTest).
 *  - A ROLE_ADMIN may view a ROLE_SUPER_ADMIN account of its own
 *    organisation, but never edit or delete it.
 */
#[AllowMockObjectsWithoutExpectations]
final class UserVoterHoldingScopeTest extends TestCase
{
    private Tenant $holding;
    private Tenant $subsidiary;
    private Tenant $sibling;

    protected function setUp(): void
    {
        $this->holding = $this->tenant(1);
        $this->subsidiary = $this->tenant(2);
        $this->subsidiary->setParent($this->holding);
        $this->sibling = $this->tenant(3);
        $this->sibling->setParent($this->holding);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function holdingRoles(): array
    {
        return ['group CISO' => ['ROLE_GROUP_CISO'], 'Konzern auditor' => ['ROLE_KONZERN_AUDITOR']];
    }

    #[Test]
    #[DataProvider('holdingRoles')]
    public function holdingRoleReadsSubsidiaryUsersButNeverWritesThem(string $holdingRole): void
    {
        $actor = $this->user(1, [$holdingRole], $this->holding, ['user.view', 'user.edit', 'user.delete']);
        $target = $this->user(2, ['ROLE_USER'], $this->subsidiary);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($actor, $target, UserVoter::VIEW));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($actor, $target, UserVoter::EDIT));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($actor, $target, UserVoter::DELETE));
    }

    #[Test]
    #[DataProvider('holdingRoles')]
    public function holdingReadStillNeedsTheViewPermission(string $holdingRole): void
    {
        $actor = $this->user(1, [$holdingRole], $this->holding);
        $target = $this->user(2, ['ROLE_USER'], $this->subsidiary);

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($actor, $target, UserVoter::VIEW));
    }

    #[Test]
    #[DataProvider('holdingRoles')]
    public function holdingReadNeverGoesUpOrSideways(string $holdingRole): void
    {
        $actor = $this->user(1, [$holdingRole], $this->subsidiary, ['user.view']);

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($actor, $this->user(2, ['ROLE_USER'], $this->holding), UserVoter::VIEW), 'parent');
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($actor, $this->user(3, ['ROLE_USER'], $this->sibling), UserVoter::VIEW), 'sibling');
    }

    #[Test]
    public function holdingReadIsNotGrantedWithoutAHoldingRole(): void
    {
        $actor = $this->user(1, ['ROLE_MANAGER'], $this->holding, ['user.view']);
        $target = $this->user(2, ['ROLE_USER'], $this->subsidiary);

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($actor, $target, UserVoter::VIEW));
    }

    #[Test]
    public function adminCanViewSuperAdminOfItsOwnOrganisation(): void
    {
        $admin = $this->user(1, ['ROLE_ADMIN'], $this->holding);
        $superAdmin = $this->user(2, ['ROLE_SUPER_ADMIN'], $this->holding);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($admin, $superAdmin, UserVoter::VIEW));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($admin, $superAdmin, UserVoter::EDIT));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($admin, $superAdmin, UserVoter::DELETE));
    }

    #[Test]
    public function parentAdminWithHoldingRoleAdministersSubsidiaryUsers(): void
    {
        // ROLE_ADMIN wins over the holding read-only rule for user administration.
        $admin = $this->user(1, ['ROLE_ADMIN', 'ROLE_GROUP_CISO'], $this->holding);
        $target = $this->user(2, ['ROLE_USER'], $this->subsidiary);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($admin, $target, UserVoter::EDIT));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($admin, $this->user(3, ['ROLE_USER'], $this->tenant(9)), UserVoter::VIEW));
    }

    #[Test]
    public function adminCannotReachSuperAdminOfAnotherOrganisation(): void
    {
        $admin = $this->user(1, ['ROLE_ADMIN'], $this->sibling);
        $superAdmin = $this->user(2, ['ROLE_SUPER_ADMIN'], $this->subsidiary);

        foreach ([UserVoter::VIEW, UserVoter::EDIT, UserVoter::DELETE] as $attribute) {
            self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($admin, $superAdmin, $attribute), $attribute);
        }
    }

    private function vote(User $actor, User $target, string $attribute): int
    {
        // TenantContext with the real tree semantics for the actor's organisation.
        $tenantContext = $this->createStub(TenantContext::class);
        $tenantContext->method('getCurrentTenant')->willReturn($actor->getTenant());
        $tenantContext->method('canAccessTenant')->willReturnCallback(
            static fn (Tenant $candidate): bool => TenantOwnership::isSameOrDescendant($actor->getTenant(), $candidate),
        );
        $voter = new UserVoter(
            $this->createStub(InitialAdminService::class),
            VoterTestHelper::createRoleHierarchy(),
            $tenantContext,
        );

        return $voter->vote(new UsernamePasswordToken($actor, 'main', $actor->getRoles()), $target, [$attribute]);
    }

    /**
     * @param list<string> $roles
     * @param list<string> $permissions
     */
    private function user(int $id, array $roles, Tenant $tenant, array $permissions = []): User
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn($id);
        $user->method('getRoles')->willReturn($roles);
        $user->method('getTenant')->willReturn($tenant);
        $user->method('isActive')->willReturn(true);
        $user->method('hasPermission')->willReturnCallback(
            static fn (string $permission): bool => in_array($permission, $permissions, true),
        );

        return $user;
    }

    private function tenant(int $id): Tenant
    {
        $tenant = new Tenant();
        new ReflectionProperty(Tenant::class, 'id')->setValue($tenant, $id);

        return $tenant;
    }
}
