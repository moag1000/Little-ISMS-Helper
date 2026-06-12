<?php

declare(strict_types=1);

namespace App\Job;

use App\Service\SchemaMaintenanceService;
use App\Service\SchemaSnapshotService;

/**
 * Async QuickFix: schema reconcile with auto-recovery for pending migrations.
 *
 * If the first reconcile is blocked by pending_migrations, this job tries
 * applying them first via Doctrine's MigrationPlanCalculator and then
 * retries reconcile with bypassMigrationGate=true.
 *
 * Args:
 *   confirmDestructive (bool) — operator ticked the risk-acceptance checkbox.
 *
 * Destructive statements (DROP/TRUNCATE) are detected before dispatch by the
 * controller; the job assumes either no destructive drift exists or the
 * operator already confirmed.
 */
final class QuickFixReconcileSchemaJob implements AsyncJobInterface
{
    public function __construct(
        private readonly SchemaMaintenanceService $schemaMaintenanceService,
        private readonly SchemaSnapshotService $snapshotService,
    ) {
    }

    public function run(JobContext $ctx): void
    {
        $snap = $this->snapshotService->snapshot('reconcile-schema');
        if ($snap['warning'] !== null) {
            $ctx->message('WARN: ' . $snap['warning']);
        } else {
            $ctx->message(sprintf('Snapshot saved (%s).', $snap['method']));
        }

        $ctx->message('Reconciling schema against entity metadata…');

        $result = $this->schemaMaintenanceService->reconcileSchema('quick-fix');

        if (($result['blocked'] ?? null) === 'locked') {
            throw new \RuntimeException('Another schema operation is already running. Try again shortly.');
        }

        // Auto-recovery: if blocked by pending_migrations, try to apply them first.
        if (!$result['success'] && ($result['blocked'] ?? null) === 'pending_migrations') {
            $ctx->message('Reconcile blocked by pending migrations — applying them first…');
            $applyResult = $this->schemaMaintenanceService->executePendingMigrations('quick-fix');
            if ($applyResult['success']) {
                $ctx->message('Migrations applied — retrying reconcile (bypass gate)…');
                $result = $this->schemaMaintenanceService->reconcileSchema('quick-fix', true);
            } else {
                $diagnosis = $applyResult['diagnosis'] ?? null;
                $diagMsg = (is_array($diagnosis) && ($diagnosis['category'] ?? 'unknown') !== 'unknown')
                    ? sprintf('[%s] %s', $diagnosis['category'], $diagnosis['suggested_action'] ?? $diagnosis['message'] ?? '')
                    : ((string) ($applyResult['error'] ?? 'unknown error'));

                // Async-job split — stash structured diagnosis on the
                // job-status payload so the QuickFix index can render the
                // same recovery card as ApplyMigrations
                // (mark-as-executed, repair-all, …).
                if (is_array($diagnosis) && isset($diagnosis['offending_version'])) {
                    $ctx->updatePayload([
                        'apply_failure' => [
                            'version' => (string) $diagnosis['offending_version'],
                            'category' => (string) ($diagnosis['category'] ?? 'unknown'),
                            'message' => (string) ($diagnosis['message'] ?? ''),
                            'suggested_action' => (string) ($diagnosis['suggested_action'] ?? ''),
                        ],
                    ]);
                }

                // @intentional-assertion: surface auto-recovery failure to operator
                throw new \RuntimeException(sprintf(
                    'Reconcile blocked: pending migration(s) — apply failed: %s',
                    $diagMsg,
                ));
            }
        }

        if (!$result['success']) {
            // Pure reconcile failure (no auto-recovery path). Surface the
            // blocked-reason for the index page so the operator sees more
            // than just "unknown error".
            $ctx->updatePayload([
                'reconcile_failure' => [
                    'error' => (string) ($result['error'] ?? ''),
                    'blocked' => (string) ($result['blocked'] ?? ''),
                ],
            ]);

            // @intentional-assertion: surface reconcile failure to operator
            throw new \RuntimeException(sprintf(
                'Schema reconcile failed: %s',
                (string) ($result['error'] ?? ($result['blocked'] ?? 'unknown error')),
            ));
        }

        $executed = (int) ($result['executed'] ?? 0);
        $autoMarked = count($result['auto_marked'] ?? []);

        $ctx->progress(
            $executed,
            $executed,
            sprintf(
                'Done. %d schema statement(s) applied%s.',
                $executed,
                $autoMarked > 0 ? sprintf(' + %d migration(s) auto-marked', $autoMarked) : '',
            ),
        );
    }
}
