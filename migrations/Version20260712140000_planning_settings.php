<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Resource-Planning PR-5: per-tenant planning settings. isTransactional() = false.
 */
final class Version20260712140000_planning_settings extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Resource-Planning PR-5: planning_settings';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE planning_settings (
            id INT AUTO_INCREMENT NOT NULL,
            default_recurrence_months INT DEFAULT 12 NOT NULL,
            roadmap_horizon_weeks INT DEFAULT 12 NOT NULL,
            overbooking_threshold_pct INT DEFAULT 100 NOT NULL,
            scopes JSON NOT NULL,
            tenant_id INT NOT NULL,
            UNIQUE INDEX uniq_planning_settings_tenant (tenant_id),
            PRIMARY KEY (id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE planning_settings ADD CONSTRAINT FK_D240F6689033212A FOREIGN KEY (tenant_id) REFERENCES tenant (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE planning_settings DROP FOREIGN KEY FK_D240F6689033212A');
        $this->addSql('DROP TABLE planning_settings');
    }
}
