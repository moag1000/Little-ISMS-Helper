<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Resource-Planning PR-3: per-tenant, per-source auto-conversion config.
 * isTransactional() = false.
 */
final class Version20260712120000_source_conversion_config extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Resource-Planning PR-3: source_conversion_configs';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE source_conversion_configs (
            id INT AUTO_INCREMENT NOT NULL,
            source_slug VARCHAR(40) NOT NULL,
            enabled TINYINT DEFAULT 0 NOT NULL,
            due_offset_days INT DEFAULT 0 NOT NULL,
            default_effort_pt NUMERIC(4, 1) DEFAULT NULL,
            tenant_id INT NOT NULL,
            INDEX idx_source_conv_tenant (tenant_id),
            UNIQUE INDEX uniq_source_conv_tenant_slug (tenant_id, source_slug),
            PRIMARY KEY (id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE source_conversion_configs ADD CONSTRAINT FK_25DBB0969033212A FOREIGN KEY (tenant_id) REFERENCES tenant (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE source_conversion_configs DROP FOREIGN KEY FK_25DBB0969033212A');
        $this->addSql('DROP TABLE source_conversion_configs');
    }
}
