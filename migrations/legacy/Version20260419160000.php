<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * M-01: Link MappingGapItem to a RiskTreatmentPlan and/or Control so gap
 * remediation is tracked end-to-end (ISO 27001 Clause 10 Improvement).
 */
final class Version20260419160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'M-01: mapping_gap_item.risk_treatment_plan_id + remediation_control_id';
    }

    public function up(Schema $schema): void
    {
        foreach ([
            ['col' => 'risk_treatment_plan_id', 'ref' => 'risk_treatment_plan', 'fk' => 'FK_MGI_TREATMENT_PLAN'],
            ['col' => 'remediation_control_id', 'ref' => 'control', 'fk' => 'FK_MGI_REMEDIATION_CONTROL'],
        ] as $c) {
            $col = $c['col']; $ref = $c['ref']; $fk = $c['fk'];
            $this->addSql(sprintf("SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='mapping_gap_item' AND COLUMN_NAME='%s')", $col));
            $this->addSql(sprintf("SET @sql := IF(@col = 0, 'ALTER TABLE mapping_gap_item ADD `%s` INT DEFAULT NULL', 'SELECT 1')", $col));
            $this->addSql('PREPARE stmt FROM @sql'); $this->addSql('EXECUTE stmt'); $this->addSql('DEALLOCATE PREPARE stmt');

            $this->addSql(sprintf("SET @fk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='mapping_gap_item' AND CONSTRAINT_NAME='%s')", $fk));
            $this->addSql(sprintf("SET @sql := IF(@fk = 0, 'ALTER TABLE mapping_gap_item ADD CONSTRAINT `%s` FOREIGN KEY (`%s`) REFERENCES `%s` (id) ON DELETE SET NULL', 'SELECT 1')", $fk, $col, $ref));
            $this->addSql('PREPARE stmt FROM @sql'); $this->addSql('EXECUTE stmt'); $this->addSql('DEALLOCATE PREPARE stmt');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mapping_gap_item DROP FOREIGN KEY FK_MGI_TREATMENT_PLAN');
        $this->addSql('ALTER TABLE mapping_gap_item DROP FOREIGN KEY FK_MGI_REMEDIATION_CONTROL');
        $this->addSql('ALTER TABLE mapping_gap_item DROP risk_treatment_plan_id, DROP remediation_control_id');
    }
}
