<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * GDPR Art. 30(1)(f) (N-4): optional per-data-category retention map on
 * processing_activity, for mixed activities where categories have different
 * statutory retention periods.
 */
final class Version20260704140000_RetentionPerCategory extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add retention_per_category JSON to processing_activity (GDPR Art. 30(1)(f))';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE processing_activity ADD retention_per_category JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE processing_activity DROP retention_per_category');
    }
}
