<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * BSI 200-2 phase model: track the tenant's position in the
 * IT-Grundschutz adoption journey.
 */
final class Version20260419220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'BSI 200-2: tenant.bsi_phase (initiation/analysis/concept/implementation/continuous)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tenant' AND COLUMN_NAME='bsi_phase')");
        $this->addSql("SET @sql := IF(@col = 0, 'ALTER TABLE tenant ADD bsi_phase VARCHAR(20) DEFAULT NULL', 'SELECT 1')");
        $this->addSql('PREPARE stmt FROM @sql');
        $this->addSql('EXECUTE stmt');
        $this->addSql('DEALLOCATE PREPARE stmt');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tenant DROP bsi_phase');
    }
}
