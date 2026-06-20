<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Resource-Planning Spec §8: tenant-configurable capacity constants
 * (full_time_hours_per_week + hours_per_day).
 * isTransactional() = false — ALTER TABLE commits implicitly.
 */
final class Version20260720120000_planning_capacity_constants extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Resource-Planning Spec §8: add full_time_hours_per_week + hours_per_day to planning_settings';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE planning_settings ADD full_time_hours_per_week DOUBLE PRECISION DEFAULT NULL, ADD hours_per_day DOUBLE PRECISION DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE planning_settings DROP COLUMN full_time_hours_per_week, DROP COLUMN hours_per_day');
    }
}
