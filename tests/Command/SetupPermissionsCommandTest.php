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

    /**
     * Asserts ROLE_DPO carries all privacy-domain permissions (sampling of key entries).
     */
    #[Test]
    public function testDpoRoleHasPrivacyPermissions(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);

        $command = $application->find('app:setup-permissions');
        $commandTester = new CommandTester($command);
        $commandTester->execute([]);

        $this->assertSame(0, $commandTester->getStatusCode());

        /** @var RoleRepository $roleRepository */
        $roleRepository = static::getContainer()->get(RoleRepository::class);

        $role = $roleRepository->findByName('ROLE_DPO');
        $this->assertNotNull($role, 'ROLE_DPO must exist after app:setup-permissions');

        $permissionNames = array_map(
            fn ($p) => $p->getName(),
            $role->getPermissions()->toArray()
        );

        $requiredPermissions = [
            'processing_activity.view',
            'dpia.view',
            'data_breach.notify_authority',
            'data_subject_request.respond',
            'consent.revoke',
        ];

        foreach ($requiredPermissions as $permName) {
            $this->assertContains(
                $permName,
                $permissionNames,
                sprintf('ROLE_DPO must have permission "%s"', $permName)
            );
        }
    }

    /**
     * Asserts ROLE_RISK_MANAGER carries risk-treatment-domain permissions.
     */
    #[Test]
    public function testRiskManagerRoleHasRiskTreatmentPermissions(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);

        $command = $application->find('app:setup-permissions');
        $commandTester = new CommandTester($command);
        $commandTester->execute([]);

        $this->assertSame(0, $commandTester->getStatusCode());

        /** @var RoleRepository $roleRepository */
        $roleRepository = static::getContainer()->get(RoleRepository::class);

        $role = $roleRepository->findByName('ROLE_RISK_MANAGER');
        $this->assertNotNull($role, 'ROLE_RISK_MANAGER must exist after app:setup-permissions');

        $permissionNames = array_map(
            fn ($p) => $p->getName(),
            $role->getPermissions()->toArray()
        );

        $this->assertContains('risk.treat', $permissionNames, 'ROLE_RISK_MANAGER must have "risk.treat"');
        $this->assertContains('risk_treatment_plan.approve', $permissionNames, 'ROLE_RISK_MANAGER must have "risk_treatment_plan.approve"');
    }

    /**
     * Asserts ROLE_CISO carries KPI and security-event permissions.
     */
    #[Test]
    public function testCisoRoleHasKpiAndSecurityEventPermissions(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);

        $command = $application->find('app:setup-permissions');
        $commandTester = new CommandTester($command);
        $commandTester->execute([]);

        $this->assertSame(0, $commandTester->getStatusCode());

        /** @var RoleRepository $roleRepository */
        $roleRepository = static::getContainer()->get(RoleRepository::class);

        $role = $roleRepository->findByName('ROLE_CISO');
        $this->assertNotNull($role, 'ROLE_CISO must exist after app:setup-permissions');

        $permissionNames = array_map(
            fn ($p) => $p->getName(),
            $role->getPermissions()->toArray()
        );

        $this->assertContains('kpi.export', $permissionNames, 'ROLE_CISO must have "kpi.export"');
        $this->assertContains('security_event.respond', $permissionNames, 'ROLE_CISO must have "security_event.respond"');
    }

    /**
     * Asserts ROLE_COMPLIANCE_MANAGER carries policy.approve permission.
     */
    #[Test]
    public function testComplianceManagerRoleHasPolicyApprovePermission(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);

        $command = $application->find('app:setup-permissions');
        $commandTester = new CommandTester($command);
        $commandTester->execute([]);

        $this->assertSame(0, $commandTester->getStatusCode());

        /** @var RoleRepository $roleRepository */
        $roleRepository = static::getContainer()->get(RoleRepository::class);

        $role = $roleRepository->findByName('ROLE_COMPLIANCE_MANAGER');
        $this->assertNotNull($role, 'ROLE_COMPLIANCE_MANAGER must exist after app:setup-permissions');

        $permissionNames = array_map(
            fn ($p) => $p->getName(),
            $role->getPermissions()->toArray()
        );

        $this->assertContains('policy.approve', $permissionNames, 'ROLE_COMPLIANCE_MANAGER must have "policy.approve"');
    }

    /**
     * Asserts GROUP_CISO and KONZERN_AUDITOR do not carry write/mutating permissions
     * from the new privacy, risk-treatment, kpi, and policy domains.
     *
     * Carve-outs (AUDITOR base-set, intentional): audit.create, audit.edit, report.create.
     */
    #[Test]
    public function testHoldingRolesDoNotHaveMutatingNewDomainPermissions(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);

        $command = $application->find('app:setup-permissions');
        $commandTester = new CommandTester($command);
        $commandTester->execute([]);

        $this->assertSame(0, $commandTester->getStatusCode());

        /** @var RoleRepository $roleRepository */
        $roleRepository = static::getContainer()->get(RoleRepository::class);

        // These carve-outs are intentional (AUDITOR base-set)
        $allowedCarveOuts = ['audit.create', 'audit.edit', 'report.create'];

        $forbiddenActions = ['create', 'edit', 'approve', 'delete', 'respond'];

        $holdingRoles = ['ROLE_GROUP_CISO', 'ROLE_KONZERN_AUDITOR'];
        foreach ($holdingRoles as $roleName) {
            $role = $roleRepository->findByName($roleName);
            $this->assertNotNull($role, sprintf('Holding role "%s" must exist', $roleName));

            $permissionNames = array_map(
                fn ($p) => $p->getName(),
                $role->getPermissions()->toArray()
            );

            foreach ($permissionNames as $permName) {
                if (in_array($permName, $allowedCarveOuts, true)) {
                    continue;
                }
                foreach ($forbiddenActions as $action) {
                    $this->assertFalse(
                        str_ends_with($permName, '.' . $action),
                        sprintf(
                            'Holding role "%s" must not have mutating permission "%s" (action: %s)',
                            $roleName,
                            $permName,
                            $action
                        )
                    );
                }
            }
        }
    }
}
