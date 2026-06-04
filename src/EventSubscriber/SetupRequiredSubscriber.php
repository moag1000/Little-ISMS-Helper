<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Exception;
use App\Security\SetupAccessChecker;
use Doctrine\DBAL\Connection;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Event subscriber to redirect to setup wizard if database is not initialized.
 *
 * This ensures that users are automatically redirected to the setup wizard
 * when the application is first deployed and the database tables don't exist yet.
 *
 * Priority 10: Run BEFORE SetupSecuritySubscriber (priority 4) and firewall (priority 8).
 */
#[AsEventListener(event: KernelEvents::REQUEST, method: 'onKernelRequest', priority: 10)]
final class SetupRequiredSubscriber
{
    public function __construct(
        private readonly SetupAccessChecker $setupAccessChecker,
        private readonly Connection $connection,
        private readonly UrlGeneratorInterface $urlGenerator
    ) {
    }

    public function onKernelRequest(RequestEvent $requestEvent): void
    {
        // Only handle main requests
        if (!$requestEvent->isMainRequest()) {
            return;
        }

        $request = $requestEvent->getRequest();
        $path = $request->getPathInfo();

        // Skip setup routes themselves so the wizard can run. Must also match
        // the locale prefix: Symfony routes /setup as /{_locale}/setup, so the
        // bare index path "/de/setup" has no "/setup/" substring and was NOT
        // skipped — it then redirected to itself → infinite loop.
        $normalizedPath = preg_replace('#^/[a-z]{2}(?=/|$)#', '', $path) ?? $path;
        if (str_starts_with($normalizedPath, '/setup')) {
            return;
        }

        // Skip for health check
        if ($path === '/health') {
            return;
        }

        // Skip for quick-fix fallback page (post-upgrade migration applier)
        if (str_starts_with($path, '/quick-fix')) {
            return;
        }

        // Skip for assets and profiler
        if (str_starts_with($path, '/_') || str_starts_with($path, '/bundles/')) {
            return;
        }

        // Redirect to the setup wizard unless the install is fully configured.
        // "Fully configured" = setup_complete.lock present AND core tables exist.
        // Keying off the lock (not tables alone) is essential: the embedded
        // MariaDB image bootstraps the full schema on first boot, so a table
        // check can never detect a fresh, unconfigured install. The table check
        // is kept as an additional recovery trigger (lock present but schema gone).
        if ($this->setupAccessChecker->isSetupComplete() && $this->databaseTablesExist()) {
            return;
        }

        $setupUrl = $this->urlGenerator->generate('setup_wizard_index', ['_locale' => 'de']);
        $requestEvent->setResponse(new RedirectResponse($setupUrl));
    }

    /**
     * Check if the main database tables exist
     */
    private function databaseTablesExist(): bool
    {
        try {
            // Try to check if the users table exists (it's one of the core tables)
            $schemaManager = $this->connection->createSchemaManager();
            return $schemaManager->tablesExist(['users']);
        } catch (Exception) {
            // Any error means database is not properly set up

            return false;
        }
    }
}
