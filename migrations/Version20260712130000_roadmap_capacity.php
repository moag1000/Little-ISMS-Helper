<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Resource-Planning PR-4: capacity calendar + roadmap allocations.
 * Depends on PR-1 (roadmap_tasks). isTransactional() = false.
 */
final class Version20260712130000_roadmap_capacity extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Resource-Planning PR-4: unavailability_calendars/periods + roadmap_allocations';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE unavailability_calendars (
            id INT AUTO_INCREMENT NOT NULL,
            name VARCHAR(255) NOT NULL,
            tenant_id INT NOT NULL,
            UNIQUE INDEX uniq_unavail_cal_tenant (tenant_id),
            PRIMARY KEY (id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE unavailability_periods (
            id INT AUTO_INCREMENT NOT NULL,
            kind VARCHAR(10) NOT NULL,
            start_date DATE NOT NULL COMMENT \'(DC2Type:date_immutable)\',
            end_date DATE DEFAULT NULL COMMENT \'(DC2Type:date_immutable)\',
            label VARCHAR(255) DEFAULT NULL,
            calendar_id INT NOT NULL,
            INDEX idx_unavail_period_cal (calendar_id),
            INDEX idx_unavail_period_range (start_date, end_date),
            PRIMARY KEY (id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE roadmap_allocations (
            id INT AUTO_INCREMENT NOT NULL,
            iso_year INT NOT NULL,
            iso_week INT NOT NULL,
            planned_pt NUMERIC(5, 1) DEFAULT \'0.0\' NOT NULL,
            roadmap_task_id INT NOT NULL,
            tenant_id INT NOT NULL,
            INDEX IDX_2E624C86926B4F9F (roadmap_task_id),
            INDEX IDX_2E624C869033212A (tenant_id),
            INDEX idx_alloc_tenant_week (tenant_id, iso_year, iso_week),
            UNIQUE INDEX uniq_alloc_task_week (tenant_id, roadmap_task_id, iso_year, iso_week),
            PRIMARY KEY (id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE unavailability_calendars ADD CONSTRAINT FK_50028C779033212A FOREIGN KEY (tenant_id) REFERENCES tenant (id)');
        $this->addSql('ALTER TABLE unavailability_periods ADD CONSTRAINT FK_5FB63178A40A2C8 FOREIGN KEY (calendar_id) REFERENCES unavailability_calendars (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE roadmap_allocations ADD CONSTRAINT FK_2E624C86926B4F9F FOREIGN KEY (roadmap_task_id) REFERENCES roadmap_tasks (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE roadmap_allocations ADD CONSTRAINT FK_2E624C869033212A FOREIGN KEY (tenant_id) REFERENCES tenant (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE roadmap_allocations DROP FOREIGN KEY FK_2E624C86926B4F9F');
        $this->addSql('ALTER TABLE roadmap_allocations DROP FOREIGN KEY FK_2E624C869033212A');
        $this->addSql('ALTER TABLE unavailability_periods DROP FOREIGN KEY FK_5FB63178A40A2C8');
        $this->addSql('ALTER TABLE unavailability_calendars DROP FOREIGN KEY FK_50028C779033212A');
        $this->addSql('DROP TABLE roadmap_allocations');
        $this->addSql('DROP TABLE unavailability_periods');
        $this->addSql('DROP TABLE unavailability_calendars');
    }
}
