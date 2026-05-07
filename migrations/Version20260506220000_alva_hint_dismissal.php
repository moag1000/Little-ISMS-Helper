<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Persisted per-user dismissal store for proactive Alva hints.
 * Replaces the localStorage-only dismissal state so users see the same
 * "already dismissed" hints across browsers and devices.
 *
 * Idempotent: tolerates an existing alva_hint_dismissal table (e.g.
 * when schema:update already created it before the upgrade) so
 * pre-migration installations can catch up without manual repair.
 */
final class Version20260506220000_alva_hint_dismissal extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Add alva_hint_dismissal table for cross-device per-user hint dismissal.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS alva_hint_dismissal (
                id INT AUTO_INCREMENT NOT NULL,
                user_id INT NOT NULL,
                hint_key VARCHAR(100) NOT NULL,
                entity_type VARCHAR(100) NOT NULL DEFAULT '',
                entity_id INT NOT NULL DEFAULT 0,
                dismissed_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX uq_alva_hint_dismissal (user_id, hint_key, entity_type, entity_id),
                INDEX idx_alva_hint_dismissal_user (user_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE=InnoDB
        SQL);

        // Clean out any Doctrine-auto-named FK on user_id that schema:update
        // may have left behind (e.g. FK_C6B52440A76ED395) so the canonical
        // FK_alva_hint_dismissal_user can take over without duplication.
        foreach ($this->foreignKeysOnColumn('alva_hint_dismissal', 'user_id') as $fkName) {
            if ($fkName !== 'FK_alva_hint_dismissal_user') {
                $this->addSql(sprintf('ALTER TABLE alva_hint_dismissal DROP FOREIGN KEY `%s`', $fkName));
            }
        }

        if (!$this->foreignKeyExists('alva_hint_dismissal', 'FK_alva_hint_dismissal_user')) {
            $this->addSql(<<<'SQL'
                ALTER TABLE alva_hint_dismissal
                    ADD CONSTRAINT FK_alva_hint_dismissal_user
                    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
            SQL);
        }
    }

    public function down(Schema $schema): void
    {
        if ($this->foreignKeyExists('alva_hint_dismissal', 'FK_alva_hint_dismissal_user')) {
            $this->addSql('ALTER TABLE alva_hint_dismissal DROP FOREIGN KEY FK_alva_hint_dismissal_user');
        }
        $this->addSql('DROP TABLE IF EXISTS alva_hint_dismissal');
    }

    private function foreignKeyExists(string $table, string $constraintName): bool
    {
        $count = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND CONSTRAINT_NAME = ?
               AND CONSTRAINT_TYPE = ?',
            [$table, $constraintName, 'FOREIGN KEY'],
        );

        return $count > 0;
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
}
