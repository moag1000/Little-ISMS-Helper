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

        if (!$this->guard->fallbackUiEnabled()) {
            return;
        }

        if (!$this->isSchemaException($event->getThrowable())) {
            return;
        }

        // Guard against false-positives: only act when there are actually
        // pending Doctrine migrations OR a schema drift between entity
        // metadata and the live DB. A "table not found" error can also come
        // from a typo in raw SQL, which has nothing to do with an out-of-date
        // schema — those should bubble up as a normal 500 with the original
        // stack trace, not a misleading "apply migrations" page.
        try {
            $status = $this->maintenance->getMaintenanceStatus();
            $pending = (int) ($status['migration_status']['pending'] ?? 0);
            $drift = (int) ($status['schema_drift']['count'] ?? 0);
            $destructive = $status['schema_drift']['destructive'] ?? [];
        } catch (\Throwable) {
            // If even reading status fails, the schema is presumably very
            // broken — keep the redirect to give the user a recovery path.
            $pending = 1;
            $drift = 0;
            $destructive = [];
        }
        if ($pending === 0 && $drift === 0) {
            return;
        }

        // Auto-fix additive-only drift transparently when the failing request
        // hits a schema-mismatch (e.g. ALTER TABLE ADD COLUMN never ran on
        // this deployment). Recursion guard via query-flag so a second hit
        // doesn't loop.
        $alreadyTried = $request->query->getBoolean('_schema_autofixed');
        $additiveOnly = $destructive === [];
        if (!$alreadyTried) {
            try {
                $totalApplied = 0;

                // Step 1: run pending Doctrine migrations first. reconcileSchema
                // gates itself when pending migrations exist, so we MUST drain
                // them before reconcile can patch any residual entity-vs-DB
                // drift.
                if ($pending > 0) {
                    $migResult = $this->maintenance->executePendingMigrations('auto-fix-on-error');
                    if ($migResult['success'] ?? false) {
                        $totalApplied += (int) ($migResult['executed'] ?? 0);
                    }
                }

                // Step 2: reconcile additive-only drift (ALTER TABLE ADD …).
                // Skipped when destructive drift exists — those need manual
                // review via Quick-Fix UI.
                if ($additiveOnly) {
                    $recResult = $this->maintenance->reconcileSchema('auto-fix-on-error');
                    if ($recResult['success'] ?? false) {
                        $totalApplied += (int) ($recResult['executed'] ?? 0);
                    }
                }

                if ($totalApplied > 0) {
                    $retry = $request->getRequestUri();
                    $sep = str_contains($retry, '?') ? '&' : '?';
                    $event->setResponse(new RedirectResponse($retry . $sep . '_schema_autofixed=1'));
                    return;
                }
            } catch (\Throwable) {
                // fall through to /quick-fix redirect
            }
        }

        // Auto-fix exhausted (destructive drift, already retried, or reconcile
        // failed). Bubble the exception when we're already on /quick-fix so
        // Symfony renders the standard error page instead of redirecting in
        // a loop. Otherwise hand off to the Quick-Fix UI for manual review.
        if (str_starts_with($path, '/quick-fix')) {
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
