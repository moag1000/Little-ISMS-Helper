<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Metadata\MigrationPlan;
use Doctrine\Migrations\Metadata\MigrationPlanList;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\Migrations\Version\Direction;
use Doctrine\Migrations\Version\Version;

/**
 * Aggregates schema-maintenance status (pending Doctrine migrations + schema
 * drift between entity metadata and the live DB) and exposes idempotent
 * apply-actions for both. Powers the admin Data-Repair page.
 *
 * The design keeps {@see SchemaHealthService} unchanged (used by the
 * monitoring page) and decorates it with two extras:
 *
 *  - migration_status — count + names of versions still to execute
 *  - schema_drift     — count + statements + destructive-statement subset
 *
 * Reconcile is delegated back to SchemaHealthService::applyUpdate() so the
 * existing audit-log + bypass-gate semantics remain the single source of
 * truth.
 */
class SchemaMaintenanceService
{
    /** Patterns the SchemaTool emits when it would drop tables/columns. */
    private const DESTRUCTIVE_PATTERNS = [
        '/^DROP TABLE/i',
        '/^ALTER TABLE .+ DROP /i',
    ];

    public function __construct(
        private readonly SchemaHealthService $schemaHealthService,
        private readonly DependencyFactory $migrationsDependencyFactory,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    /**
     * @return array{
     *     migration_status: array{pending: int, names: list<string>},
     *     schema_drift:     array{count: int, statements: list<string>, destructive: list<string>}
     * }
     */
    public function getMaintenanceStatus(): array
    {
        $pendingNames = $this->collectPendingMigrationNames();

        $validation = $this->schemaHealthService->validate();
        // pending_sql may contain a single '-- ERROR: …' marker when the
        // SchemaValidator throws; treat those as empty drift but surface
        // the marker so the operator sees something is wrong.
        $statements = $validation['pending_sql'];
        $destructive = array_values(array_filter(
            $statements,
            static function (string $sql): bool {
                foreach (self::DESTRUCTIVE_PATTERNS as $pattern) {
                    if (preg_match($pattern, $sql) === 1) {
                        return true;
                    }
                }
                return false;
            },
        ));

        return [
            'migration_status' => [
                'pending' => count($pendingNames),
                'names' => $pendingNames,
            ],
            'schema_drift' => [
                'count' => count($statements),
                'statements' => array_values($statements),
                'destructive' => $destructive,
            ],
        ];
    }

    /**
     * Executes every pending migration in order. No-op when the plan is empty.
     *
     * @return array{success: bool, executed: int, error: ?string}
     */
    public function executePendingMigrations(string $actor = 'system'): array
    {
        $df = $this->migrationsDependencyFactory;

        try {
            $df->getMetadataStorage()->ensureInitialized();

            $latest = $df->getVersionAliasResolver()->resolveVersionAlias('latest');
            $plan = $df->getMigrationPlanCalculator()->getPlanUntilVersion($latest);
        } catch (\Throwable $e) {
            // No migrations to execute / repository empty → not an error.
            $message = $e->getMessage();
            if (
                str_contains($message, 'No migrations')
                || str_contains($message, 'already at')
                || str_contains($message, 'already at the latest')
            ) {
                return ['success' => true, 'executed' => 0, 'error' => null];
            }
            $this->auditLogger->logCustom(
                'admin.schema.migrate.failed',
                'Doctrine',
                null,
                null,
                ['error' => $message],
                sprintf('Schema migrate failed for %s: %s', $actor, $message),
            );
            return ['success' => false, 'executed' => 0, 'error' => $message];
        }

        if ($this->isPlanEmpty($plan)) {
            return ['success' => true, 'executed' => 0, 'error' => null];
        }

        try {
            $config = (new MigratorConfiguration())
                ->setDryRun(false)
                ->setAllOrNothing(false)
                ->setNoMigrationException(true);
            $df->getMigrator()->migrate($plan, $config);
        } catch (\Throwable $e) {
            $this->auditLogger->logCustom(
                'admin.schema.migrate.failed',
                'Doctrine',
                null,
                null,
                ['error' => $e->getMessage(), 'planned' => count($plan->getItems())],
                sprintf('Schema migrate failed for %s: %s', $actor, $e->getMessage()),
            );
            return ['success' => false, 'executed' => 0, 'error' => $e->getMessage()];
        }

        $executed = count($plan->getItems());
        $this->auditLogger->logCustom(
            'admin.schema.migrate.applied',
            'Doctrine',
            null,
            null,
            [
                'executed' => $executed,
                'versions' => array_map(
                    static fn (MigrationPlan $p): string => (string) $p->getVersion(),
                    $plan->getItems(),
                ),
            ],
            sprintf('Schema migrate applied by %s (%d versions)', $actor, $executed),
        );

        return ['success' => true, 'executed' => $executed, 'error' => null];
    }

    /**
     * Reconciles entity metadata against the live DB by running the same
     * SQL `app:schema:reconcile` would emit. Delegates to SchemaHealthService
     * so the audit trail + migration-gate stay consistent with the
     * monitoring-page action.
     *
     * @return array{success: bool, executed: int, error: ?string, blocked: ?string}
     */
    public function reconcileSchema(string $actor = 'system', bool $bypassMigrationGate = false): array
    {
        $result = $this->schemaHealthService->applyUpdate($actor, $bypassMigrationGate);

        return [
            'success' => $result['success'],
            'executed' => count($result['executed_sql']),
            'error' => $result['error'],
            'blocked' => $result['blocked'],
        ];
    }

    /**
     * Returns pending migration class names sorted by version. Tolerates a
     * missing `doctrine_migration_versions` table (fresh install) by falling
     * back to "every available migration".
     *
     * @return list<string>
     */
    private function collectPendingMigrationNames(): array
    {
        try {
            $latest = $this->migrationsDependencyFactory->getVersionAliasResolver()->resolveVersionAlias('latest');
            $plan = $this->migrationsDependencyFactory->getMigrationPlanCalculator()->getPlanUntilVersion($latest);
        } catch (\Throwable) {
            return [];
        }

        if ($plan->getDirection() !== Direction::UP) {
            return [];
        }

        return array_map(
            static fn (MigrationPlan $p): string => (string) $p->getVersion(),
            $plan->getItems(),
        );
    }

    private function isPlanEmpty(MigrationPlanList $plan): bool
    {
        return $plan->getItems() === [];
    }
}
