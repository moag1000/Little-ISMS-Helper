<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Audit V3 C7 — generic ISMS Comment table.
 *
 * Polymorphic comment-thread entity attachable to any ISMS object via
 * (entity_type, entity_id). Drives the isms-comment Aurora pattern.
 *
 * `isTransactional() = false` — DDL implicitly commits in MySQL
 * (CLAUDE.md pitfall #6).
 */
final class Version20260510130000_isms_comment extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'V3 C7: Create comments table for generic ISMS comment thread (polymorphic, used by Risk/AuditFinding/Document).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
CREATE TABLE IF NOT EXISTS comments (
    id INT AUTO_INCREMENT NOT NULL,
    tenant_id INT NOT NULL,
    entity_type VARCHAR(50) NOT NULL,
    entity_id INT NOT NULL,
    author_id INT NOT NULL,
    body LONGTEXT NOT NULL,
    created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
    updated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
    INDEX idx_comments_tenant (tenant_id),
    INDEX idx_comments_entity (entity_type, entity_id),
    INDEX idx_comments_author (author_id),
    PRIMARY KEY(id),
    CONSTRAINT FK_comments_tenant FOREIGN KEY (tenant_id) REFERENCES tenant (id),
    CONSTRAINT FK_comments_author FOREIGN KEY (author_id) REFERENCES users (id) ON DELETE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS comments');
    }
}
