<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\Option;
use Exception;
use App\Entity\Permission;
use App\Entity\Role;
use App\Entity\User;
use App\Repository\PermissionRepository;
use App\Repository\RoleRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:setup-permissions',
    description: 'Initialize default permissions, roles and admin user',
)]
class SetupPermissionsCommand
{
    public function __construct(private readonly EntityManagerInterface $entityManager, private readonly PermissionRepository $permissionRepository, private readonly RoleRepository $roleRepository, private readonly UserRepository $userRepository, private readonly UserPasswordHasherInterface $userPasswordHasher)
    {
    }

    public function __invoke(
        #[Option(name: 'reset', description: 'Reset all permissions and roles (WARNING: deletes existing data)')]
        bool $reset = false,
        #[Option(name: 'admin-email', description: 'Create admin user with this email')]
        ?string $adminEmail = null,
        #[Option(name: 'admin-password', description: 'Password for admin user')]
        ?string $adminPassword = null,
        #[Option(name: 'admin-firstname', description: 'First name for admin user')]
        string $adminFirstname = 'Admin',
        #[Option(name: 'admin-lastname', description: 'Last name for admin user')]
        string $adminLastname = 'User',
        ?SymfonyStyle $symfonyStyle = null
    ): int
    {
        if ($reset) {
            if (!$symfonyStyle->confirm('This will delete all existing permissions and roles. Continue?', false)) {
                $symfonyStyle->warning('Operation cancelled.');
                return Command::SUCCESS;
            }

            $this->resetPermissions();
            $symfonyStyle->success('Reset completed.');
        }
        $symfonyStyle->title('Setting up Permissions and Roles');
        // Ensure EntityManager is in a clean state (important when called from web context)
        // Roll back ALL nested transactions/savepoints, not just one level
        $connection = $this->entityManager->getConnection();
        // Get transaction nesting level (Doctrine uses this internally)
        while ($connection->isTransactionActive()) {
            try {
                $connection->rollBack();
            } catch (Exception) {
                // If rollback fails due to missing savepoint, close the connection
                // Doctrine will automatically reconnect on next use
                try {
                    $connection->close();
                } catch (Exception $closeException) {
                    $symfonyStyle->warning('Could not close database connection: ' . $closeException->getMessage());
                }
                break; // Exit loop to avoid infinite attempts
            }
        }
        // Clear EntityManager to reset any cached state
        $this->entityManager->clear();
        // Create permissions
        $symfonyStyle->section('Creating Permissions');
        $this->createPermissions($symfonyStyle);
        // Create roles
        $symfonyStyle->section('Creating Roles');
        $this->createRoles($symfonyStyle);
        // Create admin user if requested
        if ($adminEmail && $adminPassword) {
            $symfonyStyle->section('Creating Admin User');
            $this->createAdminUser(
                $adminEmail,
                $adminPassword,
                $adminFirstname,
                $adminLastname,
                $symfonyStyle
            );
        }
        $symfonyStyle->success('Permissions and roles setup completed successfully!');
        return Command::SUCCESS;
    }

    private function resetPermissions(): void
    {
        // Delete all user_roles and role_permissions first (junction tables)
        $this->entityManager->getConnection()->executeStatement('DELETE FROM user_roles');
        $this->entityManager->getConnection()->executeStatement('DELETE FROM role_permissions');

        // Delete all permissions and roles
        $this->entityManager->createQuery('DELETE FROM App\Entity\Permission')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Role')->execute();
    }

    private function createPermissions(SymfonyStyle $symfonyStyle): void
    {
        $permissions = [
            // User permissions
            ['user.view', 'View users', 'user', 'view'],
            ['user.view_all', 'View all users', 'user', 'view'],
            ['user.create', 'Create users', 'user', 'create'],
            ['user.edit', 'Edit users', 'user', 'edit'],
            ['user.delete', 'Delete users', 'user', 'delete'],
            ['user.manage_roles', 'Manage user roles', 'user', 'edit'],
            ['user.manage_permissions', 'Manage user permissions', 'user', 'edit'],

            // Role permissions
            ['role.view', 'View roles', 'role', 'view'],
            ['role.create', 'Create roles', 'role', 'create'],
            ['role.edit', 'Edit roles', 'role', 'edit'],
            ['role.delete', 'Delete roles', 'role', 'delete'],

            // Risk permissions
            ['risk.view', 'View risks', 'risk', 'view'],
            ['risk.create', 'Create risks', 'risk', 'create'],
            ['risk.edit', 'Edit risks', 'risk', 'edit'],
            ['risk.delete', 'Delete risks', 'risk', 'delete'],
            ['risk.approve', 'Approve risk assessments', 'risk', 'approve'],
            ['risk.export', 'Export risk data', 'risk', 'export'],

            // Asset permissions
            ['asset.view', 'View assets', 'asset', 'view'],
            ['asset.create', 'Create assets', 'asset', 'create'],
            ['asset.edit', 'Edit assets', 'asset', 'edit'],
            ['asset.delete', 'Delete assets', 'asset', 'delete'],
            ['asset.export', 'Export asset data', 'asset', 'export'],

            // Incident permissions
            ['incident.view', 'View incidents', 'incident', 'view'],
            ['incident.create', 'Create incidents', 'incident', 'create'],
            ['incident.edit', 'Edit incidents', 'incident', 'edit'],
            ['incident.delete', 'Delete incidents', 'incident', 'delete'],
            ['incident.approve', 'Approve incident resolutions', 'incident', 'approve'],

            // Control permissions
            ['control.view', 'View controls', 'control', 'view'],
            ['control.create', 'Create controls', 'control', 'create'],
            ['control.edit', 'Edit controls', 'control', 'edit'],
            ['control.delete', 'Delete controls', 'control', 'delete'],

            // Audit permissions
            ['audit.view', 'View audits', 'audit', 'view'],
            ['audit.create', 'Create audits', 'audit', 'create'],
            ['audit.edit', 'Edit audits', 'audit', 'edit'],
            ['audit.delete', 'Delete audits', 'audit', 'delete'],
            ['audit.approve', 'Approve audit findings', 'audit', 'approve'],

            // Compliance permissions
            ['compliance.view', 'View compliance data', 'compliance', 'view'],
            ['compliance.edit', 'Edit compliance data', 'compliance', 'edit'],
            ['compliance.export', 'Export compliance reports', 'compliance', 'export'],

            // Report permissions
            ['report.view', 'View reports', 'report', 'view'],
            ['report.create', 'Create reports', 'report', 'create'],
            ['report.export', 'Export reports', 'report', 'export'],
        ];

        $count = 0;
        foreach ($permissions as $permData) {
            $existingPerm = $this->permissionRepository->findByName($permData[0]);
            if (!$existingPerm instanceof Permission) {
                $permission = new Permission();
                $permission->setName($permData[0]);
                $permission->setDescription($permData[1]);
                $permission->setCategory($permData[2]);
                $permission->setAction($permData[3]);
                $permission->setIsSystemPermission(true);

                $this->entityManager->persist($permission);
                $count++;
            }
        }

        try {
            $this->entityManager->flush();
            $symfonyStyle->success("Created $count permissions.");
        } catch (Exception $e) {
            $symfonyStyle->error("Failed to create permissions: " . $e->getMessage());
            throw $e;
        }
    }

