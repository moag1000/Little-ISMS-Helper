<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Suggestion queue (Paket 5): one table for everything the tool proposes —
 * derivations, inheritance, imports and AI — until a person accepts or
 * rejects it.
 */
final class Version20261107140000_SuggestionQueue extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Suggestion queue: table suggestion';
    }

    public function isTransactional(): bool
    {
        // CREATE TABLE commits implicitly in MySQL.
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS suggestion (
                id INT AUTO_INCREMENT NOT NULL,
                tenant_id INT NOT NULL,
                kind VARCHAR(80) NOT NULL,
                origin VARCHAR(20) NOT NULL,
                subject_class VARCHAR(150) DEFAULT NULL,
                subject_id INT DEFAULT NULL,
                title VARCHAR(255) NOT NULL,
                rationale LONGTEXT NOT NULL,
                payload JSON NOT NULL,
                editable_text LONGTEXT DEFAULT NULL,
                confidence DOUBLE PRECISION DEFAULT NULL,
                model VARCHAR(120) DEFAULT NULL,
                dedup_key VARCHAR(64) NOT NULL,
                status VARCHAR(20) NOT NULL,
                created_at DATETIME NOT NULL,
                decided_at DATETIME DEFAULT NULL,
                decided_by_id INT DEFAULT NULL,
                decision_note LONGTEXT DEFAULT NULL,
                result_class VARCHAR(150) DEFAULT NULL,
                result_id INT DEFAULT NULL,
                INDEX idx_suggestion_tenant_status (tenant_id, status),
                INDEX idx_suggestion_subject (subject_class, subject_id),
                INDEX IDX_DD80F31BE26B496B (decided_by_id),
                UNIQUE INDEX uniq_suggestion_dedup (tenant_id, kind, dedup_key),
                PRIMARY KEY (id),
                CONSTRAINT FK_DD80F31B9033212A FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE,
                CONSTRAINT FK_DD80F31BE26B496B FOREIGN KEY (decided_by_id) REFERENCES users (id) ON DELETE SET NULL
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS suggestion');
    }
}
