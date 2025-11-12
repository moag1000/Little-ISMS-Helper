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
}
