<?php

namespace App\Tests\Service;

use App\Service\EnvironmentWriter;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class EnvironmentWriterTest extends TestCase
{
    private EnvironmentWriter $service;
    private ParameterBagInterface $parameterBag;
    private string $testDir;
    private string $testEnvFile;

    protected function setUp(): void
    {
        // Create temporary directory for test files
        $this->testDir = sys_get_temp_dir() . '/isms_test_' . uniqid();
        mkdir($this->testDir, 0755, true);
        mkdir($this->testDir . '/var', 0755, true);

        $this->testEnvFile = $this->testDir . '/.env.local';

        $this->parameterBag = $this->createMock(ParameterBagInterface::class);
        $this->parameterBag->method('get')
            ->with('kernel.project_dir')
            ->willReturn($this->testDir);

        $this->service = new EnvironmentWriter($this->parameterBag);
    }

    protected function tearDown(): void
    {
        // Clean up test files
        if (is_dir($this->testDir)) {
            $this->removeDirectory($this->testDir);
        }
    }

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

    // Test getEnvLocalPath()
    public function testGetEnvLocalPath(): void
    {
        $path = $this->service->getEnvLocalPath();

        $this->assertSame($this->testDir . '/.env.local', $path);
    }

    // Test envLocalExists()
    public function testEnvLocalExistsReturnsFalseWhenFileDoesNotExist(): void
    {
        $this->assertFalse($this->service->envLocalExists());
    }

    public function testEnvLocalExistsReturnsTrueWhenFileExists(): void
    {
        file_put_contents($this->testEnvFile, "APP_ENV=prod\n");

        $this->assertTrue($this->service->envLocalExists());
    }

    // Test writeEnvVariables()
    public function testWriteEnvVariablesCreatesNewFile(): void
    {
        $this->service->writeEnvVariables([
            'APP_ENV' => 'prod',
            'APP_SECRET' => 'test_secret_123',
        ]);

        $this->assertFileExists($this->testEnvFile);
        $content = file_get_contents($this->testEnvFile);
        $this->assertStringContainsString('APP_ENV=prod', $content);
        $this->assertStringContainsString('APP_SECRET=test_secret_123', $content);
    }

    public function testWriteEnvVariablesMergesWithExistingFile(): void
    {
        file_put_contents($this->testEnvFile, "EXISTING_VAR=value1\n");

        $this->service->writeEnvVariables([
            'NEW_VAR' => 'value2',
        ]);

        $content = file_get_contents($this->testEnvFile);
        $this->assertStringContainsString('EXISTING_VAR=value1', $content);
        $this->assertStringContainsString('NEW_VAR=value2', $content);
    }

    public function testWriteEnvVariablesOverwritesExistingValues(): void
    {
        file_put_contents($this->testEnvFile, "APP_ENV=dev\n");

        $this->service->writeEnvVariables([
            'APP_ENV' => 'prod',
        ]);

        $content = file_get_contents($this->testEnvFile);
        $this->assertStringContainsString('APP_ENV=prod', $content);
        $this->assertStringNotContainsString('APP_ENV=dev', $content);
    }

    public function testWriteEnvVariablesCreatesBackup(): void
    {
        file_put_contents($this->testEnvFile, "OLD_VALUE=123\n");

        $this->service->writeEnvVariables(['NEW_VALUE' => '456']);

        $backupFile = $this->testEnvFile . '.backup';
        $this->assertFileExists($backupFile);
        $backupContent = file_get_contents($backupFile);
        $this->assertStringContainsString('OLD_VALUE=123', $backupContent);
    }

    public function testWriteEnvVariablesThrowsExceptionForInvalidVariableName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid environment variable name: INVALID-NAME');

        $this->service->writeEnvVariables([
            'INVALID-NAME' => 'value',
        ]);
    }

    public function testWriteEnvVariablesThrowsExceptionForVariableNameWithSpaces(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid environment variable name: INVALID NAME');

        $this->service->writeEnvVariables([
            'INVALID NAME' => 'value',
        ]);
    }

    public function testWriteEnvVariablesThrowsExceptionForVariableNameStartingWithNumber(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service->writeEnvVariables([
            '123INVALID' => 'value',
        ]);
    }

    public function testWriteEnvVariablesAcceptsLowercaseVariableNames(): void
    {
        $this->service->writeEnvVariables([
            'lowercase_var' => 'value',
        ]);

        $content = file_get_contents($this->testEnvFile);
        $this->assertStringContainsString('lowercase_var=value', $content);
    }

    public function testWriteEnvVariablesSetsCorrectFilePermissions(): void
    {
        $this->service->writeEnvVariables(['TEST' => 'value']);

        $perms = fileperms($this->testEnvFile) & 0777;
        $this->assertSame(0600, $perms);
    }

    public function testWriteEnvVariablesEscapesSpecialCharacters(): void
    {
        $this->service->writeEnvVariables([
            'PASSWORD' => 'p@ssw0rd!#$&*()',
        ]);

        $content = file_get_contents($this->testEnvFile);
        // Password with special chars should be quoted
        $this->assertStringContainsString('PASSWORD="p@ssw0rd!#$&*()"', $content);
    }

    public function testWriteEnvVariablesEscapesPercentSigns(): void
    {
        $this->service->writeEnvVariables([
            'VALUE' => 'test%value%here',
        ]);

        $content = file_get_contents($this->testEnvFile);
        // % should be escaped as %%
        $this->assertStringContainsString('VALUE=test%%value%%here', $content);
    }

    public function testWriteEnvVariablesHandlesQuotesInValues(): void
    {
        $this->service->writeEnvVariables([
            'QUOTED' => 'value with "quotes"',
        ]);

        $content = file_get_contents($this->testEnvFile);
        // Quotes should be escaped
        $this->assertStringContainsString('QUOTED="value with \\"quotes\\""', $content);
    }

    public function testWriteEnvVariablesHandlesBackslashesInValues(): void
    {
        $this->service->writeEnvVariables([
            'PATH' => 'C:\\Windows\\System32',
        ]);

        $content = file_get_contents($this->testEnvFile);
        // Backslashes don't trigger quoting by themselves, but are preserved
        $this->assertStringContainsString('PATH=C:\\Windows\\System32', $content);
    }

    public function testWriteEnvVariablesEscapesBackslashesWhenQuoted(): void
    {
        $this->service->writeEnvVariables([
            // Space triggers quoting, then backslashes get escaped
            'VALUE' => 'C:\\Program Files\\App',
        ]);

        $content = file_get_contents($this->testEnvFile);
        // Should be quoted with escaped backslashes
        $this->assertStringContainsString('VALUE="C:\\\\Program Files\\\\App"', $content);
    }

    // Test readEnvLocal()
    public function testReadEnvLocalReturnsEmptyArrayWhenFileDoesNotExist(): void
    {
        $vars = $this->service->readEnvLocal();

        $this->assertSame([], $vars);
    }

    public function testReadEnvLocalParsesSimpleVariables(): void
    {
        file_put_contents($this->testEnvFile, "APP_ENV=prod\nAPP_DEBUG=0\n");

        $vars = $this->service->readEnvLocal();

        $this->assertSame(['APP_ENV' => 'prod', 'APP_DEBUG' => '0'], $vars);
    }

    public function testReadEnvLocalSkipsComments(): void
    {
        file_put_contents($this->testEnvFile, "# This is a comment\nAPP_ENV=prod\n# Another comment\n");

        $vars = $this->service->readEnvLocal();

        $this->assertSame(['APP_ENV' => 'prod'], $vars);
    }

    public function testReadEnvLocalSkipsEmptyLines(): void
    {
        file_put_contents($this->testEnvFile, "APP_ENV=prod\n\n\nAPP_DEBUG=0\n");

        $vars = $this->service->readEnvLocal();

        $this->assertSame(['APP_ENV' => 'prod', 'APP_DEBUG' => '0'], $vars);
    }

    public function testReadEnvLocalRemovesQuotesFromValues(): void
    {
        file_put_contents($this->testEnvFile, "QUOTED=\"value\"\nSINGLE='value2'\n");

        $vars = $this->service->readEnvLocal();

        $this->assertSame(['QUOTED' => 'value', 'SINGLE' => 'value2'], $vars);
    }

    public function testReadEnvLocalHandlesVariablesWithEqualsInValue(): void
    {
        file_put_contents($this->testEnvFile, "DATABASE_URL=mysql://user:pass@host/db?param=value\n");

        $vars = $this->service->readEnvLocal();

        $this->assertSame(['DATABASE_URL' => 'mysql://user:pass@host/db?param=value'], $vars);
    }

    // Test writeDatabaseConfig() - MySQL/MariaDB
    public function testWriteDatabaseConfigMysql(): void
    {
        $config = [
            'type' => 'mysql',
            'host' => 'localhost',
            'port' => 3306,
            'name' => 'test_db',
            'user' => 'root',
            'password' => 'secret',
            'serverVersion' => '8.0',
        ];

        $this->service->writeDatabaseConfig($config);

        $vars = $this->service->readEnvLocal();
        $this->assertSame('mysql', $vars['DB_TYPE']);
        $this->assertSame('localhost', $vars['DB_HOST']);
        $this->assertSame('3306', $vars['DB_PORT']);
        $this->assertSame('test_db', $vars['DB_NAME']);
        $this->assertSame('root', $vars['DB_USER']);
        $this->assertSame('secret', $vars['DB_PASS']);
        $this->assertSame('8.0', $vars['DB_SERVER_VERSION']);
        $this->assertStringContainsString('mysql://${DB_USER}:${DB_PASS}@${DB_HOST}:${DB_PORT}/${DB_NAME}', $vars['DATABASE_URL']);
    }

    public function testWriteDatabaseConfigMysqlWithUnixSocket(): void
    {
        $config = [
            'type' => 'mysql',
            'host' => 'localhost',
            'port' => 3306,
            'name' => 'test_db',
            'user' => 'root',
            'password' => 'secret',
            'serverVersion' => '8.0',
            'unixSocket' => '/var/run/mysqld/mysqld.sock',
        ];

        $this->service->writeDatabaseConfig($config);

        $vars = $this->service->readEnvLocal();
        $this->assertSame('/var/run/mysqld/mysqld.sock', $vars['DB_SOCKET']);
        $this->assertStringContainsString('unix_socket=/var/run/mysqld/mysqld.sock', $vars['DATABASE_URL']);
    }

    public function testWriteDatabaseConfigMysqlUsesDefaultPort(): void
    {
        $config = [
            'type' => 'mysql',
            'host' => 'localhost',
            'name' => 'test_db',
            'user' => 'root',
            'password' => 'secret',
        ];

        $this->service->writeDatabaseConfig($config);

        $vars = $this->service->readEnvLocal();
        $this->assertSame('3306', $vars['DB_PORT']);
    }

    public function testWriteDatabaseConfigMysqlUsesDefaultValues(): void
    {
        $config = ['type' => 'mysql'];

        $this->service->writeDatabaseConfig($config);

        $vars = $this->service->readEnvLocal();
        $this->assertSame('localhost', $vars['DB_HOST']);
        $this->assertSame('little_isms_helper', $vars['DB_NAME']);
        $this->assertSame('root', $vars['DB_USER']);
        $this->assertSame('', $vars['DB_PASS']);
    }

    // Test writeDatabaseConfig() - PostgreSQL
    public function testWriteDatabaseConfigPostgresql(): void
    {
        $config = [
            'type' => 'postgresql',
            'host' => 'db.example.com',
            'port' => 5432,
            'name' => 'app_db',
            'user' => 'postgres',
            'password' => 'pg_pass',
            'serverVersion' => '14',
        ];

        $this->service->writeDatabaseConfig($config);

        $vars = $this->service->readEnvLocal();
        $this->assertSame('postgresql', $vars['DB_TYPE']);
        $this->assertSame('db.example.com', $vars['DB_HOST']);
        $this->assertSame('5432', $vars['DB_PORT']);
        $this->assertStringContainsString('postgresql://', $vars['DATABASE_URL']);
    }

    public function testWriteDatabaseConfigPostgresqlUsesDefaultPort(): void
    {
        $config = [
            'type' => 'postgresql',
            'host' => 'localhost',
            'name' => 'test_db',
            'user' => 'postgres',
            'password' => 'secret',
        ];

        $this->service->writeDatabaseConfig($config);

        $vars = $this->service->readEnvLocal();
        $this->assertSame('5432', $vars['DB_PORT']);
    }

    // Test writeDatabaseConfig() - SQLite
    public function testWriteDatabaseConfigSqlite(): void
    {
        $config = [
            'type' => 'sqlite',
            'name' => 'app_database',
        ];

        $this->service->writeDatabaseConfig($config);

        $vars = $this->service->readEnvLocal();
        $this->assertStringContainsString('sqlite:///', $vars['DATABASE_URL']);
        $this->assertStringContainsString('app_database.db', $vars['DATABASE_URL']);
        $this->assertArrayNotHasKey('DB_HOST', $vars);
        $this->assertArrayNotHasKey('DB_USER', $vars);
    }

    public function testWriteDatabaseConfigSqliteUsesDefaultName(): void
    {
        $config = ['type' => 'sqlite'];

        $this->service->writeDatabaseConfig($config);

        $vars = $this->service->readEnvLocal();
        $this->assertStringContainsString('little_isms_helper.db', $vars['DATABASE_URL']);
    }

    public function testWriteDatabaseConfigThrowsExceptionForUnsupportedType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported database type: oracle');

        $this->service->writeDatabaseConfig(['type' => 'oracle']);
    }

    public function testWriteDatabaseConfigPasswordWithSpecialCharacters(): void
    {
        $config = [
            'type' => 'mysql',
            'host' => 'localhost',
            'port' => 3306,
            'name' => 'test_db',
            'user' => 'root',
            'password' => 'p@ss!#$%^&*()w0rd',
            'serverVersion' => '8.0',
        ];

        $this->service->writeDatabaseConfig($config);

        $vars = $this->service->readEnvLocal();
        // Password should be stored in DB_PASS and quoted
        $content = file_get_contents($this->testEnvFile);
        $this->assertStringContainsString('DB_PASS="p@ss!#$%%^&*()w0rd"', $content);
    }

    // Test ensureAppSecret()
    public function testEnsureAppSecretGeneratesNewSecret(): void
    {
        $this->service->ensureAppSecret();

        $vars = $this->service->readEnvLocal();
        $this->assertArrayHasKey('APP_SECRET', $vars);
        $this->assertSame(64, strlen($vars['APP_SECRET'])); // 32 bytes = 64 hex chars
    }

    public function testEnsureAppSecretDoesNotOverwriteExistingSecret(): void
    {
        file_put_contents($this->testEnvFile, "APP_SECRET=existing_secret_123\n");

        $this->service->ensureAppSecret();

        $vars = $this->service->readEnvLocal();
        $this->assertSame('existing_secret_123', $vars['APP_SECRET']);
    }

    public function testEnsureAppSecretGeneratesUniqueSecrets(): void
    {
        $this->service->ensureAppSecret();
        $vars1 = $this->service->readEnvLocal();
        $secret1 = $vars1['APP_SECRET'];

        // Create new service instance with different directory
        $testDir2 = sys_get_temp_dir() . '/isms_test_2_' . uniqid();
        mkdir($testDir2, 0755, true);

        $parameterBag2 = $this->createMock(ParameterBagInterface::class);
        $parameterBag2->method('get')
            ->with('kernel.project_dir')
            ->willReturn($testDir2);

        $service2 = new EnvironmentWriter($parameterBag2);
        $service2->ensureAppSecret();
        $vars2 = $service2->readEnvLocal();
        $secret2 = $vars2['APP_SECRET'];

        $this->assertNotSame($secret1, $secret2);

        // Cleanup
        $this->removeDirectory($testDir2);
    }

    // Test enrichFromDatabaseUrl()
    public function testEnrichFromDatabaseUrlWithMysqlUrl(): void
    {
        $envVars = [
            'DATABASE_URL' => 'mysql://user:pass@127.0.0.1:3306/dbname?serverVersion=8.0&charset=utf8mb4',
        ];

        $enriched = $this->service->enrichFromDatabaseUrl($envVars);

        $this->assertSame('mysql', $enriched['DB_TYPE']);
        $this->assertSame('127.0.0.1', $enriched['DB_HOST']);
        $this->assertSame('3306', $enriched['DB_PORT']);
        $this->assertSame('dbname', $enriched['DB_NAME']);
        $this->assertSame('user', $enriched['DB_USER']);
        $this->assertSame('pass', $enriched['DB_PASS']);
        $this->assertSame('8.0', $enriched['DB_SERVER_VERSION']);
    }

    public function testEnrichFromDatabaseUrlWithPostgresqlUrl(): void
    {
        $envVars = [
            'DATABASE_URL' => 'postgresql://postgres:secret@localhost:5432/appdb?serverVersion=14',
        ];

        $enriched = $this->service->enrichFromDatabaseUrl($envVars);

        $this->assertSame('postgresql', $enriched['DB_TYPE']);
        $this->assertSame('localhost', $enriched['DB_HOST']);
        $this->assertSame('5432', $enriched['DB_PORT']);
        $this->assertSame('appdb', $enriched['DB_NAME']);
        $this->assertSame('postgres', $enriched['DB_USER']);
        $this->assertSame('secret', $enriched['DB_PASS']);
    }

    public function testEnrichFromDatabaseUrlWithUrlEncodedPassword(): void
    {
        $envVars = [
            'DATABASE_URL' => 'mysql://user:p%40ss%21@localhost:3306/db',
        ];

        $enriched = $this->service->enrichFromDatabaseUrl($envVars);

        $this->assertSame('p@ss!', $enriched['DB_PASS']);
    }

    public function testEnrichFromDatabaseUrlWithVariableReferences(): void
    {
        $envVars = [
            'DB_USER' => 'myuser',
            'DB_PASS' => 'mypass',
            'DB_HOST' => 'myhost',
            'DB_PORT' => '3306',
            'DB_NAME' => 'mydb',
            'DATABASE_URL' => 'mysql://${DB_USER}:${DB_PASS}@${DB_HOST}:${DB_PORT}/${DB_NAME}',
        ];

        $enriched = $this->service->enrichFromDatabaseUrl($envVars);

        $this->assertSame('mysql', $enriched['DB_TYPE']);
        $this->assertSame('myhost', $enriched['DB_HOST']);
        $this->assertSame('myuser', $enriched['DB_USER']);
    }

    public function testEnrichFromDatabaseUrlDoesNotOverwriteExistingValues(): void
    {
        $envVars = [
            'DB_TYPE' => 'existing_type',
            'DB_HOST' => 'existing_host',
            'DATABASE_URL' => 'mysql://user:pass@newhost:3306/db',
        ];

        $enriched = $this->service->enrichFromDatabaseUrl($envVars);

        $this->assertSame('existing_type', $enriched['DB_TYPE']);
        $this->assertSame('existing_host', $enriched['DB_HOST']);
    }

    public function testEnrichFromDatabaseUrlReturnsUnchangedWhenNoDatabaseUrl(): void
    {
        $envVars = ['APP_ENV' => 'prod'];

        $enriched = $this->service->enrichFromDatabaseUrl($envVars);

        $this->assertSame($envVars, $enriched);
    }

    public function testEnrichFromDatabaseUrlHandlesUrlWithoutPort(): void
    {
        $envVars = [
            'DATABASE_URL' => 'mysql://user:pass@localhost/dbname',
        ];

        $enriched = $this->service->enrichFromDatabaseUrl($envVars);

        $this->assertSame('localhost', $enriched['DB_HOST']);
        $this->assertSame('dbname', $enriched['DB_NAME']);
        $this->assertArrayNotHasKey('DB_PORT', $enriched);
    }

    // Test getCurrentDatabaseConfig()
    public function testGetCurrentDatabaseConfigReturnsNullWhenNoFile(): void
    {
        $config = $this->service->getCurrentDatabaseConfig();

        $this->assertNull($config);
    }

    public function testGetCurrentDatabaseConfigReturnsNullWhenNoDatabaseUrl(): void
    {
        file_put_contents($this->testEnvFile, "APP_ENV=prod\n");

        $config = $this->service->getCurrentDatabaseConfig();

        $this->assertNull($config);
    }

    public function testGetCurrentDatabaseConfigParsesMysqlUrl(): void
    {
        file_put_contents($this->testEnvFile, "DATABASE_URL=mysql://user:pass@host:3306/dbname\n");

        $config = $this->service->getCurrentDatabaseConfig();

        $this->assertSame('mysql', $config['type']);
        $this->assertSame('user', $config['user']);
        $this->assertSame('pass', $config['password']);
        $this->assertSame('host', $config['host']);
        $this->assertSame(3306, $config['port']);
        $this->assertSame('dbname', $config['name']);
    }

    public function testGetCurrentDatabaseConfigParsesPostgresqlUrl(): void
    {
        file_put_contents($this->testEnvFile, "DATABASE_URL=postgresql://pg:secret@db.host:5432/app\n");

        $config = $this->service->getCurrentDatabaseConfig();

        $this->assertSame('postgresql', $config['type']);
        $this->assertSame('pg', $config['user']);
        $this->assertSame('secret', $config['password']);
        $this->assertSame('db.host', $config['host']);
        $this->assertSame(5432, $config['port']);
        $this->assertSame('app', $config['name']);
    }

    public function testGetCurrentDatabaseConfigParsesSqliteUrl(): void
    {
        file_put_contents($this->testEnvFile, "DATABASE_URL=sqlite:///var/app_db.db\n");

        $config = $this->service->getCurrentDatabaseConfig();

        $this->assertSame('sqlite', $config['type']);
        $this->assertSame('app_db', $config['name']);
    }

    // Test checkWritePermissions()
    public function testCheckWritePermissionsSucceedsWhenAllOk(): void
    {
        $result = $this->service->checkWritePermissions();

        $this->assertTrue($result['writable']);
        $this->assertSame('All filesystem permissions OK', $result['message']);
    }

    public function testCheckWritePermissionsSucceedsWhenFileExistsAndWritable(): void
    {
        file_put_contents($this->testEnvFile, "TEST=value\n");
        chmod($this->testEnvFile, 0644);

        $result = $this->service->checkWritePermissions();

        $this->assertTrue($result['writable']);
    }

    public function testCheckWritePermissionsFailsWhenFileNotWritable(): void
    {
        file_put_contents($this->testEnvFile, "TEST=value\n");
        chmod($this->testEnvFile, 0444);

        $result = $this->service->checkWritePermissions();

        $this->assertFalse($result['writable']);
        $this->assertStringContainsString('not writable', $result['message']);

        // Restore permissions for cleanup
        chmod($this->testEnvFile, 0644);
    }

    public function testCheckWritePermissionsFailsWhenVarDirNotWritable(): void
    {
        $varDir = $this->testDir . '/var';
        chmod($varDir, 0555);

        $result = $this->service->checkWritePermissions();

        $this->assertFalse($result['writable']);
        $this->assertStringContainsString('var', $result['message']);

        // Restore permissions for cleanup
        chmod($varDir, 0755);
    }

    // Test edge cases and error conditions
    public function testWriteEnvVariablesHandlesEmptyArray(): void
    {
        $this->service->writeEnvVariables([]);

        $this->assertFileExists($this->testEnvFile);
        $content = file_get_contents($this->testEnvFile);
        $this->assertStringContainsString('# LOCAL ENVIRONMENT CONFIGURATION', $content);
    }

    public function testWriteEnvVariablesHandlesEmptyStringValues(): void
    {
        $this->service->writeEnvVariables([
            'EMPTY_VAR' => '',
        ]);

        $content = file_get_contents($this->testEnvFile);
        $this->assertStringContainsString('EMPTY_VAR=', $content);
    }

    public function testWriteEnvVariablesHandlesLongValues(): void
    {
        $longValue = str_repeat('A', 1000);
        $this->service->writeEnvVariables([
            'LONG_VAR' => $longValue,
        ]);

        $vars = $this->service->readEnvLocal();
        $this->assertSame($longValue, $vars['LONG_VAR']);
    }

    public function testWriteEnvVariablesPreservesDatabaseUrlOrder(): void
    {
        $this->service->writeEnvVariables([
            'DB_USER' => 'user',
            'DB_PASS' => 'pass',
            'DATABASE_URL' => 'mysql://${DB_USER}:${DB_PASS}@localhost/db',
        ]);

        $content = file_get_contents($this->testEnvFile);
        $dbUserPos = strpos($content, 'DB_USER');
        $dbPassPos = strpos($content, 'DB_PASS');
        $databaseUrlPos = strpos($content, 'DATABASE_URL');

        // DATABASE_URL should come after DB_USER and DB_PASS
        $this->assertLessThan($databaseUrlPos, $dbUserPos);
        $this->assertLessThan($databaseUrlPos, $dbPassPos);
    }

    public function testReadEnvLocalHandlesMalformedLines(): void
    {
        file_put_contents($this->testEnvFile, "VALID=value\ninvalid line without equals\nANOTHER_VALID=value2\n");

        $vars = $this->service->readEnvLocal();

        $this->assertSame([
            'VALID' => 'value',
            'ANOTHER_VALID' => 'value2',
        ], $vars);
    }

    public function testDatabaseUrlIsQuotedInOutput(): void
    {
        $this->service->writeDatabaseConfig([
            'type' => 'mysql',
            'host' => 'localhost',
            'port' => 3306,
            'name' => 'test_db',
            'user' => 'root',
            'password' => 'secret',
        ]);

        $content = file_get_contents($this->testEnvFile);
        // DATABASE_URL should be quoted
        $this->assertMatchesRegularExpression('/DATABASE_URL="[^"]*"/', $content);
    }

    public function testWriteDatabaseConfigMariadbType(): void
    {
        $config = [
            'type' => 'mariadb',
            'host' => 'localhost',
            'port' => 3306,
            'name' => 'test_db',
            'user' => 'root',
            'password' => 'secret',
            'serverVersion' => '10.6',
        ];

        $this->service->writeDatabaseConfig($config);

        $vars = $this->service->readEnvLocal();
        $this->assertSame('mariadb', $vars['DB_TYPE']);
        $this->assertStringContainsString('mysql://', $vars['DATABASE_URL']); // MariaDB uses mysql protocol
    }

    public function testUnixSocketIgnoredWhenEmpty(): void
    {
        $config = [
            'type' => 'mysql',
            'host' => 'localhost',
            'port' => 3306,
            'name' => 'test_db',
            'user' => 'root',
            'password' => 'secret',
            'unixSocket' => '',
        ];

        $this->service->writeDatabaseConfig($config);

        $vars = $this->service->readEnvLocal();
        $this->assertArrayNotHasKey('DB_SOCKET', $vars);
        $this->assertStringNotContainsString('unix_socket', $vars['DATABASE_URL']);
    }

    public function testUnixSocketIgnoredWhenZero(): void
    {
        $config = [
            'type' => 'mysql',
            'host' => 'localhost',
            'port' => 3306,
            'name' => 'test_db',
            'user' => 'root',
            'password' => 'secret',
            'unixSocket' => '0',
        ];

        $this->service->writeDatabaseConfig($config);

        $vars = $this->service->readEnvLocal();
        $this->assertArrayNotHasKey('DB_SOCKET', $vars);
        $this->assertStringNotContainsString('unix_socket', $vars['DATABASE_URL']);
    }

    public function testEnrichFromDatabaseUrlHandlesMariadbScheme(): void
    {
        $envVars = [
            'DATABASE_URL' => 'mariadb://user:pass@localhost:3306/db',
        ];

        $enriched = $this->service->enrichFromDatabaseUrl($envVars);

        $this->assertSame('mysql', $enriched['DB_TYPE']); // MariaDB maps to mysql
    }

    public function testFileHeaderContainsTimestamp(): void
    {
        $this->service->writeEnvVariables(['TEST' => 'value']);

        $content = file_get_contents($this->testEnvFile);
        $this->assertStringContainsString('Generated at:', $content);
        $this->assertMatchesRegularExpression('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', $content);
    }

    public function testMultipleConsecutiveWritesWork(): void
    {
        $this->service->writeEnvVariables(['VAR1' => 'value1']);
        $this->service->writeEnvVariables(['VAR2' => 'value2']);
        $this->service->writeEnvVariables(['VAR3' => 'value3']);

        $vars = $this->service->readEnvLocal();
        $this->assertSame('value1', $vars['VAR1']);
        $this->assertSame('value2', $vars['VAR2']);
        $this->assertSame('value3', $vars['VAR3']);
    }
}
