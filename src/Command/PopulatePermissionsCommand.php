<?php

namespace App\Command;

use App\Entity\Permission;
use App\Repository\PermissionRepository;
use App\Security\Voter\PermissionVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:permissions:populate',
    description: 'Populate system permissions from PermissionVoter',
)]
class PopulatePermissionsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PermissionRepository $permissionRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force update existing permissions')
            ->setHelp('This command creates or updates all system permissions defined in PermissionVoter');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $force = $input->getOption('force');

        $io->title('Populating System Permissions');

        $allPermissions = PermissionVoter::getAllPermissions();
        $createdCount = 0;
        $updatedCount = 0;
        $skippedCount = 0;

        foreach ($allPermissions as $category => $permissions) {
            $io->section(sprintf('Category: %s', ucfirst($category)));

            foreach ($permissions as $name => $description) {
                // Check if permission already exists
                $permission = $this->permissionRepository->findOneBy(['name' => $name]);

                if ($permission) {
                    if ($force) {
                        // Update existing permission
                        $permission->setDescription($description);
                        $permission->setCategory($category);
                        $this->entityManager->persist($permission);
                        $updatedCount++;
                        $io->text(sprintf('  <comment>Updated:</comment> %s', $name));
                    } else {
                        $skippedCount++;
                        $io->text(sprintf('  <fg=gray>Skipped:</> %s (already exists)', $name));
                    }
                } else {
                    // Create new permission
                    $permission = new Permission();
                    $permission->setName($name);
                    $permission->setDescription($description);
                    $permission->setCategory($category);

                    // Parse action from name (e.g., USER_EDIT -> edit)
                    $parts = explode('_', $name);
                    $action = strtolower(end($parts));
                    $permission->setAction($action);

                    $permission->setIsSystemPermission(true);

                    $this->entityManager->persist($permission);
                    $createdCount++;
                    $io->text(sprintf('  <info>Created:</info> %s', $name));
                }
            }
        }

        // Flush all changes
        $this->entityManager->flush();

        $io->newLine();
        $io->success(sprintf(
            'Permissions populated successfully! Created: %d, Updated: %d, Skipped: %d',
            $createdCount,
            $updatedCount,
            $skippedCount
        ));

        if ($skippedCount > 0 && !$force) {
            $io->note('Use --force to update existing permissions');
        }

        return Command::SUCCESS;
    }
}
