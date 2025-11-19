<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251119100707 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'CRITICAL-03 Phase 2D-3: Remove deprecated tenant-unaware fields from ComplianceRequirement - Complete multi-tenancy migration';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE compliance_requirement DROP applicable, DROP applicability_justification, DROP fulfillment_percentage, DROP fulfillment_notes, DROP evidence_description, DROP responsible_person, DROP target_date, DROP last_assessment_date');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE compliance_requirement ADD applicable TINYINT(1) NOT NULL, ADD applicability_justification LONGTEXT DEFAULT NULL, ADD fulfillment_percentage INT NOT NULL, ADD fulfillment_notes LONGTEXT DEFAULT NULL, ADD evidence_description LONGTEXT DEFAULT NULL, ADD responsible_person VARCHAR(100) DEFAULT NULL, ADD target_date DATE DEFAULT NULL, ADD last_assessment_date DATE DEFAULT NULL');
    }
}
