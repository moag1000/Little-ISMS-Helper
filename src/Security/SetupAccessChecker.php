<?php

declare(strict_types=1);

namespace App\Security;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Service to check if the application setup is complete and enforce access control.
 *
 * Security Model:
 * - Before setup: /setup routes are PUBLIC (no authentication required)
 * - After setup: /setup routes require ROLE_SUPER_ADMIN (the instance operator). An
 *   organisation admin must not reach them: step 2 writes .env.local, step 3 restores
 *   or empties the whole instance.
 *
 * Enforced twice: SetupSecuritySubscriber for every /setup path, and the class-level
 * #[IsGranted(SetupAccessChecker::ACCESS)] on the wizard controllers (SetupAccessVoter).
 *
 * "Complete" is the lock file OR an existing account (S19): a lost or deleted lock file
 * must not hand /setup to an anonymous visitor who then creates an admin. The browser
 * session that is running the wizard (from the database step on) keeps going until step
 * 11 writes the lock; any other visitor of an instance with accounts is treated as after
 * setup, and the lock is written again.
 */
class SetupAccessChecker
{
    /** Voter attribute for the setup wizard controllers ({@see \App\Security\Voter\SetupAccessVoter}). */
    public const string ACCESS = 'SETUP_ACCESS';

    // Runtime state belongs under var/ (the writable, volume-persisted dir),
    // NOT config/ (committed code, lives in the image and is lost on every
    // container recreate — which made the setup wizard re-trigger after an
    // image update despite the data being intact).
    private const string SETUP_LOCK_FILE = '/var/setup_complete.lock';
    // Pre-existing installs wrote the lock to config/. Keep reading it so they
    // are not forced back through the wizard after upgrading.
    private const string LEGACY_SETUP_LOCK_FILE = '/config/setup_complete.lock';

    /**
     * Session flags only the wizard sets (database step 2 onward): a session carrying one got
     * them while setup was still open. The restore in step 3 runs as a job, so the accounts
     * exist before 'setup_backup_restored' reaches the session — 'setup_database_configured'
     * covers that gap.
     */
    private const array WIZARD_SESSION_FLAGS = [
        'setup_database_configured', 'setup_schema_created', 'setup_admin_created', 'setup_backup_restored',
    ];

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
        private readonly ?Connection $connection = null,
        private readonly ?RequestStack $requestStack = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Check if the initial setup has been completed.
     */
    public function isSetupComplete(): bool
    {
        if ($this->resolveExistingLockFile() !== null) {
            return true;
        }
        if (!$this->accountExists() || $this->isWizardSession()) {
            return false;
        }
        // Accounts exist but the lock is gone (deleted, lost volume): the instance is set up.
        $this->logger?->warning('Setup lock file missing although accounts exist — writing it again.');
        @file_put_contents($this->getSetupLockFilePath(), date('Y-m-d H:i:s'));

        return true;
    }

    /** Whether the users table holds at least one account (false while there is no database yet). */
    private function accountExists(): bool
    {
        if ($this->connection === null) {
            return false;
        }
        try {
            return $this->connection->fetchOne('SELECT 1 FROM users') !== false;
        } catch (\Throwable) {
            return false; // no database, no schema yet: the wizard has not got that far
        }
    }

    /** Whether the current browser session is the one running the wizard. */
    private function isWizardSession(): bool
    {
        $request = $this->requestStack?->getMainRequest();
        if ($request === null || !$request->hasSession()) {
            return false;
        }
        $session = $request->getSession();
        foreach (self::WIZARD_SESSION_FLAGS as $flag) {
            if ($session->get($flag)) {
                return true;
            }
        }

        return false;
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
     * @param bool $isSuperAdmin    isGranted('ROLE_SUPER_ADMIN') — via the role hierarchy, never a raw role list
     * @return bool True if access is allowed
     */
    public function canAccessSetup(bool $isAuthenticated, bool $isSuperAdmin = false): bool
    {
        if (!$this->isSetupComplete()) {
            return true;
        }

        return $isAuthenticated && $isSuperAdmin;
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
