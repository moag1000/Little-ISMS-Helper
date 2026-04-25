<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Pattern A dual-state: add ManyToOne User columns next to legacy string owner fields.
 * String columns stay in place for backwards compatibility; new code reads
 * getEffectiveXxx() (structured user preferred, string fallback).
 */
final class Version20260418150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Pattern A dual-state: *_user_id ManyToOne User next to legacy free-text owner fields';
    }

    /**
     * @return array<int, array{table: string, column: string, constraint: string}>
     */
    private function columns(): array
    {
        return [
            ['table' => 'asset',                    'column' => 'owner_user_id',                    'constraint' => 'FK_ASSET_OWNER_USER'],
            ['table' => 'business_continuity_plan', 'column' => 'plan_owner_user_id',              'constraint' => 'FK_BCP_OWNER_USER'],
            ['table' => 'business_process',         'column' => 'process_owner_user_id',          'constraint' => 'FK_BP_OWNER_USER'],
            ['table' => 'control',                  'column' => 'responsible_person_user_id',     'constraint' => 'FK_CONTROL_OWNER_USER'],
            ['table' => 'incident',                 'column' => 'reported_by_user_id',            'constraint' => 'FK_INCIDENT_REPORTER_USER'],
            ['table' => 'risk',                     'column' => 'acceptance_approved_by_user_id', 'constraint' => 'FK_RISK_APPROVER_USER'],
            ['table' => 'training',                 'column' => 'trainer_user_id',                'constraint' => 'FK_TRAINING_TRAINER_USER'],
        ];
    }

    public function up(Schema $schema): void
    {
        foreach ($this->columns() as $c) {
            $table = $c['table'];
            $column = $c['column'];
            $constraint = $c['constraint'];
            $this->addSql(sprintf(
                "SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='%s' AND COLUMN_NAME='%s')",
                $table, $column
            ));
            $this->addSql(sprintf(
                "SET @sql := IF(@col_exists = 0, 'ALTER TABLE `%s` ADD `%s` INT DEFAULT NULL', 'SELECT 1')",
                $table, $column
            ));
            $this->addSql('PREPARE stmt FROM @sql');
            $this->addSql('EXECUTE stmt');
            $this->addSql('DEALLOCATE PREPARE stmt');

            $this->addSql(sprintf(
                "SET @fk_exists := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='%s' AND CONSTRAINT_NAME='%s')",
                $table, $constraint
            ));
            $this->addSql(sprintf(
                "SET @sql := IF(@fk_exists = 0, 'ALTER TABLE `%s` ADD CONSTRAINT `%s` FOREIGN KEY (`%s`) REFERENCES users (id) ON DELETE SET NULL', 'SELECT 1')",
                $table, $constraint, $column
            ));
            $this->addSql('PREPARE stmt FROM @sql');
            $this->addSql('EXECUTE stmt');
            $this->addSql('DEALLOCATE PREPARE stmt');
        }
    }

    public function down(Schema $schema): void
    {
        foreach ($this->columns() as $c) {
            $this->addSql(sprintf('ALTER TABLE `%s` DROP FOREIGN KEY IF EXISTS `%s`', $c['table'], $c['constraint']));
            $this->addSql(sprintf('ALTER TABLE `%s` DROP COLUMN IF EXISTS `%s`', $c['table'], $c['column']));
        }
    }
}
