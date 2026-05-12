<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Repository\RoleRepository;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use PHPUnit\Framework\Attributes\Test;

class SetupPermissionsCommandTest extends KernelTestCase
{
    #[Test]
    public function testCommandExists(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);

        $this->assertTrue($application->has('app:setup-permissions'));
    }

    #[Test]
    public function testCommandHasCorrectName(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);

        $command = $application->find('app:setup-permissions');
        $this->assertSame('app:setup-permissions', $command->getName());
    }

    #[Test]
    public function testCommandHasDescription(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);

        $command = $application->find('app:setup-permissions');
        $this->assertNotEmpty($command->getDescription());
    }

    /**
     * Asserts all 10 persona/hierarchy system-roles are seeded after running
     * app:setup-permissions (ROLE_USER through ROLE_ADMIN + 6 persona/holding roles).
     */
    #[Test]
    public function testAllTenSystemRolesExistAfterSetup(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);

        $command = $application->find('app:setup-permissions');
        $commandTester = new CommandTester($command);
        $commandTester->execute([]);

        $this->assertSame(0, $commandTester->getStatusCode());

        /** @var RoleRepository $roleRepository */
        $roleRepository = static::getContainer()->get(RoleRepository::class);

        $expectedRoles = [
            'ROLE_USER',
            'ROLE_AUDITOR',
            'ROLE_MANAGER',
            'ROLE_CISO',
            'ROLE_RISK_MANAGER',
            'ROLE_DPO',
            'ROLE_COMPLIANCE_MANAGER',
            'ROLE_GROUP_CISO',
            'ROLE_KONZERN_AUDITOR',
            'ROLE_ADMIN',
        ];

        foreach ($expectedRoles as $roleName) {
            $role = $roleRepository->findByName($roleName);
            $this->assertNotNull($role, sprintf('System role "%s" must exist after app:setup-permissions', $roleName));
            $this->assertTrue($role->isSystemRole(), sprintf('Role "%s" must have isSystemRole=true', $roleName));
        }
    }

    /**
     * Asserts persona-roles (CISO, RISK_MANAGER, DPO, COMPLIANCE_MANAGER) have permissions assigned.
     */
    #[Test]
    public function testPersonaRolesHavePermissions(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);

        $command = $application->find('app:setup-permissions');
        $commandTester = new CommandTester($command);
        $commandTester->execute([]);

        $this->assertSame(0, $commandTester->getStatusCode());

        /** @var RoleRepository $roleRepository */
        $roleRepository = static::getContainer()->get(RoleRepository::class);

        $personaRoles = ['ROLE_CISO', 'ROLE_RISK_MANAGER', 'ROLE_DPO', 'ROLE_COMPLIANCE_MANAGER'];
        foreach ($personaRoles as $roleName) {
            $role = $roleRepository->findByName($roleName);
            $this->assertNotNull($role, sprintf('Role "%s" must exist', $roleName));
            $this->assertGreaterThan(
                0,
                $role->getPermissions()->count(),
                sprintf('Persona role "%s" must have at least one permission assigned', $roleName)
            );
        }
    }

    /**
     * Asserts holding roles (GROUP_CISO, KONZERN_AUDITOR) have read-only permissions only.
     * Cross-tenant enforcement is handled by HoldingTreeAccessTrait in Security voters,
     * so the permission set mirrors ROLE_AUDITOR (read-only at application level).
     */
    #[Test]
    public function testHoldingRolesHaveReadOnlyPermissions(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);

        $command = $application->find('app:setup-permissions');
        $commandTester = new CommandTester($command);
        $commandTester->execute([]);

        $this->assertSame(0, $commandTester->getStatusCode());

        /** @var RoleRepository $roleRepository */
        $roleRepository = static::getContainer()->get(RoleRepository::class);

        $holdingRoles = ['ROLE_GROUP_CISO', 'ROLE_KONZERN_AUDITOR'];
        foreach ($holdingRoles as $roleName) {
            $role = $roleRepository->findByName($roleName);
            $this->assertNotNull($role, sprintf('Holding role "%s" must exist', $roleName));

            $permissionNames = array_map(
                fn ($p) => $p->getName(),
                $role->getPermissions()->toArray()
            );

            // Holding roles must not carry delete/approve permissions
            // (audit.create and audit.edit are in the AUDITOR base-set — allowed)
            $auditCarveOut = ['audit.create', 'audit.edit'];
            foreach ($permissionNames as $permName) {
                if (in_array($permName, $auditCarveOut, true)) {
                    continue;
                }
                foreach (['delete', 'approve'] as $forbiddenAction) {
                    $this->assertFalse(
                        str_contains($permName, '.' . $forbiddenAction),
                        sprintf(
                            'Holding role "%s" must not have permission "%s"',
                            $roleName,
                            $permName
                        )
                    );
                }
            }
        }
    }
}
