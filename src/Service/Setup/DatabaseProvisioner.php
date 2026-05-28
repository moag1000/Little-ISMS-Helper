<?php

declare(strict_types=1);

namespace App\Service\Setup;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use PDO;
use PDOStatement;
use Throwable;

/**
 * DatabaseProvisioner — extracted from DeploymentWizardController (god-class split).
 *
 * Handles all low-level database provisioning logic during the setup-wizard:
 *   - Fresh schema install via Doctrine SchemaTool (fast batch DDL)
 *   - Legacy migration runner fallback
 *   - Drop-and-recreate helpers (per-table + bulk DROP DATABASE)
 *   - PDO connection factory for pre-Doctrine-config phase
 *   - Docker MySQL credential helper
 *
 * None of these methods belong in a controller — they have no HTTP concerns
 * and carry significant complexity.  The controller delegates to this service
 * and interprets the returned result arrays.
 */
final class DatabaseProvisioner
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    /**
     * Fresh-Install Schema-Create — way faster than running all migrations sequentially.
     *
     * Uses Doctrine SchemaTool to generate the schema from current entity metadata
     * (single batch SQL exec, ~1-2 s vs. 30-60 s for a migration loop). After
     * creation, marks every migration version as executed so future `migrate` calls
     * skip them.
     *
     * Trade-off: data-seed INSERTs from older migrations are NOT replayed.  The
     * setup-wizard fills those defaults in subsequent steps (admin-user, organisation,
     * frameworks, base-data) — fresh-install convergence is correct.
     *
     * @return array{success: bool, message: string, output?: string, timings?: array<string, mixed>}
     */
    public function runFreshSchemaInstall(): array
    {
        $timings = [];
        $t0 = microtime(true);
        try {
            $em = $this->entityManager;
            $metadata = $em->getMetadataFactory()->getAllMetadata();
            $timings['metadata_ms'] = (int) round((microtime(true) - $t0) * 1000);

            if ($metadata === []) {
                return ['success' => false, 'message' => 'No entity metadata found — Doctrine not configured?'];
            }

            $schemaTool = new \Doctrine\ORM\Tools\SchemaTool($em);
            $connection = $em->getConnection();
            $platform = $connection->getDatabasePlatform()::class;
            $isMysql = stripos($platform, 'MySQL') !== false || stripos($platform, 'MariaDB') !== false;
            $isPostgres = stripos($platform, 'PostgreSQL') !== false;

            // ALL DDL goes through raw PDO (getNativeConnection) — Doctrine's
            // wrapped Connection tracks transaction nesting in its own state.
            // MySQL DDL implicitly commits any active transaction, breaking
            // Doctrine's tx-tracker → "There is no active transaction" on
            // subsequent calls. Bypassing the wrapper for the DDL phase
            // sidesteps that whole issue.
            $nativeConn = $connection->getNativeConnection();
            if (!$nativeConn instanceof PDO) {
                // Unsupported driver — fall back to Doctrine SchemaTool.
                $schemaTool->dropSchema($metadata);
                $schemaTool->createSchema($metadata);
                return [
                    'success' => true,
                    'message' => 'Schema created via SchemaTool (non-PDO driver)',
                ];
            }

            // Drop existing app-tables (skip doctrine_migration_versions to
            // preserve migration history).
            if ($isMysql) {
                $stmt = $nativeConn->query('SHOW TABLES');
                $existingTables = $stmt instanceof PDOStatement
                    ? $stmt->fetchAll(PDO::FETCH_COLUMN)
                    : [];
                $existingAppTables = array_filter(
                    $existingTables,
                    fn(string $t): bool => $t !== 'doctrine_migration_versions'
                );

                if ($existingAppTables !== []) {
                    // Bulk-drop strategy: prefer DROP DATABASE / CREATE DATABASE
                    // over per-table DROP — MariaDB/MySQL serialises each
                    // DROP TABLE through innodb_flush_log_at_trx_commit, so
                    // 125 DROPs become 125 fsyncs (~15 s on a typical SSD).
                    // DROP DATABASE collapses that to two statements that
                    // execute in milliseconds. Falls back to the per-table
                    // loop if the user lacks DROP/CREATE DATABASE privileges
                    // (managed-DB scenarios).
                    $tDrop = microtime(true);
                    $dbName = (string) $nativeConn->query('SELECT DATABASE()')->fetchColumn();
                    $dropMode = 'per_table';
                    $charset = (string) ($nativeConn->query("SELECT @@character_set_database")->fetchColumn() ?: 'utf8mb4');
                    $collation = (string) ($nativeConn->query("SELECT @@collation_database")->fetchColumn() ?: 'utf8mb4_unicode_ci');
                    if ($dbName !== '') {
                        try {
                            $nativeConn->exec(sprintf('DROP DATABASE `%s`', str_replace('`', '', $dbName)));
                            $nativeConn->exec(sprintf(
                                'CREATE DATABASE `%s` CHARACTER SET %s COLLATE %s',
                                str_replace('`', '', $dbName),
                                $charset,
                                $collation
                            ));
                            $nativeConn->exec(sprintf('USE `%s`', str_replace('`', '', $dbName)));
                            $dropMode = 'recreate_db';
                        } catch (Throwable) {
                            // Fall back to per-table drops if DROP DATABASE
                            // is not permitted (managed-DB / user privilege).
                        }
                    }
                    if ($dropMode === 'per_table') {
                        $dropSql = "SET FOREIGN_KEY_CHECKS = 0;\n";
                        foreach ($existingAppTables as $table) {
                            $clean = str_replace('`', '', (string) $table);
                            $dropSql .= "DROP TABLE IF EXISTS `{$clean}`;\n";
                        }
                        $dropSql .= "SET FOREIGN_KEY_CHECKS = 1;\n";
                        $nativeConn->exec($dropSql);
                    }
                    $timings['drop_ms'] = (int) round((microtime(true) - $tDrop) * 1000);
                    $timings['drop_count'] = count($existingAppTables);
                    $timings['drop_mode'] = $dropMode;
                }
            } elseif ($isPostgres) {
                $stmt = $nativeConn->query("SELECT tablename FROM pg_tables WHERE schemaname = 'public'");
                $existingTables = $stmt instanceof PDOStatement
                    ? $stmt->fetchAll(PDO::FETCH_COLUMN)
                    : [];
                $existingAppTables = array_filter(
                    $existingTables,
                    fn(string $t): bool => $t !== 'doctrine_migration_versions'
                );
                if ($existingAppTables !== []) {
                    $dropSql = '';
                    foreach ($existingAppTables as $table) {
                        $clean = str_replace('"', '', (string) $table);
                        $dropSql .= 'DROP TABLE IF EXISTS "' . $clean . '" CASCADE;' . "\n";
                    }
                    $nativeConn->exec($dropSql);
                }
            } else {
                // SQLite or other — Doctrine's dropSchema is fast enough
                $schemaTool->dropSchema($metadata);
            }

            // Generate all CREATE TABLE / ALTER ADD CONSTRAINT SQL from
            // entity metadata, join with semicolons, submit to PDO::exec
            // in ONE call.
            $tSql = microtime(true);
            $createSqls = $schemaTool->getCreateSchemaSql($metadata);
            $timings['create_sql_gen_ms'] = (int) round((microtime(true) - $tSql) * 1000);
            $timings['create_sql_count'] = count($createSqls);
            if ($createSqls !== []) {
                // Disable FK + uniqueness checks for the duration of bulk DDL.
                $relaxedDurability = false;
                if ($isMysql) {
                    try {
                        $nativeConn->exec('SET @bench_old_flush := @@GLOBAL.innodb_flush_log_at_trx_commit');
                        $nativeConn->exec('SET GLOBAL innodb_flush_log_at_trx_commit = 2');
                        $relaxedDurability = true;
                    } catch (Throwable) {
                        // Lack of SUPER privilege — keep default durability.
                    }
                }

                // Munge CREATE TABLE → CREATE TABLE IF NOT EXISTS so the batch is
                // idempotent against background workers that auto-create their own
                // tables in parallel.
                $idempotentSqls = array_map(
                    static function (string $sql): string {
                        return preg_replace(
                            '/^\s*CREATE TABLE(?!\s+IF\s+NOT\s+EXISTS)/i',
                            'CREATE TABLE IF NOT EXISTS',
                            $sql,
                            1,
                        ) ?? $sql;
                    },
                    $createSqls,
                );

                $sqlBatch = '';
                if ($isMysql) {
                    $sqlBatch .= "SET FOREIGN_KEY_CHECKS = 0;\n";
                    $sqlBatch .= "SET UNIQUE_CHECKS = 0;\n";
                }
                $sqlBatch .= implode(";\n", $idempotentSqls);
                if (!str_ends_with(rtrim($sqlBatch), ';')) {
                    $sqlBatch .= ';';
                }
                if ($isMysql) {
                    $sqlBatch .= "\nSET UNIQUE_CHECKS = 1;";
                    $sqlBatch .= "\nSET FOREIGN_KEY_CHECKS = 1;";
                }
                $tExec = microtime(true);
                try {
                    $nativeConn->exec($sqlBatch);
                } finally {
                    if ($relaxedDurability) {
                        try {
                            $nativeConn->exec('SET GLOBAL innodb_flush_log_at_trx_commit = IFNULL(@bench_old_flush, 1)');
                        } catch (Throwable) {
                            // Best-effort; the session will end soon anyway.
                        }
                    }
                }
                $timings['create_exec_ms'] = (int) round((microtime(true) - $tExec) * 1000);
                $timings['relaxed_durability'] = $relaxedDurability;
            }

            // Mark every migration version as executed so future migrate-calls skip them.
            $tReg = microtime(true);
            $migrationFiles = glob($this->projectDir . '/migrations/Version*.php') ?: [];
            $registered = 0;
            if ($migrationFiles !== []) {
                $nativeConn->exec(
                    'CREATE TABLE IF NOT EXISTS doctrine_migration_versions (version VARCHAR(191) PRIMARY KEY, executed_at DATETIME, execution_time INT)'
                );
                $rows = [];
                foreach ($migrationFiles as $file) {
                    $version = $nativeConn->quote('DoctrineMigrations\\' . basename($file, '.php'));
                    $rows[] = "({$version}, NOW(), 0)";
                }
                $nativeConn->exec(
                    'INSERT IGNORE INTO doctrine_migration_versions (version, executed_at, execution_time) VALUES '
                        . implode(', ', $rows)
                );
                $registered = count($migrationFiles);
            }

            $timings['migrations_register_ms'] = (int) round((microtime(true) - $tReg) * 1000);

            // Force Doctrine to re-establish its connection state — the raw
            // PDO calls above bypassed Doctrine's transaction tracker.
            $connection->close();
            $timings['total_ms'] = (int) round((microtime(true) - $t0) * 1000);

            return [
                'success' => true,
                'message' => sprintf('Schema created from entity metadata (%d migrations marked executed)', $registered),
                'output' => sprintf('Tables created: %d, migrations registered: %d', count($metadata), $registered),
                'timings' => $timings,
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'message' => 'Fresh-install failed: ' . $e->getMessage(),
                'output' => $e->getTraceAsString(),
            ];
        }
    }

    /**
     * Drop and recreate database to ensure clean state.
     * For SQLite: deletes the db file. For MySQL/PgSQL: delegates to truncateAllTables().
     */
    public function dropAndRecreateDatabase(array $config): void
    {
        $type = $config['type'] ?? 'mysql';
        $name = $config['name'] ?? 'little_isms_helper';

        if ($type === 'sqlite') {
            $dbPath = $this->projectDir . "/var/{$name}.db";
            if (file_exists($dbPath)) {
                @unlink($dbPath);
            }
            return;
        }

        // For MySQL/MariaDB and PostgreSQL, directly drop all tables instead of dropping the database
        $this->truncateAllTables($config);
    }

    /**
     * Truncate all tables in database (fallback if DROP DATABASE fails).
     */
    public function truncateAllTables(array $config): void
    {
        try {
            $pdo = $this->connectToDatabaseWithDbName($config);
            $type = $config['type'] ?? 'mysql';

            if ($type === 'postgresql') {
                $stmt = $pdo->query("SELECT tablename FROM pg_tables WHERE schemaname = 'public'");
                $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
                foreach ($tables as $table) {
                    $pdo->exec("TRUNCATE TABLE \"{$table}\" CASCADE");
                }
            } else {
                $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
                $stmt = $pdo->query("SHOW TABLES");
                $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
                foreach ($tables as $table) {
                    $pdo->exec("DROP TABLE IF EXISTS `{$table}`");
                }
                $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
            }
        } catch (\Exception) {
            // Silently fail — migrations will handle it
        }
    }

    /**
     * Create a PDO connection to a specific named database.
     * Used during the setup phase before Doctrine is fully configured.
     */
    public function connectToDatabaseWithDbName(array $config): PDO
    {
        $type = $config['type'] ?? 'mysql';
        $host = $config['host'] ?? 'localhost';
        $port = $config['port'] ?? ($type === 'postgresql' ? 5432 : 3306);
        $name = $config['name'] ?? 'little_isms_helper';
        $user = $config['user'] ?? 'root';
        $password = $config['password'] ?? '';
        $unixSocket = $config['unixSocket'] ?? null;

        if ($type === 'postgresql') {
            $dsn = "pgsql:host={$host};port={$port};dbname={$name}";
        } elseif (!empty($unixSocket)) {
            $dsn = "mysql:unix_socket={$unixSocket};dbname={$name};charset=utf8mb4";
        } else {
            $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
        }

        return new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
        ]);
    }

    /**
     * Get the Docker MySQL password from the auto-generated credentials file.
     * Falls back to the hard-coded default 'isms' if the file is missing.
     */
    public function getDockerMysqlPassword(): string
    {
        $credentialsFile = $this->projectDir . '/var/mysql_credentials.txt';

        if (file_exists($credentialsFile)) {
            $content = file_get_contents($credentialsFile);
            if (preg_match('/password:\s*(.+)/', (string) $content, $matches)) {
                return trim($matches[1]);
            }
        }

        return 'isms';
    }
}
