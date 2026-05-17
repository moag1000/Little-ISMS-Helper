<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Symfony-BP-Audit #5 — AuditLog gets an explicit `tenant_id` FK column.
 *
 * Replaces the prior brittle string-JOIN tenant scoping (`userName →
 * User.email → User.tenant_id`) which broke whenever a user was
 * renamed, deactivated, or moved to a different tenant — the audit
 * trail would silently dangle or, worse, leak across tenant boundaries.
 *
 * Backfill: for every existing row, derive the tenant from the user
 * referenced by `user_name`. Rows with no matching user (system actions,
 * legacy deletions) stay NULL — repository methods must treat NULL as
 * "system / cross-tenant" and gate access accordingly.
 *
 * `isTransactional() = false` — MySQL implicitly commits ALTER TABLE,
 * which invalidates Doctrine's per-migration SAVEPOINT (CLAUDE.md
 * Pitfall #6).
 */
final class Version20260518090000_audit_log_tenant_fk extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Symfony-BP #5: audit_log.tenant_id FK + backfill from user_name lookup.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE audit_log
                ADD COLUMN tenant_id INT DEFAULT NULL COMMENT 'Multi-tenancy scope — captured at write time'
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE audit_log
                ADD CONSTRAINT FK_audit_log_tenant
                FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE SET NULL
        SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX idx_audit_tenant ON audit_log (tenant_id)
        SQL);

        // Backfill: derive tenant from user_name → users.email → users.tenant_id.
        // INSERT IGNORE-style update via subquery; rows without a matching user
        // stay NULL.
        $this->addSql(<<<'SQL'
            UPDATE audit_log al
            LEFT JOIN users u ON u.email = al.user_name
            SET al.tenant_id = u.tenant_id
            WHERE al.tenant_id IS NULL
              AND u.tenant_id IS NOT NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE audit_log DROP FOREIGN KEY FK_audit_log_tenant');
        $this->addSql('DROP INDEX idx_audit_tenant ON audit_log');
        $this->addSql('ALTER TABLE audit_log DROP COLUMN tenant_id');
    }
}
