<?php

declare(strict_types=1);

namespace App\Service\Setup;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * SetupConsoleRunner — extracted from DeploymentWizardController (god-class split).
 *
 * Wraps Symfony\Bundle\FrameworkBundle\Console\Application to run console
 * commands from within the setup-wizard HTTP context:
 *
 *   - createAdminUser()          → app:setup-permissions
 *   - cleanupDatabaseConnection() → rolls back open Doctrine transactions
 *
 * These helpers previously lived as private methods on the controller, but
 * carry no HTTP concerns and are better placed in a focused service.
 */
final class SetupConsoleRunner
{
    public function __construct(
        private readonly KernelInterface $kernel,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Create the initial admin user via the `app:setup-permissions` console command.
     *
     * @param array{email: string, password: string, firstName: string, lastName: string} $data
     * @return array{success: bool, message: string}
     */
    public function createAdminUser(array $data): array
    {
        try {
            $application = new Application($this->kernel);
            $application->setAutoExit(false);

            $arrayInput = new ArrayInput([
                'command' => 'app:setup-permissions',
                '--admin-email' => $data['email'],
                '--admin-password' => $data['password'],
                '--admin-firstname' => $data['firstName'],
                '--admin-lastname' => $data['lastName'],
                '--no-interaction' => true,
            ]);

            $bufferedOutput = new BufferedOutput();
            $exitCode = $application->run($arrayInput, $bufferedOutput);

            if ($exitCode === 0) {
                return [
                    'success' => true,
                    'message' => 'Admin user created successfully',
                ];
            }

            return [
                'success' => false,
                'message' => 'Failed to create admin user: ' . $bufferedOutput->fetch(),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Clean up database connection state to prevent savepoint errors.
     * Rolls back any open transactions and clears the EntityManager identity map.
     */
    public function cleanupDatabaseConnection(): void
    {
        try {
            $connection = $this->entityManager->getConnection();

            while ($connection->isTransactionActive()) {
                try {
                    $connection->rollBack();
                } catch (\Exception) {
                    try {
                        $connection->close();
                    } catch (\Exception) {
                        // Connection already closed — ignore
                    }
                    break;
                }
            }

            $this->entityManager->clear();
        } catch (\Exception) {
            // Silently ignore — the setup command will handle connection issues
        }
    }
}
