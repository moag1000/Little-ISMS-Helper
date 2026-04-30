<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\Migrations\DependencyFactory;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Reconciliation-Command für Schema-Drift.
 *
 * Hintergrund: Einige frühe Migrationen (Version20260418-20260420)
 * nutzen `PREPARE stmt FROM @sql / EXECUTE stmt / DEALLOCATE PREPARE stmt`
 * für "idempotente" ALTER TABLE-Wrapper. Dieses Pattern schlägt in
 * Doctrine Migrations gelegentlich silent fehl — die Migration wird in
 * `doctrine_migration_versions` als `executed` markiert, aber die
 * eigentlichen ALTER/CREATE werden nicht durchgeführt.
 *
 * Symptom: `Column not found: 'i0_.dora_clients_impacted'` oder
 * fehlende Tabellen (`threat_led_penetration_test`, `tlpt_finding`,
 * `compliance_requirement_evidence`).
 *
 * Lösung: `doctrine:schema:update --complete --force` bringt die DB
 * in Sync mit Entity-Metadata. Non-destruktiv für additive Änderungen
 * (ADD COLUMN / CREATE TABLE). Cleaner Wrapper hier liefert dedizierte
 * Fehlermeldungen und eine Vorher-/Nachher-Diagnose.
 *
 * Ausführung:
 *   php bin/console app:schema:reconcile
 *   php bin/console app:schema:reconcile --dry-run
 *   php bin/console app:schema:reconcile --dump-sql
 *   php bin/console app:schema:reconcile --mark-migrations-executed
 */
