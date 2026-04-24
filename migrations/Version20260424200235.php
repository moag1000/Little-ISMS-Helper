<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add sample_data_import tracking table so Admin UI can remove
 * imported sample data in a targeted way (per-sample, per-tenant).
 */
final class Version20260424200235 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add sample_data_import tracking table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE sample_data_import (id INT AUTO_INCREMENT NOT NULL, sample_key VARCHAR(100) NOT NULL, entity_class VARCHAR(255) NOT NULL, entity_id INT NOT NULL, imported_at DATETIME NOT NULL, tenant_id INT DEFAULT NULL, imported_by_id INT DEFAULT NULL, INDEX IDX_EBCDF3849033212A (tenant_id), INDEX IDX_EBCDF38474953CEA (imported_by_id), INDEX idx_sample_key_tenant (sample_key, tenant_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE sample_data_import ADD CONSTRAINT FK_EBCDF3849033212A FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE sample_data_import ADD CONSTRAINT FK_EBCDF38474953CEA FOREIGN KEY (imported_by_id) REFERENCES users (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sample_data_import DROP FOREIGN KEY FK_EBCDF3849033212A');
        $this->addSql('ALTER TABLE sample_data_import DROP FOREIGN KEY FK_EBCDF38474953CEA');
        $this->addSql('DROP TABLE sample_data_import');
    }
}
