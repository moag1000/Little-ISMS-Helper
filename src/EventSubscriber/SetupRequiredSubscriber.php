<?php

namespace App\EventSubscriber;

use App\Security\SetupAccessChecker;
use Doctrine\DBAL\Connection;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Event subscriber to redirect to setup wizard if database is not initialized.
 *
 * This ensures that users are automatically redirected to the setup wizard
 * when the application is first deployed and the database tables don't exist yet.
 */
class SetupRequiredSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly SetupAccessChecker $setupChecker,
        private readonly Connection $connection,
        private readonly UrlGeneratorInterface $urlGenerator
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Run BEFORE SetupSecuritySubscriber (priority 9) and firewall (priority 8)
            KernelEvents::REQUEST => ['onKernelRequest', 10],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        // Only handle main requests
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $path = $request->getPathInfo();

        // Skip for setup routes (allow setup wizard to work)
        // Routes have locale prefix: /{_locale}/setup/
        if (str_starts_with($path, '/setup') || str_contains($path, '/setup/')) {
            return;
        }

        // Skip for health check
        if ($path === '/health') {
            return;
        }

        // Skip for assets and profiler
        if (str_starts_with($path, '/_') || str_starts_with($path, '/bundles/')) {
            return;
        }

        // If setup is already complete, don't redirect
        if ($this->setupChecker->isSetupComplete()) {
            return;
        }

        // Check if database tables exist
        if (!$this->databaseTablesExist()) {
            // Redirect to setup wizard with default locale
            $setupUrl = $this->urlGenerator->generate('setup_wizard_index', ['_locale' => 'de']);
            $event->setResponse(new RedirectResponse($setupUrl));
        }
    }

    /**
     * Check if the main database tables exist
     */
    private function databaseTablesExist(): bool
    {
        try {
            // Try to check if the user table exists (it's one of the core tables)
            $schemaManager = $this->connection->createSchemaManager();
            return $schemaManager->tablesExist(['user']);
        } catch (\Exception $e) {
            // Any error means database is not properly set up
            return false;
        }
    }
}
