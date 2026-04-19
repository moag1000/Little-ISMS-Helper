<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * DORA Art. 18 / RTS on incident classification — add structured fields
 * (clients impacted, reputation impact, downtime, spread, data loss,
 * economic impact, final classification) to the existing incident row.
 */
final class Version20260419190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'DORA Art. 18: incident.dora_* classification fields';
    }

    /** @return array<int, array{col: string, type: string}> */
    private function columns(): array
    {
        return [
            ['col' => 'dora_clients_impacted',        'type' => 'INT DEFAULT NULL'],
            ['col' => 'dora_reputation_impact',       'type' => 'VARCHAR(30) DEFAULT NULL'],
            ['col' => 'dora_service_downtime_minutes','type' => 'INT DEFAULT NULL'],
            ['col' => 'dora_geographical_spread',     'type' => 'JSON DEFAULT NULL'],
            ['col' => 'dora_data_loss_occurred',      'type' => 'TINYINT(1) DEFAULT NULL'],
            ['col' => 'dora_economic_impact_eur',     'type' => 'INT DEFAULT NULL'],
            ['col' => 'dora_classification',          'type' => 'VARCHAR(20) DEFAULT NULL'],
        ];
    }

    public function up(Schema $schema): void
    {
        foreach ($this->columns() as $c) {
            $col = $c['col']; $type = $c['type'];
            $this->addSql(sprintf("SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='incident' AND COLUMN_NAME='%s')", $col));
            $this->addSql(sprintf("SET @sql := IF(@exists = 0, 'ALTER TABLE incident ADD `%s` %s', 'SELECT 1')", $col, $type));
            $this->addSql('PREPARE stmt FROM @sql'); $this->addSql('EXECUTE stmt'); $this->addSql('DEALLOCATE PREPARE stmt');
        }
        $this->addSql("ALTER TABLE incident MODIFY COLUMN dora_geographical_spread JSON DEFAULT NULL COMMENT '(DC2Type:json)'");
    }

    public function down(Schema $schema): void
    {
        foreach ($this->columns() as $c) {
            $this->addSql(sprintf('ALTER TABLE incident DROP `%s`', $c['col']));
        }
    }
}
