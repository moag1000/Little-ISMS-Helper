<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Phase 8QW-2 — RiskAppetite.reviewBufferMultiplier.
 *
 * Adds the configurable review-buffer multiplier column to risk_appetite.
 * DB default 1.50 preserves historic behaviour (previously hardcoded 1.5
 * in getRiskLevelClassification).
 */
final class Version20260421300000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 8QW-2: risk_appetite.review_buffer_multiplier column (default 1.50)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("
            ALTER TABLE risk_appetite
            ADD review_buffer_multiplier NUMERIC(4, 2) NOT NULL DEFAULT '1.50'
        ");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE risk_appetite DROP COLUMN review_buffer_multiplier');
    }
}
