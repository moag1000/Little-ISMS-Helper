<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\QuickFixGuard;
use App\Service\SchemaMaintenanceService;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\InvalidFieldNameException;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Doctrine\ORM\Mapping\MappingException as ORMMappingException;
use Doctrine\Persistence\Mapping\MappingException as PersistenceMappingException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
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
        private readonly LoggerInterface $logger = new NullLogger(),
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

        $throwable = $event->getThrowable();
        $isUnknownColumn = $this->isUnknownColumnException($throwable);

        $this->logger->warning('SchemaExceptionSubscriber: schema exception caught', [
            'path' => $path,
            'exception' => $throwable::class,
            'message' => $throwable->getMessage(),
            'is_unknown_column' => $isUnknownColumn,
        ]);

        // Guard against false-positives: only act when there are actually
        // pending Doctrine migrations OR a schema drift between entity
        // metadata and the live DB. A "table not found" error can also come
        // from a typo in raw SQL, which has nothing to do with an out-of-date
        // schema — those should bubble up as a normal 500 with the original
        // stack trace, not a misleading "apply migrations" page.
        //
        // EXCEPTION (Pitfall #6): When the exception is an "Unknown column" /
        // InvalidFieldNameException, the migration may have been MARKED as
        // executed in doctrine_migration_versions while the actual DDL never
        // ran (legacy PREPARE/EXECUTE pattern). In that case both pending and
        // drift counters report 0 despite the live DB missing columns. We MUST
        // NOT gate on those counters for this class of error — always proceed
        // to auto-fix and redirect.
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
            if (!$isUnknownColumn) {
                // No known drift in either direction and not an unknown-column
                // hit — the exception is most likely a bug in raw SQL, not a
                // schema-sync problem. Let it bubble as a normal 500.
                return;
            }

            // phantom_diff_state: migration marked executed but DDL never ran.
            // Extract the offending column name for the log.
            $columnName = $this->extractUnknownColumnName($throwable);
            $this->logger->warning('SchemaExceptionSubscriber: phantom_diff_state — column missing despite migration marked executed', [
                'path' => $path,
                'missing_column' => $columnName,
                'exception' => $throwable::class,
                'hint' => 'Migration likely used PREPARE/EXECUTE pattern (CLAUDE.md Pitfall #6) or was marked executed without running DDL.',
            ]);

            // Attempt a direct entity-metadata vs. live-DB reconcile, bypassing
            // the migrations table (the reconcile path reads information_schema,
            // not doctrine_migration_versions).
            try {
                $entityDrift = $this->maintenance->getEntityVsDbDrift();
                $drift = count($entityDrift);
                // Treat all entity-drift as additive for auto-fix purposes;
                // destructive stays empty so auto-fix runs reconcile below.
            } catch (\Throwable $e) {
                $this->logger->warning('SchemaExceptionSubscriber: getEntityVsDbDrift threw', [
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
                // Force drift > 0 so we proceed to auto-fix attempt.
                $drift = 1;
            }
        }

        // Auto-fix additive-only drift transparently when the failing request
        // hits a schema-mismatch (e.g. ALTER TABLE ADD COLUMN never ran on
        // this deployment). Recursion guard via query-flag so a second hit
        // doesn't loop.
        $alreadyTried = $request->query->getBoolean('_schema_autofixed');
        $additiveOnly = $destructive === [];
        $this->logger->warning('SchemaExceptionSubscriber: maintenance status', [
            'pending' => $pending,
            'drift' => $drift,
            'destructive' => count($destructive),
            'already_tried' => $alreadyTried,
        ]);

        if (!$alreadyTried) {
            try {
                $totalApplied = 0;
                $migResult = null;
                $recResult = null;

                // Step 1: run pending Doctrine migrations first. reconcileSchema
                // gates itself when pending migrations exist, so we MUST drain
                // them before reconcile can patch any residual entity-vs-DB
                // drift. Always attempt — even when pending=0 — because the
                // migrations table may be marked-executed while the actual DDL
                // never ran (Pitfall #6 in CLAUDE.md).
                $migResult = $this->maintenance->executePendingMigrations('auto-fix-on-error');
                if ($migResult['success'] ?? false) {
                    $totalApplied += (int) ($migResult['executed'] ?? 0);
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

                $this->logger->warning('SchemaExceptionSubscriber: auto-fix attempted', [
                    'total_applied' => $totalApplied,
                    'migrations_result' => $migResult,
                    'reconcile_result' => $recResult,
                ]);

                if ($totalApplied > 0) {
                    $retry = $request->getRequestUri();
                    $sep = str_contains($retry, '?') ? '&' : '?';
                    $event->setResponse(new RedirectResponse($retry . $sep . '_schema_autofixed=1'));
                    return;
                }
            } catch (\Throwable $e) {
                $this->logger->error('SchemaExceptionSubscriber: auto-fix threw', [
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
                // fall through to /quick-fix redirect
            }
        }

        // Final fallback for InvalidFieldNameException when the standard auto-fix
        // (migrations + reconcile) did not help: attempt forceSchemaUpdate() which
        // runs doctrine:schema:update --force (saveMode=true). This covers the
        // case where the column really is missing from the DB and neither the
        // migrations table nor SchemaTool reports drift. Recursion guard via
        // _schema_force_updated query param — only one attempt per request chain.
        $alreadyForced = $request->query->getBoolean('_schema_force_updated');
        if ($isUnknownColumn && !$alreadyForced && !str_starts_with($path, '/quick-fix')) {
            try {
                $forceResult = $this->maintenance->forceSchemaUpdate('auto-fix-on-error');
                $this->logger->warning('SchemaExceptionSubscriber: forceSchemaUpdate attempted', [
                    'success' => $forceResult['success'],
                    'statements_executed' => $forceResult['statements_executed'] ?? 0,
                    'error' => $forceResult['error'] ?? null,
                ]);
                if (($forceResult['success'] ?? false) && ($forceResult['statements_executed'] ?? 0) > 0) {
                    $retry = $request->getRequestUri();
                    $sep = str_contains($retry, '?') ? '&' : '?';
                    $event->setResponse(new RedirectResponse($retry . $sep . '_schema_force_updated=1'));
                    return;
                }
            } catch (\Throwable $e) {
                $this->logger->error('SchemaExceptionSubscriber: forceSchemaUpdate threw', [
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
                // fall through to /quick-fix redirect
            }
        }

        // Auto-fix exhausted (destructive drift, already retried, or reconcile
        // failed). Always redirect to /quick-fix when the exception is a
        // schema-drift signal — regardless of what the migrations table says.
        // This prevents phantom-diff-state (Pitfall #6) from silently rendering
        // a bare 500 when pending=0 and drift=0 but DDL never actually ran.
        //
        // Exception: already on /quick-fix — bubble the exception so Symfony
        // renders the error page instead of looping. For unknown-column hits
        // on /quick-fix itself, the operator will see the error in context.
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
                || $current instanceof InvalidFieldNameException
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

    /**
     * Returns true when the exception chain contains an "Unknown column" /
     * InvalidFieldNameException hit — the phantom-diff-state indicator.
     * SQLSTATE 1054 (HY000 / 42S22) is the MySQL error code for this.
     */
    private function isUnknownColumnException(\Throwable $throwable): bool
    {
        $current = $throwable;
        while ($current !== null) {
            if ($current instanceof InvalidFieldNameException) {
                return true;
            }
            if ($current instanceof DriverException) {
                $message = $current->getMessage();
                if (
                    str_contains($message, 'Unknown column')
                    || str_contains($message, 'no such column')
                ) {
                    return true;
                }
            }
            $current = $current->getPrevious();
        }
        return false;
    }

    /**
     * Extracts the offending column name from an "Unknown column" exception
     * message using regex. Returns null when the pattern does not match.
     *
     * Matches both MySQL format:
     *   Unknown column 't0.in_app_notifications_enabled' in 'SELECT'
     * and SQLite format:
     *   no such column: t0.in_app_notifications_enabled
     */
    private function extractUnknownColumnName(\Throwable $throwable): ?string
    {
        $current = $throwable;
        while ($current !== null) {
            $message = $current->getMessage();
            // MySQL / MariaDB: Unknown column 'table.col' in '...'
            if (preg_match("/Unknown column ['\"]([^'\"]+)['\"]/i", $message, $m)) {
                return $m[1];
            }
            // SQLite: no such column: table.col
            if (preg_match('/no such column:\s*(\S+)/i', $message, $m)) {
                return $m[1];
            }
            $current = $current->getPrevious();
        }
        return null;
    }
}
