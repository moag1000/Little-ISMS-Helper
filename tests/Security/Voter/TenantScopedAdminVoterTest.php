<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\TenantRepository;
use App\Security\Voter\TenantScopedAdminVoter;
use App\Service\TenantContext;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * Coverage matrix:
 *
 *  8 attributes × ~10 role permutations × 3 subject shapes
 *  ≈ 80 logical cases. Implemented with PHPUnit DataProviders to
 *  keep the class readable.
 */
#[AllowMockObjectsWithoutExpectations]
final class TenantScopedAdminVoterTest extends TestCase
{
    // --- Test fixtures ----------------------------------------------------

    private function createUser(array $roles = ['ROLE_USER']): User
    {
        $user = $this->createMock(User::class);
        $user->method('getRoles')->willReturn($roles);
        return $user;
    }

    /** Token for an anonymous (non-User) caller. */
    private function createAnonymousToken(): TokenInterface
    {
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn(null);
        return $token;
    }

    private function createToken(User $user): UsernamePasswordToken
    {
        return new UsernamePasswordToken($user, 'main', $user->getRoles());
    }

    /**
     * Build a Security mock whose isGranted() answers from a flat role
     * set — Symfony role-hierarchy is approximated explicitly to keep
     * the unit-test self-contained.
     */
    private function createSecurity(?User $user, array $effectiveRoles): Security
    {
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);
        $security->method('isGranted')->willReturnCallback(
            fn(mixed $attribute): bool =>
                is_string($attribute) && in_array($attribute, $effectiveRoles, true)
        );
        return $security;
    }

    private function createTenant(int $id, ?Tenant $parent = null): Tenant
    {
        $tenant = $this->createMock(Tenant::class);
        $tenant->method('getId')->willReturn($id);
        $tenant->method('isChildOf')->willReturnCallback(
            fn(Tenant $candidate): bool =>
                $parent !== null
                && $candidate->getId() !== null
                && $candidate->getId() === $parent->getId()
        );
        return $tenant;
    }

    private function createTenantContext(?Tenant $current): TenantContext
    {
        $ctx = $this->createMock(TenantContext::class);
        $ctx->method('getCurrentTenant')->willReturn($current);
        $ctx->method('hasTenant')->willReturn($current instanceof Tenant);
        $ctx->method('canAccessTenant')->willReturnCallback(
            function (Tenant $candidate) use ($current): bool {
                if (!$current instanceof Tenant) {
                    return false;
                }
                if ($current === $candidate) {
                    return true;
                }
                if ($current->getId() !== null && $current->getId() === $candidate->getId()) {
                    return true;
                }
                return $candidate->isChildOf($current);
            }
        );
        return $ctx;
    }

    private function createTenantRepository(array $byId = []): TenantRepository
    {
        $repo = $this->createMock(TenantRepository::class);
        $repo->method('find')->willReturnCallback(
            fn(mixed $id): ?Tenant => is_int($id) ? ($byId[$id] ?? null) : null
        );
        return $repo;
    }

    private function buildVoter(
        Security $security,
        TenantContext $tenantContext,
        TenantRepository $tenantRepository,
    ): TenantScopedAdminVoter {
        return new TenantScopedAdminVoter($security, $tenantContext, $tenantRepository);
    }

    // --- supports() -------------------------------------------------------

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function provideSupportedAttributes(): iterable
    {
        yield 'ADMIN_OWN_TENANT'   => [TenantScopedAdminVoter::ADMIN_OWN_TENANT, true];
        yield 'ADMIN_ANY_TENANT'   => [TenantScopedAdminVoter::ADMIN_ANY_TENANT, true];
        yield 'ADMIN_GLOBAL_OP'    => [TenantScopedAdminVoter::ADMIN_GLOBAL_OP, true];
        yield 'ADMIN_HOLDING_READ' => [TenantScopedAdminVoter::ADMIN_HOLDING_READ, true];
        yield 'PERSONA_CISO'       => [TenantScopedAdminVoter::PERSONA_CISO, true];
        yield 'PERSONA_RISK'       => [TenantScopedAdminVoter::PERSONA_RISK, true];
        yield 'PERSONA_DPO'        => [TenantScopedAdminVoter::PERSONA_DPO, true];
        yield 'PERSONA_COMPLIANCE' => [TenantScopedAdminVoter::PERSONA_COMPLIANCE, true];
        yield 'unrelated'          => ['ROLE_USER', false];
        yield 'random string'      => ['SOMETHING_ELSE', false];
        yield 'empty'              => ['', false];
    }

    #[Test]
    #[DataProvider('provideSupportedAttributes')]
    public function testSupports(string $attribute, bool $expectedSupports): void
    {
        $voter = $this->buildVoter(
            $this->createSecurity(null, []),
            $this->createTenantContext(null),
            $this->createTenantRepository(),
        );

        // ACCESS_ABSTAIN is the marker for "supports() returned false".
        $result = $voter->vote($this->createAnonymousToken(), null, [$attribute]);

        if ($expectedSupports) {
            $this->assertNotSame(
                VoterInterface::ACCESS_ABSTAIN,
                $result,
                "Attribute '{$attribute}' should be supported."
            );
        } else {
            $this->assertSame(
                VoterInterface::ACCESS_ABSTAIN,
                $result,
                "Attribute '{$attribute}' should NOT be supported."
            );
        }
    }

    // --- Anonymous token (no User) ---------------------------------------

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideAllAttributes(): iterable
    {
        yield 'ADMIN_OWN_TENANT'   => [TenantScopedAdminVoter::ADMIN_OWN_TENANT];
        yield 'ADMIN_ANY_TENANT'   => [TenantScopedAdminVoter::ADMIN_ANY_TENANT];
        yield 'ADMIN_GLOBAL_OP'    => [TenantScopedAdminVoter::ADMIN_GLOBAL_OP];
        yield 'ADMIN_HOLDING_READ' => [TenantScopedAdminVoter::ADMIN_HOLDING_READ];
        yield 'PERSONA_CISO'       => [TenantScopedAdminVoter::PERSONA_CISO];
        yield 'PERSONA_RISK'       => [TenantScopedAdminVoter::PERSONA_RISK];
        yield 'PERSONA_DPO'        => [TenantScopedAdminVoter::PERSONA_DPO];
        yield 'PERSONA_COMPLIANCE' => [TenantScopedAdminVoter::PERSONA_COMPLIANCE];
    }

    #[Test]
    #[DataProvider('provideAllAttributes')]
    public function testAnonymousIsDeniedForEveryAttribute(string $attribute): void
    {
        $voter = $this->buildVoter(
            $this->createSecurity(null, []),
            $this->createTenantContext(null),
            $this->createTenantRepository(),
        );

        $this->assertSame(
            VoterInterface::ACCESS_DENIED,
            $voter->vote($this->createAnonymousToken(), null, [$attribute]),
            "Anonymous caller must be denied for {$attribute}."
        );
    }

    // --- ADMIN_OWN_TENANT -------------------------------------------------

    #[Test]
    public function testSuperAdminGrantedAdminOwnTenantForAnySubject(): void
    {
        $own     = $this->createTenant(1);
        $foreign = $this->createTenant(99);

        $user    = $this->createUser(['ROLE_SUPER_ADMIN']);
        $voter   = $this->buildVoter(
            $this->createSecurity($user, ['ROLE_SUPER_ADMIN', 'ROLE_ADMIN']),
            $this->createTenantContext($own),
            $this->createTenantRepository([1 => $own, 99 => $foreign]),
        );
        $token = $this->createToken($user);

        $this->assertSame(VoterInterface::ACCESS_GRANTED,
            $voter->vote($token, null, [TenantScopedAdminVoter::ADMIN_OWN_TENANT]));
        $this->assertSame(VoterInterface::ACCESS_GRANTED,
            $voter->vote($token, $own, [TenantScopedAdminVoter::ADMIN_OWN_TENANT]));
        $this->assertSame(VoterInterface::ACCESS_GRANTED,
            $voter->vote($token, $foreign, [TenantScopedAdminVoter::ADMIN_OWN_TENANT]));
        $this->assertSame(VoterInterface::ACCESS_GRANTED,
            $voter->vote($token, 99, [TenantScopedAdminVoter::ADMIN_OWN_TENANT]));
    }

    #[Test]
    public function testAdminGrantedAdminOwnTenantForOwnSubject(): void
    {
        $own = $this->createTenant(1);

        $user  = $this->createUser(['ROLE_ADMIN']);
        $voter = $this->buildVoter(
            $this->createSecurity($user, ['ROLE_ADMIN']),
            $this->createTenantContext($own),
            $this->createTenantRepository([1 => $own]),
        );
        $token = $this->createToken($user);

        $this->assertSame(VoterInterface::ACCESS_GRANTED,
            $voter->vote($token, null, [TenantScopedAdminVoter::ADMIN_OWN_TENANT]));
        $this->assertSame(VoterInterface::ACCESS_GRANTED,
            $voter->vote($token, $own, [TenantScopedAdminVoter::ADMIN_OWN_TENANT]));
        $this->assertSame(VoterInterface::ACCESS_GRANTED,
            $voter->vote($token, 1, [TenantScopedAdminVoter::ADMIN_OWN_TENANT]));
        $this->assertSame(VoterInterface::ACCESS_GRANTED,
            $voter->vote($token, '1', [TenantScopedAdminVoter::ADMIN_OWN_TENANT]));
    }

    #[Test]
    public function testAdminGrantedAdminOwnTenantForDescendantSubject(): void
    {
        $parent = $this->createTenant(1);
        $child  = $this->createTenant(2, $parent);

        $user  = $this->createUser(['ROLE_ADMIN']);
        $voter = $this->buildVoter(
            $this->createSecurity($user, ['ROLE_ADMIN']),
            $this->createTenantContext($parent),
            $this->createTenantRepository([1 => $parent, 2 => $child]),
        );
        $token = $this->createToken($user);

        $this->assertSame(VoterInterface::ACCESS_GRANTED,
            $voter->vote($token, $child, [TenantScopedAdminVoter::ADMIN_OWN_TENANT]));
        $this->assertSame(VoterInterface::ACCESS_GRANTED,
            $voter->vote($token, 2, [TenantScopedAdminVoter::ADMIN_OWN_TENANT]));
    }

    #[Test]
    public function testAdminDeniedAdminOwnTenantForForeignSubject(): void
    {
        $own     = $this->createTenant(1);
        $foreign = $this->createTenant(99);

        $user  = $this->createUser(['ROLE_ADMIN']);
        $voter = $this->buildVoter(
            $this->createSecurity($user, ['ROLE_ADMIN']),
            $this->createTenantContext($own),
            $this->createTenantRepository([1 => $own, 99 => $foreign]),
        );
        $token = $this->createToken($user);

        $this->assertSame(VoterInterface::ACCESS_DENIED,
            $voter->vote($token, $foreign, [TenantScopedAdminVoter::ADMIN_OWN_TENANT]));
        $this->assertSame(VoterInterface::ACCESS_DENIED,
            $voter->vote($token, 99, [TenantScopedAdminVoter::ADMIN_OWN_TENANT]));
    }

    #[Test]
    public function testAdminDeniedAdminOwnTenantWhenNoTenantContext(): void
    {
        $user  = $this->createUser(['ROLE_ADMIN']);
        $voter = $this->buildVoter(
            $this->createSecurity($user, ['ROLE_ADMIN']),
            $this->createTenantContext(null),
            $this->createTenantRepository(),
        );

        $this->assertSame(VoterInterface::ACCESS_DENIED,
            $voter->vote($this->createToken($user), null, [TenantScopedAdminVoter::ADMIN_OWN_TENANT]));
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function provideNonAdminRoleSets(): iterable
    {
        yield 'ROLE_USER'              => [['ROLE_USER']];
        yield 'ROLE_AUDITOR'           => [['ROLE_AUDITOR', 'ROLE_USER']];
        yield 'ROLE_MANAGER'           => [['ROLE_MANAGER', 'ROLE_USER']];
        yield 'ROLE_CISO only'         => [['ROLE_CISO']];
        yield 'ROLE_RISK_MANAGER only' => [['ROLE_RISK_MANAGER']];
        yield 'ROLE_DPO only'          => [['ROLE_DPO']];
        yield 'ROLE_COMPLIANCE only'   => [['ROLE_COMPLIANCE_MANAGER']];
        yield 'ROLE_GROUP_CISO only'   => [['ROLE_GROUP_CISO']];
    }

    #[Test]
    #[DataProvider('provideNonAdminRoleSets')]
    public function testNonAdminDeniedAdminOwnTenant(array $roles): void
    {
        $own  = $this->createTenant(1);
        $user = $this->createUser($roles);
        $voter = $this->buildVoter(
            $this->createSecurity($user, $roles),
            $this->createTenantContext($own),
            $this->createTenantRepository([1 => $own]),
        );

        $this->assertSame(VoterInterface::ACCESS_DENIED,
            $voter->vote($this->createToken($user), $own, [TenantScopedAdminVoter::ADMIN_OWN_TENANT]));
        $this->assertSame(VoterInterface::ACCESS_DENIED,
            $voter->vote($this->createToken($user), null, [TenantScopedAdminVoter::ADMIN_OWN_TENANT]));
    }

    // --- ADMIN_ANY_TENANT + ADMIN_GLOBAL_OP -------------------------------

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideSuperOnlyAttributes(): iterable
    {
        yield 'ADMIN_ANY_TENANT' => [TenantScopedAdminVoter::ADMIN_ANY_TENANT];
        yield 'ADMIN_GLOBAL_OP'  => [TenantScopedAdminVoter::ADMIN_GLOBAL_OP];
    }

    #[Test]
    #[DataProvider('provideSuperOnlyAttributes')]
    public function testSuperAdminGrantedForSuperOnlyAttributes(string $attribute): void
    {
        $user  = $this->createUser(['ROLE_SUPER_ADMIN']);
        $voter = $this->buildVoter(
            $this->createSecurity($user, ['ROLE_SUPER_ADMIN', 'ROLE_ADMIN']),
            $this->createTenantContext(null),
            $this->createTenantRepository(),
        );

        $this->assertSame(VoterInterface::ACCESS_GRANTED,
            $voter->vote($this->createToken($user), null, [$attribute]));
    }

    #[Test]
    #[DataProvider('provideSuperOnlyAttributes')]
    public function testAdminDeniedForSuperOnlyAttributes(string $attribute): void
    {
        $user  = $this->createUser(['ROLE_ADMIN']);
        $voter = $this->buildVoter(
            $this->createSecurity($user, ['ROLE_ADMIN']),
            $this->createTenantContext(null),
            $this->createTenantRepository(),
        );

        $this->assertSame(VoterInterface::ACCESS_DENIED,
            $voter->vote($this->createToken($user), null, [$attribute]));
    }

    #[Test]
    #[DataProvider('provideSuperOnlyAttributes')]
    public function testManagerDeniedForSuperOnlyAttributes(string $attribute): void
    {
        $user  = $this->createUser(['ROLE_MANAGER']);
        $voter = $this->buildVoter(
            $this->createSecurity($user, ['ROLE_MANAGER', 'ROLE_USER']),
            $this->createTenantContext(null),
            $this->createTenantRepository(),
        );

        $this->assertSame(VoterInterface::ACCESS_DENIED,
            $voter->vote($this->createToken($user), null, [$attribute]));
    }

    // --- ADMIN_HOLDING_READ ----------------------------------------------

    #[Test]
    public function testSuperAdminGrantedHoldingRead(): void
    {
        $own     = $this->createTenant(1);
        $foreign = $this->createTenant(99);
        $user    = $this->createUser(['ROLE_SUPER_ADMIN']);

        $voter = $this->buildVoter(
            $this->createSecurity($user, ['ROLE_SUPER_ADMIN']),
            $this->createTenantContext($own),
            $this->createTenantRepository([1 => $own, 99 => $foreign]),
        );

        $this->assertSame(VoterInterface::ACCESS_GRANTED,
            $voter->vote($this->createToken($user), null, [TenantScopedAdminVoter::ADMIN_HOLDING_READ]));
        $this->assertSame(VoterInterface::ACCESS_GRANTED,
            $voter->vote($this->createToken($user), $foreign, [TenantScopedAdminVoter::ADMIN_HOLDING_READ]));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideHoldingReadRoles(): iterable
    {
        yield 'ROLE_GROUP_CISO'      => ['ROLE_GROUP_CISO'];
        yield 'ROLE_KONZERN_AUDITOR' => ['ROLE_KONZERN_AUDITOR'];
    }

    #[Test]
    #[DataProvider('provideHoldingReadRoles')]
    public function testHoldingRoleGrantedHoldingReadForOwnTenant(string $role): void
    {
        $own  = $this->createTenant(1);
        $user = $this->createUser([$role]);

        $voter = $this->buildVoter(
            $this->createSecurity($user, [$role]),
            $this->createTenantContext($own),
            $this->createTenantRepository([1 => $own]),
        );

        $this->assertSame(VoterInterface::ACCESS_GRANTED,
            $voter->vote($this->createToken($user), $own, [TenantScopedAdminVoter::ADMIN_HOLDING_READ]));
        $this->assertSame(VoterInterface::ACCESS_GRANTED,
            $voter->vote($this->createToken($user), null, [TenantScopedAdminVoter::ADMIN_HOLDING_READ]));
        $this->assertSame(VoterInterface::ACCESS_GRANTED,
            $voter->vote($this->createToken($user), 1, [TenantScopedAdminVoter::ADMIN_HOLDING_READ]));
    }

    #[Test]
    #[DataProvider('provideHoldingReadRoles')]
    public function testHoldingRoleGrantedHoldingReadForDescendant(string $role): void
    {
        $parent = $this->createTenant(1);
        $child  = $this->createTenant(2, $parent);
        $user   = $this->createUser([$role]);

        $voter = $this->buildVoter(
            $this->createSecurity($user, [$role]),
            $this->createTenantContext($parent),
            $this->createTenantRepository([1 => $parent, 2 => $child]),
        );

        $this->assertSame(VoterInterface::ACCESS_GRANTED,
            $voter->vote($this->createToken($user), $child, [TenantScopedAdminVoter::ADMIN_HOLDING_READ]));
    }

    #[Test]
    #[DataProvider('provideHoldingReadRoles')]
    public function testHoldingRoleDeniedHoldingReadForForeignTenant(string $role): void
    {
        $own     = $this->createTenant(1);
        $foreign = $this->createTenant(99);
        $user    = $this->createUser([$role]);

        $voter = $this->buildVoter(
            $this->createSecurity($user, [$role]),
            $this->createTenantContext($own),
            $this->createTenantRepository([1 => $own, 99 => $foreign]),
        );

        $this->assertSame(VoterInterface::ACCESS_DENIED,
            $voter->vote($this->createToken($user), $foreign, [TenantScopedAdminVoter::ADMIN_HOLDING_READ]));
        $this->assertSame(VoterInterface::ACCESS_DENIED,
            $voter->vote($this->createToken($user), 99, [TenantScopedAdminVoter::ADMIN_HOLDING_READ]));
    }

    #[Test]
    public function testRegularAdminDeniedHoldingRead(): void
    {
        $own  = $this->createTenant(1);
        $user = $this->createUser(['ROLE_ADMIN']);

        $voter = $this->buildVoter(
            $this->createSecurity($user, ['ROLE_ADMIN']),
            $this->createTenantContext($own),
            $this->createTenantRepository([1 => $own]),
        );

        // Plain ROLE_ADMIN without ROLE_GROUP_CISO/KONZERN_AUDITOR is NOT
        // a holding reader. (SUPER_ADMIN gets it because role-hierarchy
        // grants holding roles — but here our flat security mock does NOT
        // include them, so ROLE_ADMIN alone is denied.)
        $this->assertSame(VoterInterface::ACCESS_DENIED,
            $voter->vote($this->createToken($user), $own, [TenantScopedAdminVoter::ADMIN_HOLDING_READ]));
    }

    #[Test]
    public function testRegularUserDeniedHoldingRead(): void
    {
        $own  = $this->createTenant(1);
        $user = $this->createUser(['ROLE_USER']);

        $voter = $this->buildVoter(
            $this->createSecurity($user, ['ROLE_USER']),
            $this->createTenantContext($own),
            $this->createTenantRepository([1 => $own]),
        );

        $this->assertSame(VoterInterface::ACCESS_DENIED,
            $voter->vote($this->createToken($user), $own, [TenantScopedAdminVoter::ADMIN_HOLDING_READ]));
    }

    // --- Persona attributes ----------------------------------------------

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function providePersonaPairs(): iterable
    {
        yield 'CISO'       => [TenantScopedAdminVoter::PERSONA_CISO,       'ROLE_CISO'];
        yield 'RISK'       => [TenantScopedAdminVoter::PERSONA_RISK,       'ROLE_RISK_MANAGER'];
        yield 'DPO'        => [TenantScopedAdminVoter::PERSONA_DPO,        'ROLE_DPO'];
        yield 'COMPLIANCE' => [TenantScopedAdminVoter::PERSONA_COMPLIANCE, 'ROLE_COMPLIANCE_MANAGER'];
    }

    #[Test]
    #[DataProvider('providePersonaPairs')]
    public function testPersonaAttributeGrantedWhenRoleIsHeld(string $attribute, string $role): void
    {
        $user = $this->createUser([$role]);
        $voter = $this->buildVoter(
            $this->createSecurity($user, [$role]),
            $this->createTenantContext(null),
            $this->createTenantRepository(),
        );

        $this->assertSame(VoterInterface::ACCESS_GRANTED,
            $voter->vote($this->createToken($user), null, [$attribute]));
    }

    #[Test]
    #[DataProvider('providePersonaPairs')]
    public function testPersonaAttributeDeniedWhenRoleNotHeld(string $attribute, string $role): void
    {
        // Plain ROLE_USER — no persona at all.
        $user = $this->createUser(['ROLE_USER']);
        $voter = $this->buildVoter(
            $this->createSecurity($user, ['ROLE_USER']),
            $this->createTenantContext(null),
            $this->createTenantRepository(),
        );

        $this->assertSame(VoterInterface::ACCESS_DENIED,
            $voter->vote($this->createToken($user), null, [$attribute]));
    }

    #[Test]
    public function testPersonaAttributesIgnoreSubject(): void
    {
        // A foreign-tenant subject must NOT change a persona vote — persona
        // is a pure role-presence check.
        $foreign = $this->createTenant(99);
        $user    = $this->createUser(['ROLE_DPO']);
        $voter   = $this->buildVoter(
            $this->createSecurity($user, ['ROLE_DPO']),
            $this->createTenantContext($this->createTenant(1)),
            $this->createTenantRepository([1 => $this->createTenant(1), 99 => $foreign]),
        );

        $this->assertSame(VoterInterface::ACCESS_GRANTED,
            $voter->vote($this->createToken($user), $foreign, [TenantScopedAdminVoter::PERSONA_DPO]));
        $this->assertSame(VoterInterface::ACCESS_GRANTED,
            $voter->vote($this->createToken($user), 'global', [TenantScopedAdminVoter::PERSONA_DPO]));
    }

    // --- Edge cases -------------------------------------------------------

    #[Test]
    public function testUnknownIntegerSubjectIsTreatedAsNonTenant(): void
    {
        $own  = $this->createTenant(1);
        $user = $this->createUser(['ROLE_ADMIN']);
        $voter = $this->buildVoter(
            $this->createSecurity($user, ['ROLE_ADMIN']),
            $this->createTenantContext($own),
            // Repository has tenant 1 only — id 42 is unknown.
            $this->createTenantRepository([1 => $own]),
        );

        $this->assertSame(VoterInterface::ACCESS_DENIED,
            $voter->vote($this->createToken($user), 42, [TenantScopedAdminVoter::ADMIN_OWN_TENANT]));
    }

    #[Test]
    public function testGlobalLiteralIsTreatedAsRouteLevelForAdminOwnTenant(): void
    {
        $own  = $this->createTenant(1);
        $user = $this->createUser(['ROLE_ADMIN']);
        $voter = $this->buildVoter(
            $this->createSecurity($user, ['ROLE_ADMIN']),
            $this->createTenantContext($own),
            $this->createTenantRepository([1 => $own]),
        );

        // For a ROLE_ADMIN with active tenant context, 'global' subject
        // means "no specific tenant" → falls through to canAccessTenant
        // gate which is satisfied by the active context.
        $this->assertSame(VoterInterface::ACCESS_GRANTED,
            $voter->vote($this->createToken($user), 'global', [TenantScopedAdminVoter::ADMIN_OWN_TENANT]));
        $this->assertSame(VoterInterface::ACCESS_GRANTED,
            $voter->vote($this->createToken($user), '', [TenantScopedAdminVoter::ADMIN_OWN_TENANT]));
    }

    #[Test]
    public function testNumericStringSubjectResolvesViaRepository(): void
    {
        $own     = $this->createTenant(1);
        $foreign = $this->createTenant(99);
        $user    = $this->createUser(['ROLE_ADMIN']);
        $voter   = $this->buildVoter(
            $this->createSecurity($user, ['ROLE_ADMIN']),
            $this->createTenantContext($own),
            $this->createTenantRepository([1 => $own, 99 => $foreign]),
        );

        $this->assertSame(VoterInterface::ACCESS_GRANTED,
            $voter->vote($this->createToken($user), '1', [TenantScopedAdminVoter::ADMIN_OWN_TENANT]));
        $this->assertSame(VoterInterface::ACCESS_DENIED,
            $voter->vote($this->createToken($user), '99', [TenantScopedAdminVoter::ADMIN_OWN_TENANT]));
    }
}
