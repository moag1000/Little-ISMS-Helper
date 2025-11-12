<?php

namespace App\Service;

use PDO;
use PDOException;

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
        } catch (\Exception $e) {
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
        } catch (\Exception $e) {
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
        $dbPath = dirname(__DIR__, 2) . "/var/{$dbName}.db";

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

        try {
            $dsn = "mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4";
            $pdo = new PDO($dsn, $user, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 5,
            ]);

            // Test a simple query
            $pdo->query('SELECT 1');

            return [
                'success' => true,
                'message' => 'MySQL connection successful',
            ];
        } catch (PDOException $e) {
            // If database doesn't exist, that's okay - we can create it
            if (str_contains($e->getMessage(), 'Unknown database')) {
                return [
                    'success' => true,
                    'message' => 'Connection successful (database will be created)',
                    'create_needed' => true,
                ];
            }

            return [
                'success' => false,
                'message' => 'MySQL connection failed: ' . $this->sanitizeErrorMessage($e->getMessage()),
            ];
        }
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

            return [
                'success' => true,
                'message' => 'PostgreSQL connection successful',
            ];
        } catch (PDOException $e) {
            // If database doesn't exist, that's okay - we can create it
            if (str_contains($e->getMessage(), 'does not exist')) {
                return [
                    'success' => true,
                    'message' => 'Connection successful (database will be created)',
                    'create_needed' => true,
                ];
            }

            return [
                'success' => false,
                'message' => 'PostgreSQL connection failed: ' . $this->sanitizeErrorMessage($e->getMessage()),
            ];
        }
    }

    /**
     * Create SQLite database
     */
    private function createSqliteDatabase(array $config): array
    {
        $dbName = $config['name'] ?? 'little_isms_helper';
        $dbPath = dirname(__DIR__, 2) . "/var/{$dbName}.db";
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

        try {
            // Connect without database name
            $dsn = "mysql:host={$host};port={$port};charset=utf8mb4";
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
                // Create database
                $pdo->exec("CREATE DATABASE {$dbName} WITH ENCODING 'UTF8'");
            }

            return [
                'success' => true,
                'message' => 'PostgreSQL database created successfully',
            ];
        } catch (PDOException $e) {
            return [
                'success' => false,
                'message' => 'Failed to create PostgreSQL database: ' . $this->sanitizeErrorMessage($e->getMessage()),
            ];
        }
    }

    /**
     * Sanitize error message for user display
     */
    private function sanitizeErrorMessage(string $message): string
    {
        // Remove sensitive information like passwords from error messages
        $message = preg_replace('/password[=:][^\s]+/i', 'password=***', $message);

        // Limit message length
        if (strlen($message) > 200) {
            $message = substr($message, 0, 200) . '...';
        }

        return $message;
    }
}
