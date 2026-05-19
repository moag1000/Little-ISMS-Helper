<?php

declare(strict_types=1);

namespace App\Job;

use App\Service\SchemaMaintenanceService;

/**
 * Async admin job: reconcile entity metadata against the live DB schema.
 *
 * Wraps SchemaMaintenanceService::reconcileSchema(). A large drift list
 * (50+ ALTER statements) can easily exceed PHP-FPM's 30 s timeout.
 *
 * Args:
 *   actor               (string) — operator email or 'quick-fix' for audit trail
 *   bypassMigrationGate (bool)   — true when caller already saw pending migrations
 *                                  on the same page (data-repair index UX).
 *
 * On failure (blocked / error) the job is marked failed so the polling UI
 * surfaces the reason via the standard error box.
 */
final class ReconcileSchemaJob implements AsyncJobInterface
{
    public function __construct(
        private readonly SchemaMaintenanceService $schemaMaintenanceService,
    ) {
    }

    public function run(JobContext $ctx): void
    {
        $actor = (string) $ctx->arg('actor', 'admin');
        $bypassGate = (bool) $ctx->arg('bypassMigrationGate', false);

        $ctx->message('Reconciling entity metadata against database schema…');

        $result = $this->schemaMaintenanceService->reconcileSchema($actor, $bypassGate);

        if ($result['blocked'] !== null) {
            // @intentional-assertion: surface block reason via failed status
            throw new \RuntimeException(sprintf(
                'Schema reconcile blocked: %s',
                (string) $result['blocked'],
            ));
        }

        if (!$result['success']) {
            // @intentional-assertion: surface generic reconcile failure to operator
            throw new \RuntimeException(sprintf(
                'Schema reconcile failed: %s',
                (string) ($result['error'] ?? 'unknown error'),
            ));
        }

        $executed = (int) ($result['executed'] ?? 0);
        $autoMarked = count($result['auto_marked'] ?? []);
        $autoMarkFailed = count($result['auto_mark_failed'] ?? []);

        $summary = sprintf('Done. %d statement(s) applied.', $executed);
        if ($autoMarked > 0) {
            $summary .= sprintf(' %d migration(s) auto-marked.', $autoMarked);
        }
        if ($autoMarkFailed > 0) {
            $summary .= sprintf(' %d auto-mark failure(s) — review manually.', $autoMarkFailed);
        }

        $ctx->progress($executed, $executed, $summary);
    }
}
