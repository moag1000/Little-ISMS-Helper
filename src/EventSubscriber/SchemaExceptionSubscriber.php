<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\QuickFixGuard;
use App\Service\SchemaMaintenanceService;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Doctrine\ORM\Mapping\MappingException as ORMMappingException;
use Doctrine\Persistence\Mapping\MappingException as PersistenceMappingException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Catches schema-mismatch exceptions (missing tables / columns / mappings)
 * and forwards the user to the Quick-Fix UI instead of letting Symfony render
 * a 500. Setup-Wizard handles the empty-DB case at request-time (priority 10
 * KernelEvents::REQUEST), so by the time we see an exception we are in an
 * upgrade scenario: existing instance, code shipped new migrations, schema
 * is out of sync.
 *
 * Disabled when {@see QuickFixGuard::fallbackUiEnabled()} returns false —
 * audit-critical environments fall back to the standard 500.
 */
class SchemaExceptionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly QuickFixGuard $guard,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly SchemaMaintenanceService $maintenance,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Priority 64 — before profiler (-128) and Symfony's default
            // ExceptionListener (-128). Stays out of the way of more
            // specific listeners (TenantNotFoundSubscriber priority 0,
            // SecurityEventSubscriber default).
            KernelEvents::EXCEPTION => ['onKernelException', 64],
        ];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $path = $request->getPathInfo();

        // Avoid recursion if Quick-Fix itself blew up.
        if (str_starts_with($path, '/quick-fix')) {
            return;
        }

        if (!$this->guard->fallbackUiEnabled()) {
            return;
        }

        if (!$this->isSchemaException($event->getThrowable())) {
            return;
        }

        // Guard against false-positives: only redirect to Quick-Fix when there
        // are actually pending Doctrine migrations OR a schema drift between
        // entity metadata and the live DB. A "table not found" error can also
        // come from a typo in raw SQL, which has nothing to do with an
        // out-of-date schema — those should bubble up as a normal 500 with the
        // original stack trace, not a misleading "apply migrations" page.
        try {
            $status = $this->maintenance->getMaintenanceStatus();
            $pending = (int) ($status['migration_status']['pending'] ?? 0);
            $drift = (int) ($status['schema_drift']['count'] ?? 0);
        } catch (\Throwable) {
            // If even reading status fails, the schema is presumably very
            // broken — keep the redirect to give the user a recovery path.
            $pending = 1;
            $drift = 0;
        }
        if ($pending === 0 && $drift === 0) {
            return;
        }

        $event->setResponse(new RedirectResponse(
            $this->urlGenerator->generate('app_quick_fix_index'),
        ));
    }

    private function isSchemaException(\Throwable $throwable): bool
    {
        $current = $throwable;
        while ($current !== null) {
            if (
                $current instanceof TableNotFoundException
                || $current instanceof ORMMappingException
                || $current instanceof PersistenceMappingException
            ) {
                return true;
            }
            // DriverException with "Unknown column" / "no such table" message —
            // older driver versions don't always wrap into TableNotFoundException
            // when the column-level mismatch shows up first.
            if ($current instanceof DriverException) {
                $message = $current->getMessage();
                if (
                    str_contains($message, 'Unknown column')
                    || str_contains($message, 'no such column')
                    || str_contains($message, "doesn't exist")
                    || str_contains($message, 'no such table')
                ) {
                    return true;
                }
            }
            $current = $current->getPrevious();
        }
        return false;
    }
}
