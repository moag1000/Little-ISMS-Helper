<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Schema-mapping reconciliation:
 * - risk_appetite.review_buffer_multiplier: DECIMAL(4,2) → FLOAT to match
 *   PHP property type and remove ORM-mapping mismatch warning.
 * - incident.severity: enforce nullable (matches mapping).
 * - DPIA: rename index to Doctrine-convention name (cosmetic).
 */
final class Version20260429110455 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Reconcile ORM mapping vs DB schema: risk_appetite multiplier '
            . 'to FLOAT, incident.severity nullable, DPIA index name.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            ALTER TABLE
              data_protection_impact_assessment RENAME INDEX idx_dpia_related_asset TO IDX_1ECB684CA35957EC
        SQL);
        $this->addSql('ALTER TABLE incident CHANGE severity severity VARCHAR(50) DEFAULT NULL');
        $this->addSql(<<<'SQL'
            ALTER TABLE
              risk_appetite
            CHANGE
              review_buffer_multiplier review_buffer_multiplier DOUBLE PRECISION NOT NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            ALTER TABLE
              data_protection_impact_assessment RENAME INDEX idx_1ecb684ca35957ec TO IDX_DPIA_RELATED_ASSET
        SQL);
        $this->addSql('ALTER TABLE incident CHANGE severity severity VARCHAR(50) NOT NULL');
        $this->addSql(<<<'SQL'
            ALTER TABLE
              risk_appetite
            CHANGE
              review_buffer_multiplier review_buffer_multiplier NUMERIC(4, 2) NOT NULL
        SQL);
    }
}
