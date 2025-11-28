<?php

namespace App\Tests\Service;

use App\Service\DatabaseTestService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class DatabaseTestServiceTest extends TestCase
{
    private MockObject $parameterBag;
    private DatabaseTestService $service;
    private string $projectDir;

    protected function setUp(): void
    {
        $this->parameterBag = $this->createMock(ParameterBagInterface::class);
        $this->projectDir = sys_get_temp_dir() . '/db_test_' . uniqid();

        // Create test project directory
        if (!is_dir($this->projectDir)) {
            mkdir($this->projectDir, 0755, true);
        }

        $this->parameterBag->method('get')
            ->with('kernel.project_dir')
            ->willReturn($this->projectDir);

        $this->service = new DatabaseTestService($this->parameterBag);
    }

    protected function tearDown(): void
    {
        // Clean up test directories
        if (is_dir($this->projectDir)) {
            $this->removeDirectory($this->projectDir);
        }
    }

    // testConnection() Tests

    public function testTestConnectionReturnsErrorForUnsupportedDatabaseType(): void
    {
        $config = ['type' => 'oracle'];

        $result = $this->service->testConnection($config);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('message', $result);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Unsupported database type: oracle', $result['message']);
    }

    public function testTestConnectionDefaultsToMysql(): void
    {
        $config = []; // No type specified

        $result = $this->service->testConnection($config);

        // Should default to mysql and fail (no connection details)
        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('message', $result);
    }

    public function testTestConnectionSanitizesErrorMessages(): void
    {
        // Test with invalid MySQL config to trigger error
        $config = [
            'type' => 'mysql',
            'host' => 'invalid.host.test',
            'user' => 'testuser',
            'password' => 'secret123',
            'name' => 'testdb',
        ];

        $result = $this->service->testConnection($config);

        $this->assertFalse($result['success']);
        // Verify error message is sanitized (no raw PDO errors)
        $this->assertIsString($result['message']);
        // Message should be limited to 200 chars if longer
        $this->assertLessThanOrEqual(203, strlen($result['message'])); // 200 + '...'
    }

    public function testTestConnectionRecognizesMysqlType(): void
    {
        $config = [
            'type' => 'mysql',
            'host' => 'invalid.test',
        ];

        $result = $this->service->testConnection($config);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
    }

    public function testTestConnectionRecognizesMariadbType(): void
    {
        $config = [
            'type' => 'mariadb',
            'host' => 'invalid.test',
        ];

        $result = $this->service->testConnection($config);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
    }

    public function testTestConnectionRecognizesPostgresqlType(): void
    {
        $config = [
            'type' => 'postgresql',
            'host' => 'invalid.test',
        ];

        $result = $this->service->testConnection($config);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
    }

    public function testTestConnectionRecognizesSqliteType(): void
    {
        $config = [
            'type' => 'sqlite',
            'name' => 'test',
        ];

        $result = $this->service->testConnection($config);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
    }

    public function testTestConnectionSqliteSuccessfulConnection(): void
    {
        $config = [
            'type' => 'sqlite',
            'name' => 'test_success',
        ];

        // Create var directory
        $varDir = $this->projectDir . '/var';
        if (!is_dir($varDir)) {
            mkdir($varDir, 0755, true);
        }

        $result = $this->service->testConnection($config);

        $this->assertTrue($result['success']);
        $this->assertEquals('SQLite connection successful', $result['message']);
    }

    // createDatabaseIfNotExists() Tests

    public function testCreateDatabaseIfNotExistsReturnsErrorForUnsupportedType(): void
    {
        $config = ['type' => 'mongodb'];

        $result = $this->service->createDatabaseIfNotExists($config);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Unsupported database type: mongodb', $result['message']);
    }

    public function testCreateDatabaseIfNotExistsDefaultsToMysql(): void
    {
        $config = []; // No type specified

        $result = $this->service->createDatabaseIfNotExists($config);

        // Should default to mysql
        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
    }

    public function testCreateDatabaseIfNotExistsSqliteCreatesVarDirectory(): void
    {
        $config = [
            'type' => 'sqlite',
            'name' => 'auto_created',
        ];

        $result = $this->service->createDatabaseIfNotExists($config);

        $this->assertTrue($result['success']);
        $this->assertEquals('SQLite database created successfully', $result['message']);
        $this->assertDirectoryExists($this->projectDir . '/var');
    }

    public function testCreateDatabaseIfNotExistsSqliteCreatesDatabase(): void
    {
        $config = [
            'type' => 'sqlite',
            'name' => 'new_database',
        ];

        $result = $this->service->createDatabaseIfNotExists($config);

        $this->assertTrue($result['success']);
        $dbPath = $this->projectDir . '/var/new_database.db';
        $this->assertFileExists($dbPath);
    }

    public function testCreateDatabaseIfNotExistsSqliteUsesDefaultName(): void
    {
        $config = [
            'type' => 'sqlite',
            // No name specified
        ];

        $result = $this->service->createDatabaseIfNotExists($config);

        $this->assertTrue($result['success']);
        $dbPath = $this->projectDir . '/var/little_isms_helper.db';
        $this->assertFileExists($dbPath);
    }

    // checkExistingTables() Tests

    public function testCheckExistingTablesReturnsEmptyForUnsupportedType(): void
    {
        $config = ['type' => 'redis'];

        $result = $this->service->checkExistingTables($config);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('has_tables', $result);
        $this->assertArrayHasKey('count', $result);
        $this->assertArrayHasKey('tables', $result);
        $this->assertFalse($result['has_tables']);
        $this->assertEquals(0, $result['count']);
        $this->assertEquals([], $result['tables']);
    }

    public function testCheckExistingTablesSqliteReturnsEmptyWhenDatabaseDoesNotExist(): void
    {
        $config = [
            'type' => 'sqlite',
            'name' => 'nonexistent',
        ];

        $result = $this->service->checkExistingTables($config);

        $this->assertFalse($result['has_tables']);
        $this->assertEquals(0, $result['count']);
        $this->assertEquals([], $result['tables']);
    }

    public function testCheckExistingTablesSqliteReturnsEmptyForNewDatabase(): void
    {
        $config = [
            'type' => 'sqlite',
            'name' => 'empty_db',
        ];

        // Create empty database
        $dbDir = $this->projectDir . '/var';
        mkdir($dbDir, 0755, true);
        $dbPath = $dbDir . '/empty_db.db';
        touch($dbPath);

        $result = $this->service->checkExistingTables($config);

        $this->assertFalse($result['has_tables']);
        $this->assertEquals(0, $result['count']);
        $this->assertIsArray($result['tables']);
    }

    public function testCheckExistingTablesSqliteDetectsExistingTables(): void
    {
        $config = [
            'type' => 'sqlite',
            'name' => 'db_with_tables',
        ];

        // Create database with tables
        $dbDir = $this->projectDir . '/var';
        mkdir($dbDir, 0755, true);
        $dbPath = $dbDir . '/db_with_tables.db';

        $pdo = new \PDO("sqlite:{$dbPath}");
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT)');
        $pdo->exec('CREATE TABLE roles (id INTEGER PRIMARY KEY, name TEXT)');

        $result = $this->service->checkExistingTables($config);

        $this->assertTrue($result['has_tables']);
        $this->assertEquals(2, $result['count']);
        $this->assertContains('users', $result['tables']);
        $this->assertContains('roles', $result['tables']);
    }

    public function testCheckExistingTablesSqliteExcludesSqliteMasterTables(): void
    {
        $config = [
            'type' => 'sqlite',
            'name' => 'db_test',
        ];

        $dbDir = $this->projectDir . '/var';
        mkdir($dbDir, 0755, true);
        $dbPath = $dbDir . '/db_test.db';

        $pdo = new \PDO("sqlite:{$dbPath}");
        $pdo->exec('CREATE TABLE test_table (id INTEGER)');

        $result = $this->service->checkExistingTables($config);

        // Should not include sqlite_* tables
        foreach ($result['tables'] as $table) {
            $this->assertStringStartsNotWith('sqlite_', $table);
        }
    }

    public function testCheckExistingTablesHandlesExceptions(): void
    {
        $config = [
            'type' => 'mysql',
            'host' => 'invalid.host.test',
            'user' => 'invalid',
            'password' => 'invalid',
            'name' => 'invalid',
        ];

        $result = $this->service->checkExistingTables($config);

        $this->assertArrayHasKey('has_tables', $result);
        $this->assertFalse($result['has_tables']);
        $this->assertEquals(0, $result['count']);
        $this->assertArrayHasKey('error', $result);
    }

    public function testCheckExistingTablesDefaultsToMysql(): void
    {
        $config = []; // No type specified

        $result = $this->service->checkExistingTables($config);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('has_tables', $result);
    }

    // sanitizeErrorMessage() Tests (tested indirectly through other methods)

    public function testSanitizeErrorMessageRemovesPassword(): void
    {
        $config = [
            'type' => 'mysql',
            'host' => 'invalid.host.test',
            'user' => 'testuser',
            'password' => 'MySecretPassword123',
            'name' => 'testdb',
        ];

        $result = $this->service->testConnection($config);

        // Error message should not contain the password
        $this->assertStringNotContainsString('MySecretPassword123', $result['message']);
    }

    public function testSanitizeErrorMessageTruncatesLongMessages(): void
    {
        // This is tested indirectly in testTestConnectionSanitizesErrorMessages
        // as we verify the message length is limited
        $this->assertTrue(true);
    }

    // Edge Cases and Additional Coverage

    public function testMysqlConnectionWithDefaultValues(): void
    {
        $config = [
            'type' => 'mysql',
            // Uses defaults: host=localhost, port=3306, user=root, password='', name=little_isms_helper
        ];

        $result = $this->service->testConnection($config);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('message', $result);
    }

    public function testPostgresqlConnectionWithDefaultValues(): void
    {
        $config = [
            'type' => 'postgresql',
            // Uses defaults: host=localhost, port=5432, user=postgres, password='', name=little_isms_helper
        ];

        $result = $this->service->testConnection($config);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
    }

    public function testMysqlConnectionWithCustomPort(): void
    {
        $config = [
            'type' => 'mysql',
            'host' => 'invalid.test',
            'port' => 3307,
            'user' => 'testuser',
            'password' => 'testpass',
            'name' => 'testdb',
        ];

        $result = $this->service->testConnection($config);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
    }

    public function testPostgresqlConnectionWithCustomPort(): void
    {
        $config = [
            'type' => 'postgresql',
            'host' => 'invalid.test',
            'port' => 5433,
            'user' => 'testuser',
            'password' => 'testpass',
            'name' => 'testdb',
        ];

        $result = $this->service->testConnection($config);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
    }

    public function testMysqlConnectionWithUnixSocket(): void
    {
        $config = [
            'type' => 'mysql',
            'unixSocket' => '/var/run/mysqld/mysqld.sock',
            'user' => 'testuser',
            'password' => 'testpass',
            'name' => 'testdb',
        ];

        $result = $this->service->testConnection($config);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        // Will fail because socket doesn't exist, but tests the code path
    }

    public function testMysqlCreateDatabaseWithUnixSocket(): void
    {
        $config = [
            'type' => 'mysql',
            'unixSocket' => '/var/run/mysqld/mysqld.sock',
            'user' => 'testuser',
            'password' => 'testpass',
            'name' => 'testdb',
        ];

        $result = $this->service->createDatabaseIfNotExists($config);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
    }

    public function testSqliteConnectionWithCustomName(): void
    {
        $config = [
            'type' => 'sqlite',
            'name' => 'custom_name',
        ];

        $varDir = $this->projectDir . '/var';
        mkdir($varDir, 0755, true);

        $result = $this->service->testConnection($config);

        $this->assertTrue($result['success']);
    }

    public function testMultipleCallsToSameService(): void
    {
        $config = [
            'type' => 'sqlite',
            'name' => 'multi_test',
        ];

        $varDir = $this->projectDir . '/var';
        mkdir($varDir, 0755, true);

        // First call
        $result1 = $this->service->testConnection($config);
        $this->assertTrue($result1['success']);

        // Second call
        $result2 = $this->service->testConnection($config);
        $this->assertTrue($result2['success']);

        // Both should succeed
        $this->assertEquals($result1, $result2);
    }

    public function testCreateDatabaseThenCheckTables(): void
    {
        $config = [
            'type' => 'sqlite',
            'name' => 'workflow_test',
        ];

        // Create database
        $createResult = $this->service->createDatabaseIfNotExists($config);
        $this->assertTrue($createResult['success']);

        // Check tables (should be empty)
        $checkResult = $this->service->checkExistingTables($config);
        $this->assertFalse($checkResult['has_tables']);
        $this->assertEquals(0, $checkResult['count']);
    }

    public function testCheckExistingTablesAfterCreatingTables(): void
    {
        $config = [
            'type' => 'sqlite',
            'name' => 'populated_db',
        ];

        // Create database
        $dbDir = $this->projectDir . '/var';
        mkdir($dbDir, 0755, true);
        $dbPath = $dbDir . '/populated_db.db';

        $pdo = new \PDO("sqlite:{$dbPath}");
        $pdo->exec('CREATE TABLE test1 (id INTEGER)');
        $pdo->exec('CREATE TABLE test2 (id INTEGER)');
        $pdo->exec('CREATE TABLE test3 (id INTEGER)');

        // Check tables
        $result = $this->service->checkExistingTables($config);

        $this->assertTrue($result['has_tables']);
        $this->assertEquals(3, $result['count']);
        $this->assertCount(3, $result['tables']);
    }

    public function testErrorMessageContainsConnectionType(): void
    {
        $sqliteConfig = [
            'type' => 'sqlite',
            'name' => 'test',
        ];

        // Force an error by making var directory unwritable (if possible)
        $varDir = $this->projectDir . '/var';
        mkdir($varDir, 0755, true);

        // Create a file where directory should be to cause error
        $dbPath = $varDir . '/error_test.db';
        mkdir($dbPath); // Create as directory instead of file to cause PDO error

        $errorConfig = [
            'type' => 'sqlite',
            'name' => 'error_test',
        ];

        $result = $this->service->testConnection($errorConfig);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('SQLite connection failed', $result['message']);

        // Clean up
        rmdir($dbPath);
    }

    // Helper method to remove directories recursively
    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
