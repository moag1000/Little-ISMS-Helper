<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Role;
use App\Entity\User;
use App\Security\RoleCeiling;
use App\Security\RoleNames;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Role\RoleHierarchy;

/**
 * Role matrix (S14) over the REAL security.yaml `role_hierarchy`: nobody
 * grants, revokes or administers a role above their own; holding and instance
 * roles only by a super admin; nobody gives themselves top management.
 *
 * The invariants are derived from the hierarchy, so a new role is checked
 * without editing this test: a new holding role (ROLE_GROUP_* / ROLE_KONZERN_*)
 * must be privileged, and every other reserved role must be either reached by
 * ROLE_ADMIN or listed as admin-delegated.
 */
final class RoleCeilingTest extends KernelTestCase
{
    private static ?RoleCeiling $ceiling = null;
    /** @var array<string, list<string>> */
    private static array $hierarchy = [];

    private function ceiling(): RoleCeiling
    {
        if (self::$ceiling === null) {
            self::bootKernel();
            /** @var array<string, list<string>> $hierarchy */
            $hierarchy = static::getContainer()->getParameter('security.role_hierarchy.roles');
            self::$hierarchy = $hierarchy;
            self::$ceiling = new RoleCeiling(new RoleHierarchy($hierarchy));
        }

        return self::$ceiling;
    }

    private static function actor(string ...$roles): User
    {
        return (new User())->setEmail(strtolower(implode('-', $roles)) . '@example.com')->setRoles($roles);
    }

    #[Test]
    public function everyHoldingRoleOfTheHierarchyIsPrivileged(): void
    {
        $this->ceiling();
        $names = array_keys(self::$hierarchy);
        foreach (self::$hierarchy as $children) {
            array_push($names, ...$children);
        }
        foreach (array_unique($names) as $role) {
            if (str_starts_with($role, 'ROLE_GROUP_') || str_starts_with($role, 'ROLE_KONZERN_')) {
                self::assertTrue(RoleNames::isPrivileged($role), $role . ' reaches across organisations and must be in RoleNames::PRIVILEGED');
            }
        }
        self::assertContains('ROLE_GROUP_BCM_OFFICER', RoleNames::PRIVILEGED);
    }

    #[Test]
    public function adminCeilingCoversEveryNonPrivilegedRole(): void
    {
        $admin = self::actor('ROLE_ADMIN');
        foreach (RoleNames::RESERVED as $role) {
            self::assertSame(
                !RoleNames::isPrivileged($role),
                $this->ceiling()->mayChange($admin, $role),
                $role . ': an organisation admin grants exactly the non-privileged roles',
            );
        }
    }

    #[Test]
    public function onlySuperAdminChangesPrivilegedRoles(): void
    {
        $actors = ['ROLE_USER', 'ROLE_AUDITOR', 'ROLE_MANAGER', 'ROLE_CISO', 'ROLE_ISB', 'ROLE_TOP_MGMT', 'ROLE_ADMIN',
            'ROLE_GROUP_CISO', 'ROLE_KONZERN_AUDITOR', 'ROLE_GROUP_BCM_OFFICER'];
        foreach (RoleNames::PRIVILEGED as $role) {
            foreach ($actors as $actorRole) {
                self::assertFalse($this->ceiling()->mayChange(self::actor($actorRole), $role), $actorRole . ' must not grant ' . $role);
            }
            self::assertTrue($this->ceiling()->mayChange(self::actor('ROLE_SUPER_ADMIN'), $role), 'super admin grants ' . $role);
        }
    }

    /**
     * Below admin, an actor grants only roles it reaches itself.
     */
    #[Test]
    public function nobodyBelowAdminGrantsARoleAboveTheirOwn(): void
    {
        $matrix = [
            'ROLE_USER' => ['ROLE_USER' => true, 'ROLE_AUDITOR' => false, 'ROLE_MANAGER' => false, 'ROLE_ADMIN' => false],
            'ROLE_MANAGER' => ['ROLE_AUDITOR' => true, 'ROLE_MANAGER' => true, 'ROLE_CISO' => false, 'ROLE_DPO' => false,
                'ROLE_ADMIN' => false, 'ROLE_TOP_MGMT' => false, 'ROLE_FUNCTION_OWNER' => false],
            'ROLE_CISO' => ['ROLE_CISO' => true, 'ROLE_MANAGER' => true, 'ROLE_DPO' => false, 'ROLE_ADMIN' => false],
            'ROLE_TOP_MGMT' => ['ROLE_AUDITOR' => true, 'ROLE_TOP_MGMT' => true, 'ROLE_MANAGER' => false, 'ROLE_ADMIN' => false],
        ];
        foreach ($matrix as $actorRole => $expectations) {
            foreach ($expectations as $role => $expected) {
                self::assertSame($expected, $this->ceiling()->mayChange(self::actor($actorRole), $role), $actorRole . ' → ' . $role);
            }
        }
        // Names that are not Symfony roles (labels of custom roles) are not limited here.
        self::assertTrue($this->ceiling()->mayChange(self::actor('ROLE_USER'), 'Datenschutz-Team'));
    }

    #[Test]
    public function nobodyGivesThemselvesTopManagement(): void
    {
        foreach (['ROLE_ADMIN', 'ROLE_SUPER_ADMIN'] as $actorRole) {
            $actor = self::actor($actorRole);
            self::assertNotContains('ROLE_TOP_MGMT', $this->ceiling()->grantable($actor, ['ROLE_TOP_MGMT', 'ROLE_DPO'], $actor), $actorRole);
            self::assertContains('ROLE_TOP_MGMT', $this->ceiling()->grantable($actor, ['ROLE_TOP_MGMT'], self::actor('ROLE_USER')), $actorRole . ' grants it to others');
        }
        // Who already holds it keeps the box (and may drop it).
        $leitung = self::actor('ROLE_ADMIN', 'ROLE_TOP_MGMT');
        self::assertContains('ROLE_TOP_MGMT', $this->ceiling()->grantable($leitung, ['ROLE_TOP_MGMT'], $leitung));
    }

    #[Test]
    public function accountsAboveTheCeilingAreNotAdministered(): void
    {
        $admin = self::actor('ROLE_ADMIN');
        foreach (['ROLE_GROUP_CISO', 'ROLE_KONZERN_AUDITOR', 'ROLE_GROUP_BCM_OFFICER', 'ROLE_SUPER_ADMIN'] as $role) {
            self::assertFalse($this->ceiling()->mayAdminister($admin, self::actor('ROLE_MANAGER', $role)), 'admin must not take over ' . $role);
        }
        // Holding roles usually come as a seeded system custom role.
        $viaCustomRole = self::actor('ROLE_USER')->addCustomRole((new Role())->setName('ROLE_GROUP_BCM_OFFICER')->setIsSystemRole(true));
        self::assertFalse($this->ceiling()->mayAdminister($admin, $viaCustomRole));

        self::assertTrue($this->ceiling()->mayAdminister($admin, self::actor('ROLE_TOP_MGMT')));
        self::assertTrue($this->ceiling()->mayAdminister($admin, self::actor('ROLE_ADMIN')));
        self::assertFalse($this->ceiling()->mayAdminister(self::actor('ROLE_MANAGER'), self::actor('ROLE_ADMIN')));
        self::assertTrue($this->ceiling()->mayAdminister(self::actor('ROLE_SUPER_ADMIN'), $viaCustomRole));
    }
}
