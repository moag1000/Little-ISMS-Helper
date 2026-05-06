<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Persisted per-user dismissal store for proactive Alva hints.
 * Replaces the localStorage-only dismissal state so users see the same
 * "already dismissed" hints across browsers and devices.
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
            CREATE TABLE alva_hint_dismissal (
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
        $this->addSql(<<<'SQL'
            ALTER TABLE alva_hint_dismissal
                ADD CONSTRAINT FK_alva_hint_dismissal_user
                FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE alva_hint_dismissal DROP FOREIGN KEY FK_alva_hint_dismissal_user');
        $this->addSql('DROP TABLE alva_hint_dismissal');
    }
}
