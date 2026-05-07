<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Per-tenant render counter for proactive Alva hints. Pairs with the
 * dismissal table so the stats dashboard can show "rendered N times,
 * dismissed M times" for every hint key.
 *
 * Idempotent: tolerates an existing alva_hint_render_count table for
 * upgrades that already ran schema:update.
 */
final class Version20260506235000_alva_hint_render_count extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Add alva_hint_render_count table for hint render telemetry.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS alva_hint_render_count (
                id INT AUTO_INCREMENT NOT NULL,
                tenant_id INT DEFAULT NULL,
                hint_key VARCHAR(100) NOT NULL,
                render_count INT NOT NULL DEFAULT 0,
                UNIQUE INDEX uq_alva_hint_render_count (tenant_id, hint_key),
                INDEX idx_alva_hint_render_tenant (tenant_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE=InnoDB
        SQL);

        if (!$this->foreignKeyExists('alva_hint_render_count', 'FK_alva_hint_render_tenant')) {
            $this->addSql(<<<'SQL'
                ALTER TABLE alva_hint_render_count
                    ADD CONSTRAINT FK_alva_hint_render_tenant
                    FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE
            SQL);
        }
    }

    public function down(Schema $schema): void
    {
        if ($this->foreignKeyExists('alva_hint_render_count', 'FK_alva_hint_render_tenant')) {
            $this->addSql('ALTER TABLE alva_hint_render_count DROP FOREIGN KEY FK_alva_hint_render_tenant');
        }
        $this->addSql('DROP TABLE IF EXISTS alva_hint_render_count');
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
}
