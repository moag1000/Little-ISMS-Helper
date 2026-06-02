<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Per-tenant confirmed TISAX legacy-id → canonical-id crosswalk store.
 */
final class Version20260630100000_TisaxCrosswalkEntry extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create tisax_crosswalk_entry (per-tenant confirmed legacy-id → 1.1.1 crosswalk)';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS tisax_crosswalk_entry (
                id INT AUTO_INCREMENT NOT NULL,
                tenant_id INT NOT NULL,
                legacy_id VARCHAR(64) NOT NULL,
                canonical_id VARCHAR(32) NOT NULL,
                confidence VARCHAR(16) NOT NULL,
                source VARCHAR(64) NOT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX idx_tce_tenant (tenant_id),
                UNIQUE INDEX uniq_tce_tenant_legacy (tenant_id, legacy_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql('ALTER TABLE tisax_crosswalk_entry ADD CONSTRAINT FK_tce_tenant FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS tisax_crosswalk_entry');
    }
}
