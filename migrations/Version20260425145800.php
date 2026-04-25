<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Mapping-Quality-Vision Phase 1: Schema-Erweiterung für Lifecycle,
 * Provenance, Methodology, Relationship + MQS-Score-Aufschlüsselung.
 */
final class Version20260425145800 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Mapping-Quality: lifecycle/provenance/methodology/relationship + MQS breakdown';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE compliance_mapping ADD lifecycle_state VARCHAR(20) NOT NULL DEFAULT 'draft', ADD provenance_source VARCHAR(255) DEFAULT NULL, ADD provenance_url VARCHAR(500) DEFAULT NULL, ADD methodology_type VARCHAR(50) DEFAULT NULL, ADD methodology_description LONGTEXT DEFAULT NULL, ADD relationship VARCHAR(30) DEFAULT NULL, ADD gap_warning LONGTEXT DEFAULT NULL, ADD audit_evidence_hint LONGTEXT DEFAULT NULL, ADD mqs_breakdown JSON DEFAULT NULL");
        // Bestehendes review_status best-effort auf lifecycle_state migrieren
        $this->addSql("UPDATE compliance_mapping SET lifecycle_state = CASE review_status WHEN 'approved' THEN 'approved' WHEN 'in_review' THEN 'review' WHEN 'rejected' THEN 'deprecated' ELSE 'draft' END");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compliance_mapping DROP lifecycle_state, DROP provenance_source, DROP provenance_url, DROP methodology_type, DROP methodology_description, DROP relationship, DROP gap_warning, DROP audit_evidence_hint, DROP mqs_breakdown');
    }
}
