<?php

namespace App\Service;

use Exception;
use PDO;
use PDOException;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * Service to test database connections during setup.
 *
 * Security considerations:
 * - Only used during setup wizard
 * - Does not expose raw SQL errors to frontend
 * - Sanitizes connection parameters
 */
class DatabaseTestService
{
    public function __construct(
        private readonly ParameterBagInterface $parameterBag
    ) {
    }
    /**
     * Test database connection with given configuration.
     *
     * @param array $config Database configuration
     * @return array Result with 'success' boolean and 'message' string
     */
    public function testConnection(array $config): array
    {
        $type = $config['type'] ?? 'mysql';

        try {
            return match ($type) {
                'sqlite' => $this->testSqliteConnection($config),
                'mysql', 'mariadb' => $this->testMysqlConnection($config),
                'postgresql' => $this->testPostgresqlConnection($config),
                default => [
                    'success' => false,
                    'message' => "Unsupported database type: {$type}",
                ],
            };
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $this->sanitizeErrorMessage($e->getMessage()),
            ];
        }
    }

    /**
     * Test and create database if it doesn't exist.
     *
     * @param array $config Database configuration
     * @return array Result with 'success' boolean and 'message' string
     */
    public function createDatabaseIfNotExists(array $config): array
    {
        $type = $config['type'] ?? 'mysql';

        try {
            return match ($type) {
                'sqlite' => $this->createSqliteDatabase($config),
                'mysql', 'mariadb' => $this->createMysqlDatabase($config),
                'postgresql' => $this->createPostgresqlDatabase($config),
                default => [
                    'success' => false,
                    'message' => "Unsupported database type: {$type}",
                ],
            };
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $this->sanitizeErrorMessage($e->getMessage()),
            ];
        }
    }

    /**
     * Test SQLite connection
     */
    private function testSqliteConnection(array $config): array
    {
        $dbName = $config['name'] ?? 'little_isms_helper';
        $dbPath = $this->parameterBag->get('kernel.project_dir') . "/var/{$dbName}.db";

        try {
            $pdo = new PDO("sqlite:{$dbPath}");
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Test a simple query
            $pdo->query('SELECT 1');

            return [
                'success' => true,
                'message' => 'SQLite connection successful',
            ];
        } catch (PDOException $e) {
            return [
                'success' => false,
                'message' => 'SQLite connection failed: ' . $this->sanitizeErrorMessage($e->getMessage()),
            ];
        }
    }

    /**
     * Test MySQL/MariaDB connection
     */
    private function testMysqlConnection(array $config): array
    {
        $host = $config['host'] ?? 'localhost';
        $port = $config['port'] ?? 3306;
        $user = $config['user'] ?? 'root';
        $password = $config['password'] ?? '';
        $dbName = $config['name'] ?? 'little_isms_helper';
        $unixSocket = $config['unixSocket'] ?? null;

        try {
            // Use Unix socket if explicitly provided
            $useUnixSocket = !empty($unixSocket);

            if ($useUnixSocket) {
                // Unix socket connection
                $dsn = "mysql:unix_socket={$unixSocket};dbname={$dbName};charset=utf8mb4";
            } else {
                // Standard TCP connection
                $dsn = "mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4";
            }

            $pdo = new PDO($dsn, $user, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 5,
            ]);

            // Test a simple query
            $pdo->query('SELECT 1');

            // Detect server version
            $detectedVersion = $this->detectMysqlVersion($pdo);

            return [
                'success' => true,
                'message' => $useUnixSocket ? 'MySQL/MariaDB connection successful (via Unix socket)' : 'MySQL connection successful',
                'detected_version' => $detectedVersion,
            ];
        } catch (PDOException $e) {
            // If database doesn't exist, try connecting without database to detect version
            if (str_contains($e->getMessage(), 'Unknown database')) {
                try {
                    if ($useUnixSocket) {
                        $dsn = "mysql:unix_socket={$unixSocket};charset=utf8mb4";
                    } else {
                        $dsn = "mysql:host={$host};port={$port};charset=utf8mb4";
                    }
                    $pdo = new PDO($dsn, $user, $password, [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_TIMEOUT => 5,
                    ]);
                    $detectedVersion = $this->detectMysqlVersion($pdo);

                    return [
                        'success' => true,
                        'message' => 'Connection successful (database will be created)',
                        'create_needed' => true,
                        'detected_version' => $detectedVersion,
                    ];
                } catch (PDOException) {
                    // Fall through to original error
                }
            }

            return [
                'success' => false,
                'message' => 'MySQL connection failed: ' . $this->sanitizeErrorMessage($e->getMessage()),
            ];
        }
    }

    /**
     * Detect MySQL/MariaDB server version and return Symfony-compatible version string.
     *
     * @return array{type: string, version: string, symfony_version: string, raw: string}
     */
    private function detectMysqlVersion(PDO $pdo): array
    {
        $stmt = $pdo->query('SELECT VERSION()');
        $rawVersion = $stmt->fetchColumn();

        // Check if it's MariaDB (version string contains 'MariaDB')
        $isMariaDb = stripos($rawVersion, 'mariadb') !== false;

        // Extract version number (e.g., "10.11.6" from "10.11.6-MariaDB-1:10.11.6+maria~ubu2204")
        preg_match('/^(\d+\.\d+\.\d+)/', $rawVersion, $matches);
        $versionNumber = $matches[1] ?? '10.6.0';

        // For Symfony DATABASE_URL, MariaDB needs "mariadb-X.X.X" format
        if ($isMariaDb) {
            return [
                'type' => 'mariadb',
                'version' => $versionNumber,
                'symfony_version' => 'mariadb-' . $versionNumber,
                'raw' => $rawVersion,
            ];
        }

        // For MySQL, just use the version number
        return [
            'type' => 'mysql',
            'version' => $versionNumber,
            'symfony_version' => $versionNumber,
            'raw' => $rawVersion,
        ];
    }

    /**
     * Test PostgreSQL connection
     */
    private function testPostgresqlConnection(array $config): array
    {
        $host = $config['host'] ?? 'localhost';
        $port = $config['port'] ?? 5432;
        $user = $config['user'] ?? 'postgres';
        $password = $config['password'] ?? '';
        $dbName = $config['name'] ?? 'little_isms_helper';

        try {
            $dsn = "pgsql:host={$host};port={$port};dbname={$dbName}";
            $pdo = new PDO($dsn, $user, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 5,
            ]);

            // Test a simple query
            $pdo->query('SELECT 1');

            // Detect server version
            $detectedVersion = $this->detectPostgresqlVersion($pdo);

            return [
                'success' => true,
                'message' => 'PostgreSQL connection successful',
                'detected_version' => $detectedVersion,
            ];
        } catch (PDOException $e) {
            // If database doesn't exist, try connecting to postgres database to detect version
            if (str_contains($e->getMessage(), 'does not exist')) {
                try {
                    $dsn = "pgsql:host={$host};port={$port};dbname=postgres";
                    $pdo = new PDO($dsn, $user, $password, [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_TIMEOUT => 5,
                    ]);
                    $detectedVersion = $this->detectPostgresqlVersion($pdo);

                    return [
                        'success' => true,
                        'message' => 'Connection successful (database will be created)',
                        'create_needed' => true,
                        'detected_version' => $detectedVersion,
                    ];
                } catch (PDOException) {
                    // Fall through to original error
                }
            }

            return [
                'success' => false,
                'message' => 'PostgreSQL connection failed: ' . $this->sanitizeErrorMessage($e->getMessage()),
            ];
        }
    }

    /**
     * Detect PostgreSQL server version and return Symfony-compatible version string.
     *
     * @return array{type: string, version: string, symfony_version: string, raw: string}
     */
    private function detectPostgresqlVersion(PDO $pdo): array
    {
        $stmt = $pdo->query('SELECT version()');
        $rawVersion = $stmt->fetchColumn();

        // Extract version number (e.g., "14.5" from "PostgreSQL 14.5 (Ubuntu 14.5-1.pgdg22.04+1)")
        preg_match('/PostgreSQL\s+(\d+(?:\.\d+)?)/', $rawVersion, $matches);
        $versionNumber = $matches[1] ?? '14';

        // For Symfony, PostgreSQL just needs the major version (e.g., "14" or "15")
        $majorVersion = explode('.', $versionNumber)[0];

        return [
            'type' => 'postgresql',
            'version' => $versionNumber,
            'symfony_version' => $majorVersion,
            'raw' => $rawVersion,
        ];
    }

    /**
     * Create SQLite database
     */
    private function createSqliteDatabase(array $config): array
    {
        $dbName = $config['name'] ?? 'little_isms_helper';
        $dbPath = $this->parameterBag->get('kernel.project_dir') . "/var/{$dbName}.db";
        $dbDir = dirname($dbPath);

        // Ensure var directory exists
        if (!is_dir($dbDir)) {
            mkdir($dbDir, 0755, true);
        }

        try {
            $pdo = new PDO("sqlite:{$dbPath}");
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            return [
                'success' => true,
                'message' => 'SQLite database created successfully',
            ];
        } catch (PDOException $e) {
            return [
                'success' => false,
                'message' => 'Failed to create SQLite database: ' . $this->sanitizeErrorMessage($e->getMessage()),
            ];
        }
    }

    /**
     * Create MySQL/MariaDB database
     */
    private function createMysqlDatabase(array $config): array
    {
        $host = $config['host'] ?? 'localhost';
        $port = $config['port'] ?? 3306;
        $user = $config['user'] ?? 'root';
        $password = $config['password'] ?? '';
        $dbName = $config['name'] ?? 'little_isms_helper';
        $unixSocket = $config['unixSocket'] ?? null;

        try {
            // Use Unix socket if explicitly provided
            $useUnixSocket = !empty($unixSocket);

            if ($useUnixSocket) {
                // Unix socket connection
                $dsn = "mysql:unix_socket={$unixSocket};charset=utf8mb4";
            } else {
                // Standard TCP connection
                $dsn = "mysql:host={$host};port={$port};charset=utf8mb4";
            }

            $pdo = new PDO($dsn, $user, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 5,
            ]);

            // Create database
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

            return [
                'success' => true,
                'message' => 'MySQL database created successfully',
            ];
        } catch (PDOException $e) {
            return [
                'success' => false,
                'message' => 'Failed to create MySQL database: ' . $this->sanitizeErrorMessage($e->getMessage()),
            ];
        }
    }

    /**
     * Create PostgreSQL database
     */
    private function createPostgresqlDatabase(array $config): array
    {
        $host = $config['host'] ?? 'localhost';
        $port = $config['port'] ?? 5432;
        $user = $config['user'] ?? 'postgres';
        $password = $config['password'] ?? '';
        $dbName = $config['name'] ?? 'little_isms_helper';

        try {
            // Connect to default postgres database
            $dsn = "pgsql:host={$host};port={$port};dbname=postgres";
            $pdo = new PDO($dsn, $user, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 5,
            ]);

            // Check if database exists
            $stmt = $pdo->prepare("SELECT 1 FROM pg_database WHERE datname = ?");
            $stmt->execute([$dbName]);
            $exists = $stmt->fetchColumn();

            if (!$exists) {
                // Check if user has CREATEDB privilege
                $stmt = $pdo->query("SELECT rolcreatedb FROM pg_roles WHERE rolname = current_user");
                $canCreateDb = $stmt->fetchColumn();

                if (!$canCreateDb) {
                    return [
                        'success' => false,
                        'message' => 'Permission denied: User does not have CREATEDB privilege. Please ask your database administrator to create the database "' . $dbName . '" or grant CREATEDB permission.',
                    ];
                }

                // Create database
                $pdo->exec("CREATE DATABASE {$dbName} WITH ENCODING 'UTF8'");
            }

            return [
                'success' => true,
                'message' => 'PostgreSQL database created successfully',
            ];
        } catch (PDOException $e) {
            // Check for permission-related errors
            if (str_contains($e->getMessage(), 'permission denied')) {
                return [
                    'success' => false,
                    'message' => 'Permission denied: You do not have permission to create databases. Please ask your database administrator to create the database "' . $dbName . '" manually, or use an existing database.',
                ];
            }

            return [
                'success' => false,
                'message' => 'Failed to create PostgreSQL database: ' . $this->sanitizeErrorMessage($e->getMessage()),
            ];
        }
    }

    /**
     * Check if database has existing tables
     *
     * @param array $config Database configuration
     * @return array Result with 'has_tables' boolean, 'count' int, and 'tables' array
     */
    public function checkExistingTables(array $config): array
    {
        $type = $config['type'] ?? 'mysql';

        try {
            return match ($type) {
                'sqlite' => $this->checkSqliteTables($config),
                'mysql', 'mariadb' => $this->checkMysqlTables($config),
                'postgresql' => $this->checkPostgresqlTables($config),
                default => [
                    'has_tables' => false,
                    'count' => 0,
                    'tables' => [],
                ],
            };
        } catch (Exception $e) {
            return [
                'has_tables' => false,
                'count' => 0,
                'tables' => [],
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Check SQLite tables
     */
    private function checkSqliteTables(array $config): array
    {
        $dbName = $config['name'] ?? 'little_isms_helper';
        $dbPath = $this->parameterBag->get('kernel.project_dir') . "/var/{$dbName}.db";

        if (!file_exists($dbPath)) {
            return ['has_tables' => false, 'count' => 0, 'tables' => []];
        }

        $pdo = new PDO("sqlite:{$dbPath}");
        $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

        return [
            'has_tables' => count($tables) > 0,
            'count' => count($tables),
            'tables' => $tables,
        ];
    }

    /**
     * Check MySQL tables
     */
    private function checkMysqlTables(array $config): array
    {
        $host = $config['host'] ?? 'localhost';
        $port = $config['port'] ?? 3306;
        $user = $config['user'] ?? 'root';
        $password = $config['password'] ?? '';
        $dbName = $config['name'] ?? 'little_isms_helper';

        // Check if we should use Unix socket for localhost connections
        $unixSocketPath = '/run/mysqld/mysqld.sock';
        $useUnixSocket = ($host === 'localhost' && file_exists($unixSocketPath));

        if ($useUnixSocket) {
            $dsn = "mysql:unix_socket={$unixSocketPath};dbname={$dbName}";
        } else {
            $dsn = "mysql:host={$host};port={$port};dbname={$dbName}";
        }

        $pdo = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        $stmt = $pdo->query("SHOW TABLES");
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

        return [
            'has_tables' => count($tables) > 0,
            'count' => count($tables),
            'tables' => $tables,
        ];
    }

    /**
     * Check PostgreSQL tables
     */
    private function checkPostgresqlTables(array $config): array
    {
        $host = $config['host'] ?? 'localhost';
        $port = $config['port'] ?? 5432;
        $user = $config['user'] ?? 'postgres';
        $password = $config['password'] ?? '';
        $dbName = $config['name'] ?? 'little_isms_helper';

        $dsn = "pgsql:host={$host};port={$port};dbname={$dbName}";
        $pdo = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        $stmt = $pdo->query("SELECT tablename FROM pg_tables WHERE schemaname = 'public'");
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

        return [
            'has_tables' => count($tables) > 0,
            'count' => count($tables),
            'tables' => $tables,
        ];
    }

    /**
     * Sanitize error message for user display
     */
    private function sanitizeErrorMessage(string $message): string
    {
        // Remove sensitive information like passwords from error messages
        $message = preg_replace('/password[=:][^\s]+/i', 'password=***', $message);

        // Limit message length
        if (strlen((string) $message) > 200) {
            return substr((string) $message, 0, 200) . '...';
        }

        return $message;
    }
}