    private function createRoles(SymfonyStyle $symfonyStyle): void
    {
        $roles = [
            'ROLE_USER' => [
                'description' => 'Basic user role - read-only access',
                'permissions' => [
                    'risk.view', 'asset.view', 'incident.view', 'control.view',
                    'audit.view', 'compliance.view', 'report.view'
                ]
            ],
            'ROLE_AUDITOR' => [
                'description' => 'Internal auditor - can create and manage audits',
                'permissions' => [
                    'risk.view', 'asset.view', 'incident.view', 'control.view',
                    'audit.view', 'audit.create', 'audit.edit',
                    'compliance.view', 'report.view', 'report.create'
                ]
            ],
            'ROLE_MANAGER' => [
                'description' => 'ISMS Manager - can manage risks, incidents, controls',
                'permissions' => [
                    'risk.view', 'risk.create', 'risk.edit', 'risk.approve',
                    'asset.view', 'asset.create', 'asset.edit',
                    'incident.view', 'incident.create', 'incident.edit', 'incident.approve',
                    'control.view', 'control.create', 'control.edit',
                    'audit.view', 'audit.approve',
                    'compliance.view', 'compliance.edit',
                    'report.view', 'report.create', 'report.export'
                ]
            ],
            'ROLE_ADMIN' => [
                'description' => 'Administrator - full system access',
                'permissions' => '*' // Will get all permissions
            ]
        ];

        $count = 0;
        foreach ($roles as $roleName => $roleData) {
            $existingRole = $this->roleRepository->findByName($roleName);
            if (!$existingRole instanceof Role) {
                $role = new Role();
                $role->setName($roleName);
                $role->setDescription($roleData['description']);
                $role->setIsSystemRole(true);

                // Add permissions
                if ($roleData['permissions'] === '*') {
                    // Add all permissions for admin
                    $allPermissions = $this->permissionRepository->findAll();
                    foreach ($allPermissions as $allPermission) {
                        $role->addPermission($allPermission);
                    }
                } else {
                    foreach ($roleData['permissions'] as $permName) {
                        $permission = $this->permissionRepository->findByName($permName);
                        if ($permission instanceof Permission) {
                            $role->addPermission($permission);
                        }
                    }
                }

                $this->entityManager->persist($role);
                $count++;
            }
        }

        try {
            $this->entityManager->flush();
            $symfonyStyle->success("Created $count roles.");
        } catch (Exception $e) {
            $symfonyStyle->error("Failed to create roles: " . $e->getMessage());
            throw $e;
        }
    }

    private function createAdminUser(string $email, string $password, string $firstName, string $lastName, SymfonyStyle $symfonyStyle): void
    {
        $existingUser = $this->userRepository->findOneBy(['email' => $email]);
        if ($existingUser) {
            $symfonyStyle->warning("User with email $email already exists.");
            return;
        }

        $user = new User();
        $user->setEmail($email);
        $user->setFirstName($firstName);
        $user->setLastName($lastName);
        $user->setRoles(['ROLE_ADMIN']);
        $user->setAuthProvider('local');
        $user->setIsActive(true);
        $user->setIsVerified(true);

        $hashedPassword = $this->userPasswordHasher->hashPassword($user, $password);
        $user->setPassword($hashedPassword);

        $this->entityManager->persist($user);

        try {
            $this->entityManager->flush();
            $symfonyStyle->success("Admin user created: $firstName $lastName ($email)");
        } catch (Exception $e) {
            $symfonyStyle->error("Failed to create admin user: " . $e->getMessage());
            throw $e;
        }
    }
}
