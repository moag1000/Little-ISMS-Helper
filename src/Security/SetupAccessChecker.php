<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Service to check if the application setup is complete and enforce access control.
 *
 * Security Model:
 * - Before setup: /setup routes are PUBLIC (no authentication required)
 * - After setup: /setup routes require ROLE_ADMIN (admin-only access)
 */
class SetupAccessChecker
{
    // Runtime state belongs under var/ (the writable, volume-persisted dir),
    // NOT config/ (committed code, lives in the image and is lost on every
    // container recreate — which made the setup wizard re-trigger after an
    // image update despite the data being intact).
    private const string SETUP_LOCK_FILE = '/var/setup_complete.lock';
    // Pre-existing installs wrote the lock to config/. Keep reading it so they
    // are not forced back through the wizard after upgrading.
    private const string LEGACY_SETUP_LOCK_FILE = '/config/setup_complete.lock';

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment
    ) {
    }

    /**
     * Check if the initial setup has been completed.
     */
    public function isSetupComplete(): bool
    {
        return $this->resolveExistingLockFile() !== null;
    }

    /**
     * Check if the setup was completed recently (within last 5 minutes).
     * Useful for showing welcome messages or post-setup instructions.
     */
    public function isSetupRecentlyCompleted(): bool
    {
        $setupFile = $this->resolveExistingLockFile();

        if ($setupFile === null) {
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
     * @throws \LogicException if not in development environment
     */
    public function resetSetup(): void
    {
        if ($this->environment !== 'dev') {
            // @intentional-assertion: programmer-error guard — resetSetup() must never be called in production
            throw new \LogicException('Setup reset is only allowed in development environment');
        }

        foreach ([$this->getSetupLockFilePath(), $this->getLegacyLockFilePath()] as $setupFile) {
            if (file_exists($setupFile)) {
                unlink($setupFile);
            }
        }
    }

    /**
     * Get the full path to the setup lock file.
     */
    private function getSetupLockFilePath(): string
    {
        return $this->projectDir . self::SETUP_LOCK_FILE;
    }

    /**
     * Legacy lock path (config/) for installs created before the lock moved
     * to var/. Read-only fallback — new completions are written to var/.
     */
    private function getLegacyLockFilePath(): string
    {
        return $this->projectDir . self::LEGACY_SETUP_LOCK_FILE;
    }

    /**
     * Return the path of the lock file that actually exists (canonical var/
     * first, then the legacy config/ location), or null if setup is not done.
     */
    private function resolveExistingLockFile(): ?string
    {
        foreach ([$this->getSetupLockFilePath(), $this->getLegacyLockFilePath()] as $setupFile) {
            if (file_exists($setupFile)) {
                return $setupFile;
            }
        }

        return null;
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
        $envLocalPath = $this->projectDir . '/.env.local';
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
            return 'setup_step2_database_config';
        }

        // If DB configured but not sure about admin, go to admin creation
        return 'setup_step4_admin_user';
    }
}