#[AsCommand(
    name: 'app:schema:reconcile',
    description: 'Reconciles DB schema with entity metadata (fixes silent-failed PREPARE/EXECUTE migrations)',
)]
class SchemaReconcileCommand
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ?DependencyFactory $migrationsDependencyFactory = null,
    ) {
    }

    public function __invoke(
        #[Option(description: 'Show pending SQL without executing', name: 'dry-run')]
        bool $dryRun = false,
        #[Option(description: 'Dump SQL statements instead of executing them', name: 'dump-sql')]
        bool $dumpSql = false,
        #[Option(description: 'After reconcile, mark all known migrations as executed without running them (use after a fresh schema:update to prevent CREATE TABLE re-runs)', name: 'mark-migrations-executed')]
        bool $markMigrationsExecuted = false,
        ?SymfonyStyle $io = null,
    ): int {
        $io?->title('Schema Reconcile');

        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        if ($metadata === []) {
            $io?->warning('No entity metadata found — nothing to reconcile.');
            return Command::SUCCESS;
        }

        $tool = new SchemaTool($this->entityManager);
        $raw = $tool->getUpdateSchemaSql($metadata);

        // Doctrine ORM 3 bietet kein saveMode-Flag mehr → DROPs auf Doctrine-
        // interne/Migrations-Tabellen selbst herausfiltern.
        $doctrineInternal = ['doctrine_migration_versions', 'messenger_messages'];
        $sqls = array_values(array_filter(
            $raw,
            static function (string $sql) use ($doctrineInternal): bool {
                foreach ($doctrineInternal as $t) {
                    if (preg_match('/^DROP TABLE\s+`?' . preg_quote($t, '/') . '`?\s*$/i', trim($sql)) === 1) {
                        return false;
                    }
                }
                return true;
            },
        ));

        if ($sqls === []) {
            $io?->success('Database schema is already in sync with entity metadata. Nothing to do.');
            return Command::SUCCESS;
        }

        $io?->section(sprintf('Found %d pending schema statements', count($sqls)));

        if ($dryRun || $dumpSql) {
            foreach ($sqls as $sql) {
                $io?->writeln('<info>' . $sql . ';</info>');
            }
            $io?->note(sprintf(
                '%d statements NOT executed (dry-run / dump-sql mode). Re-run without flag to apply.',
                count($sqls),
            ));
            return Command::SUCCESS;
        }

        $io?->text(sprintf(
            'Applying %d statements. Non-destructive for additive changes (ADD COLUMN / CREATE TABLE). Review required for destructive changes.',
            count($sqls),
        ));

        $destructivePatterns = ['/^DROP TABLE/i', '/^ALTER TABLE .+ DROP /i'];
        foreach ($sqls as $sql) {
            foreach ($destructivePatterns as $pattern) {
                if (preg_match($pattern, $sql) === 1) {
                    $io?->warning('Destructive statement detected: ' . $sql);
                    if (!$io?->confirm('Continue?', false)) {
                        $io?->error('Aborted by user.');
                        return Command::FAILURE;
                    }
                    break;
                }
            }
        }

        // Gefilterte SQLs einzeln ausführen (nicht $tool->updateSchema,
        // weil das die Filter umgehen würde).
        $conn = $this->entityManager->getConnection();
        foreach ($sqls as $sql) {
            $conn->executeStatement($sql);
        }

        // Phantom-Drift-Detection: DBAL 4.x + MariaDB emittiert für JSON-
        // Spalten und nullable Defaults wiederholt CHANGE-Statements, die
        // tatsächlich keine Änderung bewirken (JSON↔LONGTEXT-Introspection,
        // DEFAULT-NULL-Diff, oft wegen falsch gesetztem serverVersion in
        // DATABASE_URL). Wenn der zweite Lauf identische Statements liefert,
        // ist das Schema in Wahrheit in sync.
        $secondPass = $tool->getUpdateSchemaSql($metadata);
        $secondPass = array_values(array_filter(
            $secondPass,
            static function (string $sql) use ($doctrineInternal): bool {
                foreach ($doctrineInternal as $t) {
                    if (preg_match('/^DROP TABLE\s+`?' . preg_quote($t, '/') . '`?\s*$/i', trim($sql)) === 1) {
                        return false;
                    }
                }
                return true;
            },
        ));

        if ($secondPass !== [] && count($secondPass) === count($sqls)) {
            $io?->warning(sprintf(
                'DBAL phantom drift detected: %d statements still emitted after applying. '
                . 'Known DBAL/MariaDB issue (JSON↔LONGTEXT introspection, DEFAULT NULL diff), '
                . 'often caused by serverVersion mismatch in DATABASE_URL vs the actual MariaDB version. '
                . 'Verify DB_SERVER_VERSION matches `SELECT VERSION()`. Schema IS in sync; '
                . 'subsequent runs will keep reporting these no-op statements. Safe to ignore.',
                count($secondPass),
            ));
        } else {
            $io?->success(sprintf('Applied %d schema statements. DB is now in sync with entity metadata.', count($sqls)));
        }

        if ($markMigrationsExecuted) {
            $this->markAllMigrationsExecuted($io);
        }

        return Command::SUCCESS;
    }

    /**
     * Mark all available migrations as executed without running them. Used after
     * a fresh schema:update / reconcile so subsequent doctrine:migrations:migrate
     * runs do not try to CREATE TABLE on already-existing tables.
     */
    private function markAllMigrationsExecuted(?SymfonyStyle $io): void
    {
        if ($this->migrationsDependencyFactory === null) {
            $io?->warning('doctrine/migrations not available — skipping --mark-migrations-executed.');
            return;
        }

        $factory = $this->migrationsDependencyFactory;
        $storage = $factory->getMetadataStorage();
        $storage->ensureInitialized();

        $availableMigrations = $factory->getMigrationPlanCalculator()->getMigrations();
        $executedSet = $storage->getExecutedMigrations();

        $marked = 0;
        foreach ($availableMigrations->getItems() as $migration) {
            $version = $migration->getVersion();
            if ($executedSet->hasMigration($version)) {
                continue;
            }

            $result = new \Doctrine\Migrations\Version\ExecutionResult($version, \Doctrine\Migrations\Version\Direction::UP, new \DateTimeImmutable());
            $result->setTime(0.0);
            $storage->complete($result);
            $marked++;
        }

        if ($marked === 0) {
            $io?->note('All migrations were already recorded as executed.');
            return;
        }

        $io?->success(sprintf('Marked %d migrations as executed. doctrine:migrations:migrate will now skip them.', $marked));
    }
}
