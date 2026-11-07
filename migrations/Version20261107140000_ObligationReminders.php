<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * One reminder path for all deadlines (Paket 2):
 *  - obligation_notice: sent digests / urgent notices per person (dedupe +
 *    the person's own in-app notifications);
 *  - users.reminder_digest_enabled: per-person opt-out of the digest.
 */
final class Version20261107140000_ObligationReminders extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Reminders: obligation_notice table, user digest opt-out';
    }

    public function isTransactional(): bool
    {
        // CREATE/ALTER TABLE commit implicitly in MySQL.
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE IF NOT EXISTS obligation_notice ('
            . ' id INT AUTO_INCREMENT NOT NULL,'
            . ' tenant_id INT NOT NULL,'
            . ' user_id INT NOT NULL,'
            . ' notice_key VARCHAR(190) NOT NULL,'
            . ' kind VARCHAR(16) NOT NULL,'
            . ' title VARCHAR(255) NOT NULL,'
            . ' link VARCHAR(255) DEFAULT NULL,'
            . ' due_at DATETIME DEFAULT NULL,'
            . ' created_at DATETIME NOT NULL,'
            . ' INDEX IDX_2EBED2859033212A (tenant_id),'
            . ' INDEX idx_obligation_notice_user_created (user_id, created_at),'
            . ' UNIQUE INDEX uniq_obligation_notice_user_key (user_id, notice_key),'
            . ' PRIMARY KEY(id),'
            . ' CONSTRAINT FK_2EBED2859033212A FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE,'
            . ' CONSTRAINT FK_2EBED285A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE'
            . ') DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB'
        );
        $this->addSql('ALTER TABLE users ADD reminder_digest_enabled TINYINT DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS obligation_notice');
        $this->addSql('ALTER TABLE users DROP reminder_digest_enabled');
    }
}
