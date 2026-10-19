<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\RoleNames;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Drift guard: RoleNames::RESERVED is the one hard-coded list of Symfony role
 * names that a custom Role entity must not be able to impersonate. It must
 * cover every name in security.yaml `role_hierarchy` (keys and values), the
 * switch_user role and every system role offered by UserType.
 */
final class RoleNamesTest extends KernelTestCase
{
    #[Test]
    public function reservedCoversWholeRoleHierarchyAndSwitchUserRole(): void
    {
        self::bootKernel();
        /** @var array<string, list<string>> $hierarchy */
        $hierarchy = static::getContainer()->getParameter('security.role_hierarchy.roles');

        $expected = array_keys($hierarchy);
        foreach ($hierarchy as $children) {
            array_push($expected, ...$children);
        }
        $expected[] = 'ROLE_ALLOWED_TO_SWITCH';
        // System roles offered by UserType (roles ChoiceType).
        array_push($expected, 'ROLE_USER', 'ROLE_AUDITOR', 'ROLE_MANAGER', 'ROLE_ADMIN', 'ROLE_SUPER_ADMIN',
            'ROLE_CISO', 'ROLE_RISK_MANAGER', 'ROLE_DPO', 'ROLE_COMPLIANCE_MANAGER');

        foreach (array_unique($expected) as $role) {
            self::assertTrue(RoleNames::isReserved($role), $role . ' must be reserved');
        }
    }

    #[Test]
    public function privilegedRolesAreReservedAndIncludeSwitchUser(): void
    {
        foreach (RoleNames::PRIVILEGED as $role) {
            self::assertTrue(RoleNames::isReserved($role), $role);
        }
        self::assertContains('ROLE_ALLOWED_TO_SWITCH', RoleNames::PRIVILEGED);
    }

    #[Test]
    public function reservedCheckIsCaseAndWhitespaceInsensitive(): void
    {
        self::assertTrue(RoleNames::isReserved(' role_super_admin '));
        self::assertFalse(RoleNames::isReserved('ROLE_TOP_MGMT'));
        self::assertFalse(RoleNames::isReserved('Editor'));
    }

    #[Test]
    public function customRoleNameGrantRules(): void
    {
        // Ordinary custom names keep working as before.
        self::assertTrue(RoleNames::isGrantedByCustomRole('Editor', false));
        // A non-system custom role can never impersonate a Symfony role.
        self::assertFalse(RoleNames::isGrantedByCustomRole('ROLE_ADMIN', false));
        self::assertFalse(RoleNames::isGrantedByCustomRole('ROLE_ALLOWED_TO_SWITCH', false));
        // Seeded system roles (holding / persona) keep mapping to their Symfony role ...
        self::assertTrue(RoleNames::isGrantedByCustomRole('ROLE_GROUP_CISO', true));
        // ... except the instance-operator roles, which are never seeded as custom roles.
        self::assertFalse(RoleNames::isGrantedByCustomRole('ROLE_SUPER_ADMIN', true));
        self::assertFalse(RoleNames::isGrantedByCustomRole('ROLE_ALLOWED_TO_SWITCH', true));
    }
}
