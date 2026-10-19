<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use DoctrineMigrations\Version20261019120000_operator_bootstrap;
use DoctrineMigrations\Version20261019120100_reserved_custom_role_names;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * 3.15.3 security release data migrations (I1 operator bootstrap, I2
 * reserved custom role names). Run against an isolated in-memory SQLite DB
 * with the relevant columns only, so the "no SUPER_ADMIN" / "exactly one
 * tenant" preconditions are deterministic (the shared test DB is not).
 */
final class SecurityHotfixMigrationsTest extends TestCase
{
    private Connection $connection;
    /** @var list<string> */
    private array $log = [];

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement('CREATE TABLE tenant (id INTEGER PRIMARY KEY)');
        $this->connection->executeStatement(
            'CREATE TABLE users (id INTEGER PRIMARY KEY, email VARCHAR(180), roles TEXT, tenant_id INTEGER NULL)',
        );
        $this->connection->executeStatement(
            'CREATE TABLE roles (id INTEGER PRIMARY KEY, name VARCHAR(100), is_system_role BOOLEAN)',
        );
        $this->connection->executeStatement('CREATE TABLE user_roles (user_id INTEGER, role_id INTEGER)');
    }

    #[Test]
    public function promotesInitialAdminAndAssignsTenantlessUsersOnSingleTenantInstall(): void
    {
        $this->tenant(1);
        $this->user(1, 'user-first@example.com', ['ROLE_USER'], null);
        $this->user(2, 'setup-admin@example.com', ['ROLE_ADMIN'], null);
        $this->user(3, 'later-admin@example.com', ['ROLE_ADMIN'], 1);
        $this->user(4, 'tenantless@example.com', ['ROLE_USER'], null);

        $this->migrate(Version20261019120000_operator_bootstrap::class);

        self::assertSame(['ROLE_ADMIN', 'ROLE_SUPER_ADMIN'], $this->roles(2), 'setup admin = lowest admin id');
        self::assertSame(['ROLE_ADMIN'], $this->roles(3));
        self::assertNull($this->tenantOf(2), 'the instance operator stays instance-wide');
        self::assertSame(1, $this->tenantOf(1));
        self::assertSame(1, $this->tenantOf(4));
        self::assertStringContainsString('setup-admin@example.com', implode("\n", $this->log));

        // Idempotent: a second run changes nothing.
        $this->log = [];
        $this->migrate(Version20261019120000_operator_bootstrap::class);
        self::assertSame(['ROLE_ADMIN', 'ROLE_SUPER_ADMIN'], $this->roles(2));
        self::assertSame(['ROLE_ADMIN'], $this->roles(3));
        self::assertStringContainsString('no promotion needed', implode("\n", $this->log));
    }

    #[Test]
    public function leavesExistingSuperAdminAndMultiTenantInstallsAlone(): void
    {
        $this->tenant(1);
        $this->tenant(2);
        $this->user(1, 'admin@example.com', ['ROLE_ADMIN'], null);
        $this->user(2, 'root@example.com', ['ROLE_SUPER_ADMIN'], null);
        $this->user(3, 'tenantless@example.com', ['ROLE_USER'], null);

        $this->migrate(Version20261019120000_operator_bootstrap::class);

        self::assertSame(['ROLE_ADMIN'], $this->roles(1));
        self::assertNull($this->tenantOf(1));
        self::assertNull($this->tenantOf(3));
        self::assertStringContainsString('tenantless@example.com', implode("\n", $this->log));
    }

    #[Test]
    public function reservedCustomRoleNamesAreReportedNotChanged(): void
    {
        $this->connection->insert('roles', ['id' => 1, 'name' => 'ROLE_ADMIN', 'is_system_role' => 0]);
        $this->connection->insert('roles', ['id' => 2, 'name' => 'ROLE_GROUP_CISO', 'is_system_role' => 1]);
        $this->connection->insert('roles', ['id' => 3, 'name' => 'Auditors', 'is_system_role' => 0]);
        $this->connection->insert('roles', ['id' => 4, 'name' => 'role_super_admin ', 'is_system_role' => 0]);
        $this->connection->insert('user_roles', ['user_id' => 7, 'role_id' => 1]);

        $sql = $this->migrate(Version20261019120100_reserved_custom_role_names::class);

        self::assertSame(0, $sql, 'report only');
        $log = implode("\n", $this->log);
        self::assertStringContainsString('2 custom role(s)', $log);
        self::assertStringContainsString('#1 "ROLE_ADMIN" (1 holder(s))', $log);
        self::assertStringContainsString('#4 "role_super_admin "', $log);
        self::assertStringNotContainsString('ROLE_GROUP_CISO', $log, 'system roles are fine');
        self::assertStringNotContainsString('Auditors', $log);
    }

    /**
     * @param class-string<AbstractMigration> $class
     * @return int number of executed statements
     */
    private function migrate(string $class): int
    {
        $logger = new class ($this->log) extends AbstractLogger {
            /** @param list<string> $log */
            public function __construct(private array &$log)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->log[] = (string) $message;
            }
        };
        // Migrations are not autoloaded (no PSR-4 entry for DoctrineMigrations).
        require_once \dirname(__DIR__, 2) . '/migrations/' . substr($class, strrpos($class, '\\') + 1) . '.php';
        $migration = new $class($this->connection, $logger);
        $migration->up(new Schema());
        $queries = $migration->getSql();
        foreach ($queries as $query) {
            $this->connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }

        return count($queries);
    }

    private function tenant(int $id): void
    {
        $this->connection->insert('tenant', ['id' => $id]);
    }

    /**
     * @param list<string> $roles
     */
    private function user(int $id, string $email, array $roles, ?int $tenantId): void
    {
        $this->connection->insert('users', [
            'id' => $id,
            'email' => $email,
            'roles' => json_encode($roles),
            'tenant_id' => $tenantId,
        ]);
    }

    /**
     * @return list<string>
     */
    private function roles(int $id): array
    {
        return json_decode((string) $this->connection->fetchOne('SELECT roles FROM users WHERE id = ?', [$id]), true);
    }

    private function tenantOf(int $id): ?int
    {
        $value = $this->connection->fetchOne('SELECT tenant_id FROM users WHERE id = ?', [$id]);

        return $value === null ? null : (int) $value;
    }
}
