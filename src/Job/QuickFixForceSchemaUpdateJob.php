<?php

declare(strict_types=1);

namespace App\Job;

use App\Service\SchemaMaintenanceService;
use App\Service\SchemaSnapshotService;

/**
 * Async QuickFix: nuclear fallback — doctrine:schema:update --force in
 * saveMode (only ADD/CREATE, never DROP).
 *
 * Last-resort option when reconcile still fails after the FK-check envelope
 * is applied. The controller already gates this behind a confirmation
 * checkbox; the job assumes the operator has explicitly opted in.
 */
final class QuickFixForceSchemaUpdateJob implements AsyncJobInterface
{
    public function __construct(
        private readonly SchemaMaintenanceService $schemaMaintenanceService,
        private readonly SchemaSnapshotService $snapshotService,
    ) {
    }

    public function run(JobContext $ctx): void
    {
        $snap = $this->snapshotService->snapshot('force-schema-update');
        if ($snap['warning'] !== null) {
            $ctx->message('WARN: ' . $snap['warning']);
        } else {
            $ctx->message(sprintf('Snapshot saved (%s).', $snap['method']));
        }

        $ctx->message('Forcing schema update (additive only — never drops)…');

        $result = $this->schemaMaintenanceService->forceSchemaUpdate('quick-fix');

        if (($result['blocked'] ?? null) === 'locked') {
            throw new \RuntimeException('Another schema operation is already running. Try again shortly.');
        }

        if (!$result['success']) {
            // @intentional-assertion: surface force-schema-update failure to operator
            throw new \RuntimeException(sprintf(
                'Force schema update failed: %s',
                (string) ($result['error'] ?? 'unknown error'),
            ));
        }

        $executed = (int) ($result['statements_executed'] ?? 0);
        $ctx->progress(
            $executed,
            $executed,
            sprintf('Done. %d schema statement(s) applied.', $executed),
        );
    }
}
