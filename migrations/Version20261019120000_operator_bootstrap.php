<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\InitialAdminService;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * 3.15.3 security release — operator bootstrap (data only, idempotent).
 *
 * The hotfix scopes ROLE_ADMIN to its own tenant tree. Default installs never
 * had a ROLE_SUPER_ADMIN (setup created a tenantless ROLE_ADMIN that
 * effectively acted instance-wide). Without this migration the operator would
 * lose tenantless users, instance-wide backups and system roles.
 *
 *  (a) No ROLE_SUPER_ADMIN user exists → promote the initial setup admin
 *      (rule of {@see InitialAdminService}: lowest id with ROLE_ADMIN or
 *      ROLE_SUPER_ADMIN) to ROLE_SUPER_ADMIN.
 *  (b) Exactly one tenant exists → assign tenantless users to it. Instance
 *      operators (ROLE_SUPER_ADMIN) stay tenantless/instance-wide. With more
 *      than one tenant, tenantless users are only listed (a SUPER_ADMIN must
 *      assign them).
 *
 * Recovery when (a) picked the wrong account or none:
 * `php bin/console app:user:grant-super-admin <email>`.
 */
final class Version20261019120000_operator_bootstrap extends AbstractMigration
{
    public function getDescription(): string
    {
        return '3.15.3 security: promote setup admin to ROLE_SUPER_ADMIN if none exists; assign tenantless users on single-tenant installs';
    }

    public function up(Schema $schema): void
    {
        $promotedId = $this->promoteInitialAdmin();
        $this->assignTenantlessUsers($promotedId);
    }

    public function down(Schema $schema): void
    {
        // Irreversible on purpose: demoting the operator or moving users out
        // of their tenant again would re-create the lock-out this fixes.
        $this->write('Version20261019120000_operator_bootstrap: down() is a no-op (data migration).');
    }

    /**
     * @return int|null id of the promoted user (null when nothing changed)
     */
    private function promoteInitialAdmin(): ?int
    {
        $superCount = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM users WHERE roles LIKE ?',
            [InitialAdminService::ROLE_SUPER_ADMIN_LIKE],
        );
        if ($superCount > 0) {
            $this->write(sprintf('[security] %d ROLE_SUPER_ADMIN user(s) exist — no promotion needed.', $superCount));

            return null;
        }

        $initialAdmin = $this->connection->fetchAssociative(
            'SELECT id, email, roles FROM users WHERE roles LIKE ? OR roles LIKE ? ORDER BY id ASC LIMIT 1',
            [InitialAdminService::ROLE_ADMIN_LIKE, InitialAdminService::ROLE_SUPER_ADMIN_LIKE],
        );
        if ($initialAdmin === false) {
            $this->write('[security] WARNING: no admin user found — nobody promoted. Run "php bin/console app:user:grant-super-admin <email>" after creating one.');

            return null;
        }

        $roles = json_decode((string) $initialAdmin['roles'], true);
        $roles = is_array($roles) ? array_values($roles) : [];
        $roles[] = 'ROLE_SUPER_ADMIN';

        $this->addSql(
            'UPDATE users SET roles = ? WHERE id = ?',
            [json_encode(array_values(array_unique($roles))), (int) $initialAdmin['id']],
        );
        $this->write(sprintf(
            '[security] Promoted initial setup admin #%d (%s) to ROLE_SUPER_ADMIN (instance operator).',
            (int) $initialAdmin['id'],
            (string) $initialAdmin['email'],
        ));

        return (int) $initialAdmin['id'];
    }

    private function assignTenantlessUsers(?int $promotedId): void
    {
        $tenantless = $this->connection->fetchAllAssociative(
            'SELECT id, email FROM users WHERE tenant_id IS NULL AND roles NOT LIKE ? ORDER BY id ASC',
            [InitialAdminService::ROLE_SUPER_ADMIN_LIKE],
        );
        $tenantless = array_values(array_filter(
            $tenantless,
            static fn (array $row): bool => (int) $row['id'] !== $promotedId,
        ));
        if ($tenantless === []) {
            $this->write('[security] No tenantless users (besides instance operators) — nothing to assign.');

            return;
        }

        $tenantIds = $this->connection->fetchFirstColumn('SELECT id FROM tenant ORDER BY id ASC');
        $emails = implode(', ', array_map(static fn (array $row): string => (string) $row['email'], $tenantless));
        if (count($tenantIds) !== 1) {
            $this->write(sprintf(
                '[security] WARNING: %d tenantless user(s) are now only visible to ROLE_SUPER_ADMIN (%d tenants exist, no automatic assignment): %s',
                count($tenantless),
                count($tenantIds),
                $emails,
            ));

            return;
        }

        $this->addSql(
            'UPDATE users SET tenant_id = ? WHERE id IN (?)',
            [(int) $tenantIds[0], array_map(static fn (array $row): int => (int) $row['id'], $tenantless)],
            [ParameterType::INTEGER, ArrayParameterType::INTEGER],
        );
        $this->write(sprintf(
            '[security] Assigned %d tenantless user(s) to the only tenant #%d: %s',
            count($tenantless),
            (int) $tenantIds[0],
            $emails,
        ));
    }
}
