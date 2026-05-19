<?php

declare(strict_types=1);

namespace App\Job;

use App\Service\SchemaMaintenanceService;

/**
 * Async admin job: execute every pending Doctrine migration.
 *
 * Wraps SchemaMaintenanceService::executePendingMigrations() so a long
 * migration chain (e.g. 20+ pending) no longer hits PHP-FPM's 30 s wall.
 *
 * Args:
 *   actor (string) — operator email or 'quick-fix' for audit trail
 *
 * Result envelope from the service is rendered into the status message;
 * a non-zero `executed` count is success, while `diagnosis` carries the
 * recovery hint when the run failed mid-chain.
 */
final class ExecutePendingMigrationsJob implements AsyncJobInterface
{
    public function __construct(
        private readonly SchemaMaintenanceService $schemaMaintenanceService,
    ) {
    }

    public function run(JobContext $ctx): void
    {
        $actor = (string) $ctx->arg('actor', 'admin');

        $ctx->message('Applying pending Doctrine migrations…');

        $result = $this->schemaMaintenanceService->executePendingMigrations($actor);

        if ($result['success']) {
            $executed = (int) ($result['executed'] ?? 0);
            $ctx->progress(
                $executed,
                $executed,
                sprintf('Done. %d migration(s) applied.', $executed),
            );
            return;
        }

        $diagnosis = $result['diagnosis'] ?? null;
        if (is_array($diagnosis) && ($diagnosis['category'] ?? 'unknown') !== 'unknown') {
            // @intentional-assertion: surface diagnosis to operator via failed status
            throw new \RuntimeException(sprintf(
                '[%s] %s — %s%s',
                $diagnosis['category'],
                $diagnosis['message'] ?? '',
                $diagnosis['suggested_action'] ?? '',
                isset($diagnosis['offending_version']) && $diagnosis['offending_version'] !== null
                    ? sprintf(' (offending: %s)', $diagnosis['offending_version'])
                    : '',
            ));
        }

        // @intentional-assertion: surface generic migration failure to operator
        throw new \RuntimeException(
            'Migration run failed: ' . (string) ($result['error'] ?? 'unknown error'),
        );
    }
}
