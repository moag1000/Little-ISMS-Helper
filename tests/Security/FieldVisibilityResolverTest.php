<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\User;
use App\Security\FieldVisibilityResolver;
use App\Service\AuditLogger;
use App\Tests\Security\Voter\VoterTestHelper;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * F7 Field-Level RBAC — FieldVisibilityResolver unit tests.
 *
 * Verifies that:
 * - Internal roles (ROLE_MANAGER+) can view all sensitive fields.
 * - External roles (ROLE_AUDITOR, ROLE_KONZERN_AUDITOR) are denied.
 * - role_hierarchy expansion works (ROLE_ADMIN satisfies ROLE_MANAGER check).
 * - Unknown fields default to visible (additive, non-breaking).
 * - Null user is denied on restricted fields.
 * - Deny-log is called (A.8.15), deduped once per (class, field) per request.
 */
#[AllowMockObjectsWithoutExpectations]
final class FieldVisibilityResolverTest extends TestCase
{
    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function makeResolver(?AuditLogger $logger = null): FieldVisibilityResolver
    {
        return new FieldVisibilityResolver(
            VoterTestHelper::createRoleHierarchy(),
            $logger ?? $this->createMock(AuditLogger::class),
        );
    }

    private function makeUser(array $roles): User
    {
        $user = $this->createMock(User::class);
        $user->method('getRoles')->willReturn($roles);
        return $user;
    }

    // ------------------------------------------------------------------
    // Internal roles CAN view sensitive fields
    // ------------------------------------------------------------------

    #[Test]
    public function managerCanViewOwnerField(): void
    {
        $this->assertTrue(
            $this->makeResolver()->canViewField('Risk', 'owner', $this->makeUser(['ROLE_MANAGER']))
        );
    }

    #[Test]
    public function adminCanViewSleField(): void
    {
        $this->assertTrue(
            $this->makeResolver()->canViewField('Risk', 'sle', $this->makeUser(['ROLE_ADMIN']))
        );
    }

    #[Test]
    public function managerCanViewAroField(): void
    {
        $this->assertTrue(
            $this->makeResolver()->canViewField('Risk', 'aro', $this->makeUser(['ROLE_MANAGER']))
        );
    }

    #[Test]
    public function managerCanViewAleField(): void
    {
        $this->assertTrue(
            $this->makeResolver()->canViewField('Risk', 'ale', $this->makeUser(['ROLE_MANAGER']))
        );
    }

    #[Test]
    public function managerCanViewOwnerPersonField(): void
    {
        $this->assertTrue(
            $this->makeResolver()->canViewField('Risk', 'ownerPerson', $this->makeUser(['ROLE_MANAGER']))
        );
    }

    #[Test]
    public function managerCanViewOwnerDeputiesField(): void
    {
        $this->assertTrue(
            $this->makeResolver()->canViewField('Risk', 'ownerDeputies', $this->makeUser(['ROLE_MANAGER']))
        );
    }

    // ------------------------------------------------------------------
    // External roles CANNOT view sensitive fields
    // ------------------------------------------------------------------

    #[Test]
    public function auditorCannotViewOwnerField(): void
    {
        $this->assertFalse(
            $this->makeResolver()->canViewField('Risk', 'owner', $this->makeUser(['ROLE_AUDITOR']))
        );
    }

    #[Test]
    public function auditorCannotViewSleField(): void
    {
        $this->assertFalse(
            $this->makeResolver()->canViewField('Risk', 'sle', $this->makeUser(['ROLE_AUDITOR']))
        );
    }

    #[Test]
    public function konzernAuditorCannotViewOwnerField(): void
    {
        $this->assertFalse(
            $this->makeResolver()->canViewField('Risk', 'owner', $this->makeUser(['ROLE_KONZERN_AUDITOR']))
        );
    }

    #[Test]
    public function plainUserCannotViewOwnerField(): void
    {
        $this->assertFalse(
            $this->makeResolver()->canViewField('Risk', 'owner', $this->makeUser(['ROLE_USER']))
        );
    }

    // ------------------------------------------------------------------
    // role_hierarchy expansion — ROLE_ADMIN satisfies ROLE_MANAGER gate
    // ------------------------------------------------------------------

    #[Test]
    public function adminSatisfiesManagerGateViaHierarchy(): void
    {
        // ROLE_ADMIN has ['ROLE_MANAGER'] in the DB hierarchy
        $this->assertTrue(
            $this->makeResolver()->canViewField('Risk', 'owner', $this->makeUser(['ROLE_ADMIN']))
        );
    }

