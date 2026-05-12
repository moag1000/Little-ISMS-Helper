<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * F26.1 — AuthorityTemplate entity table.
 *
 * Stores pre-configured notification templates for EU/DE supervisory authorities:
 * BSI-Meldestelle (NIS-2), BfDI (GDPR Art. 33), 16 LfDI (DE state DPAs).
 */
final class Version20260514100000_authority_template extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'F26.1 — AuthorityTemplate table for EU/DE supervisory-authority notification templates';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS authority_template (
            id INT AUTO_INCREMENT NOT NULL,
            tenant_id INT NOT NULL,
            authority_key VARCHAR(50) NOT NULL,
            entity_type VARCHAR(30) NOT NULL,
            field_mapping JSON NOT NULL,
            header_template LONGTEXT DEFAULT NULL,
            submission_url VARCHAR(512) DEFAULT NULL,
            submission_contact_email VARCHAR(255) DEFAULT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX idx_authority_template_tenant (tenant_id),
            INDEX idx_authority_template_key (authority_key),
            INDEX idx_authority_template_entity_type (entity_type),
            PRIMARY KEY (id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB');

        $this->addSql('ALTER TABLE authority_template
            ADD CONSTRAINT FK_authority_template_tenant FOREIGN KEY (tenant_id) REFERENCES tenant (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE authority_template DROP FOREIGN KEY FK_authority_template_tenant');
        $this->addSql('DROP TABLE IF EXISTS authority_template');
    }
}
