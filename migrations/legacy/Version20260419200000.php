<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * TISAX VDA ISA AL-level tagging on ComplianceRequirement.
 * Allows filtering requirements by the customer's target assessment level
 * (AL1 / AL2 / AL3) and keeps the rest of the Annex-A-style catalog intact.
 */
final class Version20260419200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'TISAX: compliance_requirement.assessment_level (AL1/AL2/AL3)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='compliance_requirement' AND COLUMN_NAME='assessment_level')");
        $this->addSql("SET @sql := IF(@col = 0, 'ALTER TABLE compliance_requirement ADD assessment_level VARCHAR(10) DEFAULT NULL', 'SELECT 1')");
        $this->addSql('PREPARE stmt FROM @sql'); $this->addSql('EXECUTE stmt'); $this->addSql('DEALLOCATE PREPARE stmt');

        // Backfill: TISAX AL3 loader already stores the requirements we can tag.
        $this->addSql("UPDATE compliance_requirement cr
            INNER JOIN compliance_framework cf ON cf.id = cr.framework_id
            SET cr.assessment_level = 'AL3'
            WHERE cf.code = 'TISAX-AL3' AND cr.assessment_level IS NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compliance_requirement DROP assessment_level');
    }
}