    #[Test]
    public function superAdminSatisfiesManagerGateViaHierarchy(): void
    {
        $this->assertTrue(
            $this->makeResolver()->canViewField('Risk', 'sle', $this->makeUser(['ROLE_SUPER_ADMIN']))
        );
    }

    // ------------------------------------------------------------------
    // Non-sensitive fields are always visible (additive default)
    // ------------------------------------------------------------------

    #[Test]
    public function unknownFieldDefaultsToVisible(): void
    {
        $this->assertTrue(
            $this->makeResolver()->canViewField('Risk', 'title', $this->makeUser(['ROLE_USER']))
        );
    }

    #[Test]
    public function unknownEntityClassDefaultsToVisible(): void
    {
        $this->assertTrue(
            $this->makeResolver()->canViewField('SomeOtherEntity', 'someField', $this->makeUser(['ROLE_USER']))
        );
    }

    // ------------------------------------------------------------------
    // Null user
    // ------------------------------------------------------------------

    #[Test]
    public function nullUserDeniesRestrictedField(): void
    {
        $this->assertFalse(
            $this->makeResolver()->canViewField('Risk', 'owner', null)
        );
    }

    #[Test]
    public function nullUserAllowsNonSensitiveField(): void
    {
        // Non-sensitive fields are visible even to null users (not in MAP → return true).
        // This is consistent — if a field is not sensitive, we don't need auth.
        $this->assertTrue(
            $this->makeResolver()->canViewField('Risk', 'title', null)
        );
    }

    // ------------------------------------------------------------------
    // A.8.15 deny-log — called on deny, deduplicated per (class, field)
    // ------------------------------------------------------------------

    #[Test]
    public function denyLogCalledOnFirstDeny(): void
    {
        $logger = $this->createMock(AuditLogger::class);
        // Verify logCustom is called exactly once with the deny action and correct entity type
        $logger->expects($this->once())
            ->method('logCustom')
            ->with(
                FieldVisibilityResolver::ACTION_FIELD_ACCESS_DENIED,
                'Risk',
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->stringContains('owner'),
            );

        $resolver = $this->makeResolver($logger);
        $user     = $this->makeUser(['ROLE_AUDITOR']);

        $resolver->canViewField('Risk', 'owner', $user);
    }

    #[Test]
    public function denyLogIsDeduplicatedPerRequest(): void
    {
        $logger = $this->createMock(AuditLogger::class);
        // Same entity+field combination called 3 times → only 1 log entry
        $logger->expects($this->once())->method('logCustom');

        $resolver = $this->makeResolver($logger);
        $user     = $this->makeUser(['ROLE_AUDITOR']);

        $resolver->canViewField('Risk', 'owner', $user); // logs once
        $resolver->canViewField('Risk', 'owner', $user); // deduped
        $resolver->canViewField('Risk', 'owner', $user); // deduped
    }

    #[Test]
    public function denyLogCalledSeparatelyForDifferentFields(): void
    {
        $logger = $this->createMock(AuditLogger::class);
        // Two different fields → two separate log entries
        $logger->expects($this->exactly(2))->method('logCustom');

        $resolver = $this->makeResolver($logger);
        $user     = $this->makeUser(['ROLE_AUDITOR']);

        $resolver->canViewField('Risk', 'owner', $user); // logs for 'owner'
        $resolver->canViewField('Risk', 'sle', $user);   // logs for 'sle'
    }

    #[Test]
    public function denyLogNotCalledOnAllowedAccess(): void
    {
        $logger = $this->createMock(AuditLogger::class);
        $logger->expects($this->never())->method('logCustom');

        $resolver = $this->makeResolver($logger);
        $user     = $this->makeUser(['ROLE_MANAGER']);

        $resolver->canViewField('Risk', 'owner', $user); // granted → no log
    }

    // ------------------------------------------------------------------
    // Extension smoke test
    // ------------------------------------------------------------------

    #[Test]
    public function twigExtensionCanBeInstantiated(): void
    {
        $resolver  = $this->makeResolver();
        $security  = $this->createMock(\Symfony\Bundle\SecurityBundle\Security::class);
        $extension = new \App\Twig\FieldVisibilityExtension($resolver, $security);
        $this->assertInstanceOf(\App\Twig\FieldVisibilityExtension::class, $extension);
    }
}
