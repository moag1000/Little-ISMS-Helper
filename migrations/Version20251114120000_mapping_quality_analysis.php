<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Mapping Quality Analysis Migration
 *
 * Adds automated quality analysis and gap tracking for compliance mappings
 */
final class Version20251114120000_mapping_quality_analysis extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add mapping quality analysis fields and gap tracking system';
    }

    public function up(Schema $schema): void
    {
        // Create mapping_gap_item table
        $this->addSql('CREATE TABLE IF NOT EXISTS mapping_gap_item (
            id INT AUTO_INCREMENT NOT NULL,
            mapping_id INT NOT NULL,
            gap_type VARCHAR(50) NOT NULL,
            description LONGTEXT NOT NULL,
            missing_keywords JSON DEFAULT NULL,
            recommended_action LONGTEXT DEFAULT NULL,
            priority VARCHAR(20) NOT NULL,
            estimated_effort INT DEFAULT NULL,
            percentage_impact INT NOT NULL,
            identification_source VARCHAR(50) NOT NULL,
            confidence INT NOT NULL,
            status VARCHAR(30) NOT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX IDX_mapping_gap_item_mapping (mapping_id),
            INDEX IDX_mapping_gap_item_priority (priority),
            INDEX IDX_mapping_gap_item_status (status),
            PRIMARY KEY(id),
            CONSTRAINT FK_mapping_gap_item_mapping
                FOREIGN KEY (mapping_id)
                REFERENCES compliance_mapping (id)
                ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Add new fields to compliance_mapping table
        $this->addSql('ALTER TABLE compliance_mapping
            ADD COLUMN calculated_percentage INT DEFAULT NULL AFTER mapping_percentage,
            ADD COLUMN manual_percentage INT DEFAULT NULL AFTER calculated_percentage,
            ADD COLUMN analysis_confidence INT DEFAULT NULL AFTER manual_percentage,
            ADD COLUMN analysis_algorithm_version VARCHAR(20) DEFAULT NULL AFTER analysis_confidence,
            ADD COLUMN requires_review TINYINT(1) NOT NULL DEFAULT 0 AFTER analysis_algorithm_version,
            ADD COLUMN review_status VARCHAR(30) NOT NULL DEFAULT \'unreviewed\' AFTER requires_review,
            ADD COLUMN quality_score INT DEFAULT NULL AFTER review_status,
            ADD COLUMN textual_similarity NUMERIC(5, 4) DEFAULT NULL AFTER quality_score,
            ADD COLUMN keyword_overlap NUMERIC(5, 4) DEFAULT NULL AFTER textual_similarity,
            ADD COLUMN structural_similarity NUMERIC(5, 4) DEFAULT NULL AFTER keyword_overlap,
            ADD COLUMN review_notes LONGTEXT DEFAULT NULL AFTER structural_similarity,
            ADD COLUMN reviewed_by VARCHAR(100) DEFAULT NULL AFTER review_notes,
            ADD COLUMN reviewed_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\' AFTER reviewed_by
        ');

        // Add indices for better query performance
        $this->addSql('CREATE INDEX IDX_compliance_mapping_review_status ON compliance_mapping (review_status)');
        $this->addSql('CREATE INDEX IDX_compliance_mapping_requires_review ON compliance_mapping (requires_review)');
        $this->addSql('CREATE INDEX IDX_compliance_mapping_quality_score ON compliance_mapping (quality_score)');
        $this->addSql('CREATE INDEX IDX_compliance_mapping_analysis_confidence ON compliance_mapping (analysis_confidence)');
    }

    public function down(Schema $schema): void
    {
        // Drop mapping_gap_item table
        $this->addSql('DROP TABLE IF EXISTS mapping_gap_item');

        // Remove indices
        $this->addSql('DROP INDEX IDX_compliance_mapping_review_status ON compliance_mapping');
        $this->addSql('DROP INDEX IDX_compliance_mapping_requires_review ON compliance_mapping');
        $this->addSql('DROP INDEX IDX_compliance_mapping_quality_score ON compliance_mapping');
        $this->addSql('DROP INDEX IDX_compliance_mapping_analysis_confidence ON compliance_mapping');

        // Remove new fields from compliance_mapping table
        $this->addSql('ALTER TABLE compliance_mapping
            DROP COLUMN calculated_percentage,
            DROP COLUMN manual_percentage,
            DROP COLUMN analysis_confidence,
            DROP COLUMN analysis_algorithm_version,
            DROP COLUMN requires_review,
            DROP COLUMN review_status,
            DROP COLUMN quality_score,
            DROP COLUMN textual_similarity,
            DROP COLUMN keyword_overlap,
            DROP COLUMN structural_similarity,
            DROP COLUMN review_notes,
            DROP COLUMN reviewed_by,
            DROP COLUMN reviewed_at
        ');
    }
}
