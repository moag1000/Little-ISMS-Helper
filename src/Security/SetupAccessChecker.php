<?php

namespace App\Security;

use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * Service to check if the application setup is complete and enforce access control.
 *
 * Security Model:
 * - Before setup: /setup routes are PUBLIC (no authentication required)
 * - After setup: /setup routes require ROLE_ADMIN (admin-only access)
 */
class SetupAccessChecker
{
    private const SETUP_LOCK_FILE = '/config/setup_complete.lock';

    public function __construct(
        private readonly ParameterBagInterface $params
    ) {
    }

    /**
     * Check if the initial setup has been completed.
     */
    public function isSetupComplete(): bool
    {
        $setupFile = $this->getSetupLockFilePath();
        return file_exists($setupFile);
    }

    /**
     * Check if the setup was completed recently (within last 5 minutes).
     * Useful for showing welcome messages or post-setup instructions.
     */
    public function isSetupRecentlyCompleted(): bool
    {
        $setupFile = $this->getSetupLockFilePath();

        if (!file_exists($setupFile)) {
            return false;
        }

        $completionTime = @filemtime($setupFile);
        if ($completionTime === false) {
            return false;
        }

        return (time() - $completionTime) < 300; // 5 minutes
    }

    /**
     * Mark the setup as complete by creating the lock file.
     */
    public function markSetupComplete(): void
    {
        $setupFile = $this->getSetupLockFilePath();
        $timestamp = date('Y-m-d H:i:s');

        file_put_contents($setupFile, $timestamp);
    }

    /**
     * Reset the setup (development only).
     *
     * @throws \RuntimeException if not in development environment
     */
    public function resetSetup(): void
    {
        if ($this->params->get('kernel.environment') !== 'dev') {
            throw new \RuntimeException('Setup reset is only allowed in development environment');
        }

        $setupFile = $this->getSetupLockFilePath();

        if (file_exists($setupFile)) {
            unlink($setupFile);
        }
    }

    /**
     * Get the full path to the setup lock file.
     */
    private function getSetupLockFilePath(): string
    {
        return $this->params->get('kernel.project_dir') . self::SETUP_LOCK_FILE;
    }

    /**
     * Check if user can access setup routes.
     *
     * @param bool $isAuthenticated Whether user is authenticated
     * @param array $roles User roles (if authenticated)
     * @return bool True if access is allowed
     */
    public function canAccessSetup(bool $isAuthenticated, array $roles = []): bool
    {
        // If setup not complete, allow everyone
        if (!$this->isSetupComplete()) {
            return true;
        }

        // If setup is complete, require ROLE_ADMIN
        return $isAuthenticated && (
            in_array('ROLE_ADMIN', $roles, true) ||
            in_array('ROLE_SUPER_ADMIN', $roles, true)
        );
    }

    /**
     * Detect current setup state for recovery purposes
     *
     * @return array State information with completed steps
     */
    public function detectSetupState(): array
    {
        $state = [
            'database_configured' => false,
            'database_migrated' => false,
            'admin_user_exists' => false,
            'setup_complete' => $this->isSetupComplete(),
        ];

        // Check if .env.local exists and has DATABASE_URL
        $envLocalPath = $this->params->get('kernel.project_dir') . '/.env.local';
        if (file_exists($envLocalPath)) {
            $content = file_get_contents($envLocalPath);
            if ($content && str_contains($content, 'DATABASE_URL=')) {
                $state['database_configured'] = true;

                // Try to check if migrations ran (this requires DB connection)
                // We'll mark as "potentially migrated" if .env.local exists
                // The actual check happens in the controller with a DB connection
            }
        }

        return $state;
    }

    /**
     * Get recommended next step based on current state
     *
     * @return string Route name of next step
     */
    public function getRecommendedNextStep(): string
    {
        $state = $this->detectSetupState();

        if ($state['setup_complete']) {
            return 'setup_wizard_index'; // Show completion message
        }

        if (!$state['database_configured']) {
            return 'setup_step1_database_config';
        }

        // If DB configured but not sure about admin, go to admin creation
        return 'setup_step2_admin_user';
    }
}
