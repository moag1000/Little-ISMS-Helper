<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Sprint A.5 hardening for AlvaHintDismissal:
 * - tenant_id column + FK so the same hint key + entity_id stays
 *   per-tenant instead of bleeding across tenants
 * - dismissed_until column for snooze-instead-of-forever semantics.
 *
 * Idempotent: each ALTER step only fires when the target column /
 * index / constraint is actually missing, so upgrade installations
 * that already ran schema:update converge to the same end-state
 * without "Duplicate column" or "Duplicate key name" errors.
 */
final class Version20260506230000_alva_hint_tenant_until extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Add tenant scope and dismissed_until snooze to alva_hint_dismissal.';
    }

    public function up(Schema $schema): void
    {
        if (!$this->columnExists('alva_hint_dismissal', 'tenant_id')) {
            $this->addSql('ALTER TABLE alva_hint_dismissal ADD tenant_id INT DEFAULT NULL');
        }
        if (!$this->columnExists('alva_hint_dismissal', 'dismissed_until')) {
            $this->addSql("ALTER TABLE alva_hint_dismissal ADD dismissed_until DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'");
        }

        if (!$this->indexExists('alva_hint_dismissal', 'idx_alva_hint_dismissal_tenant')) {
            $this->addSql('CREATE INDEX idx_alva_hint_dismissal_tenant ON alva_hint_dismissal (tenant_id)');
        }

        // Drop any Doctrine-auto-named FK on tenant_id (e.g. FK_C6B52440...)
        // before installing the canonical-named one.
        foreach ($this->foreignKeysOnColumn('alva_hint_dismissal', 'tenant_id') as $fkName) {
            if ($fkName !== 'FK_alva_hint_dismissal_tenant') {
                $this->addSql(sprintf('ALTER TABLE alva_hint_dismissal DROP FOREIGN KEY `%s`', $fkName));
            }
        }

        if (!$this->foreignKeyExists('alva_hint_dismissal', 'FK_alva_hint_dismissal_tenant')) {
            $this->addSql(<<<'SQL'
                ALTER TABLE alva_hint_dismissal
                    ADD CONSTRAINT FK_alva_hint_dismissal_tenant
                    FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE
            SQL);
        }

        // Swap the unique constraint to include tenant_id. Skip when the
        // existing unique index already covers tenant_id (idempotent re-run).
        $currentUnique = $this->uniqueIndexColumns('alva_hint_dismissal', 'uq_alva_hint_dismissal');
        $expected = ['user_id', 'tenant_id', 'hint_key', 'entity_type', 'entity_id'];
        if ($currentUnique !== $expected) {
            if ($currentUnique !== []) {
                $this->addSql('ALTER TABLE alva_hint_dismissal DROP INDEX uq_alva_hint_dismissal');
            }
            $this->addSql(<<<'SQL'
                ALTER TABLE alva_hint_dismissal
                    ADD CONSTRAINT uq_alva_hint_dismissal
                    UNIQUE (user_id, tenant_id, hint_key, entity_type, entity_id)
            SQL);
        }
    }

    public function down(Schema $schema): void
    {
        if ($this->indexExists('alva_hint_dismissal', 'uq_alva_hint_dismissal')) {
            $this->addSql('ALTER TABLE alva_hint_dismissal DROP INDEX uq_alva_hint_dismissal');
        }
        $this->addSql(<<<'SQL'
            ALTER TABLE alva_hint_dismissal
                ADD CONSTRAINT uq_alva_hint_dismissal
                UNIQUE (user_id, hint_key, entity_type, entity_id)
        SQL);
        if ($this->foreignKeyExists('alva_hint_dismissal', 'FK_alva_hint_dismissal_tenant')) {
            $this->addSql('ALTER TABLE alva_hint_dismissal DROP FOREIGN KEY FK_alva_hint_dismissal_tenant');
        }
        if ($this->indexExists('alva_hint_dismissal', 'idx_alva_hint_dismissal_tenant')) {
            $this->addSql('DROP INDEX idx_alva_hint_dismissal_tenant ON alva_hint_dismissal');
        }
        if ($this->columnExists('alva_hint_dismissal', 'tenant_id')) {
            $this->addSql('ALTER TABLE alva_hint_dismissal DROP tenant_id');
        }
        if ($this->columnExists('alva_hint_dismissal', 'dismissed_until')) {
            $this->addSql('ALTER TABLE alva_hint_dismissal DROP dismissed_until');
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column],
        ) > 0;
    }

    private function indexExists(string $table, string $indexName): bool
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            [$table, $indexName],
        ) > 0;
    }

    private function foreignKeyExists(string $table, string $constraintName): bool
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND CONSTRAINT_NAME = ?
               AND CONSTRAINT_TYPE = ?',
            [$table, $constraintName, 'FOREIGN KEY'],
        ) > 0;
    }

    /**
     * @return list<string>
     */
    private function foreignKeysOnColumn(string $table, string $column): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT DISTINCT kcu.CONSTRAINT_NAME
             FROM information_schema.KEY_COLUMN_USAGE kcu
             JOIN information_schema.TABLE_CONSTRAINTS tc
               ON tc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA
              AND tc.TABLE_NAME = kcu.TABLE_NAME
              AND tc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
             WHERE kcu.CONSTRAINT_SCHEMA = DATABASE()
               AND kcu.TABLE_NAME = ?
               AND kcu.COLUMN_NAME = ?
               AND tc.CONSTRAINT_TYPE = ?',
            [$table, $column, 'FOREIGN KEY'],
        );

        return array_map(static fn(array $r): string => (string) $r['CONSTRAINT_NAME'], $rows);
    }

    /**
     * @return list<string>
     */
    private function uniqueIndexColumns(string $table, string $indexName): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT COLUMN_NAME FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND INDEX_NAME = ?
               AND NON_UNIQUE = 0
             ORDER BY SEQ_IN_INDEX',
            [$table, $indexName],
        );

        return array_map(static fn(array $r): string => (string) $r['COLUMN_NAME'], $rows);
    }
}
