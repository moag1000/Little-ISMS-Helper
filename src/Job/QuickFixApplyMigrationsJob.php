<?php

declare(strict_types=1);

namespace App\Job;

use App\Service\SchemaMaintenanceService;

/**
 * Async QuickFix: apply pending migrations + auto-chain non-destructive
 * schema reconcile.
 *
 * Mirrors the controller's logic: after migrations land, re-check
 * schema-drift. If non-destructive drift remains, run reconcile in the
 * same job. Destructive drift still requires manual review.
 */
final class QuickFixApplyMigrationsJob implements AsyncJobInterface
{
    public function __construct(
        private readonly SchemaMaintenanceService $schemaMaintenanceService,
    ) {
    }

    public function run(JobContext $ctx): void
    {
        $ctx->message('Applying pending Doctrine migrations…');
        $migrationResult = $this->schemaMaintenanceService->executePendingMigrations('quick-fix');

        if (!$migrationResult['success']) {
            $diagnosis = $migrationResult['diagnosis'] ?? null;
            $message = is_array($diagnosis) && ($diagnosis['category'] ?? 'unknown') !== 'unknown'
                ? sprintf(
                    '[%s] %s — %s',
                    $diagnosis['category'],
                    $diagnosis['message'] ?? '',
                    $diagnosis['suggested_action'] ?? '',
                )
                : 'Migration failed: ' . ((string) ($migrationResult['error'] ?? 'unknown error'));

            // @intentional-assertion: surface migration failure to QuickFix operator
            throw new \RuntimeException($message);
        }

        $executed = (int) ($migrationResult['executed'] ?? 0);

        // Auto-chain: if non-destructive drift remains, reconcile in the same job.
        $reconcileExecuted = 0;
        try {
            $status = $this->schemaMaintenanceService->getMaintenanceStatus();
            $driftCount = (int) ($status['schema_drift']['count'] ?? 0);
            $destructive = $status['schema_drift']['destructive'] ?? [];

            if ($driftCount > 0 && $destructive === []) {
                $ctx->message('Auto-chain reconcile (non-destructive drift only)…');
                $reconcileResult = $this->schemaMaintenanceService->reconcileSchema('quick-fix');
                if ($reconcileResult['success']) {
                    $reconcileExecuted = (int) ($reconcileResult['executed'] ?? 0);
                }
            }
        } catch (\Throwable $e) {
            // Reconcile failure is non-fatal here — the migrations already landed.
            $ctx->message('Auto-chain reconcile skipped: ' . $e->getMessage());
        }

        $summary = sprintf('%d migration(s) applied', $executed);
        if ($reconcileExecuted > 0) {
            $summary .= sprintf(' + %d schema statement(s) reconciled', $reconcileExecuted);
        }

        $ctx->progress($executed, $executed, 'Done. ' . $summary . '.');
    }
}
