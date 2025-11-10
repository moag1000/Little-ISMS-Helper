<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Phase 6F: Add ISO 27001 compliance fields to Asset entity
 *
 * New fields:
 * - monetaryValue: Financial value for risk impact calculation
 * - dataClassification: public/internal/confidential/restricted
 * - acceptableUsePolicy: Reference to AUP
 * - handlingInstructions: Asset-specific handling instructions (Markdown)
 * - returnDate: For leased/borrowed assets
 *
 * Status field extended with: in_use, returned
 */
final class Version20251110150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 6F: Add ISO 27001 compliance fields (monetaryValue, dataClassification, acceptableUsePolicy, handlingInstructions, returnDate) to Asset entity';
    }

    public function up(Schema $schema): void
    {
        // Add monetary_value field for risk impact calculation
        $this->addSql('ALTER TABLE asset ADD monetary_value NUMERIC(15, 2) DEFAULT NULL');

        // Add data_classification field (public, internal, confidential, restricted)
        $this->addSql('ALTER TABLE asset ADD data_classification VARCHAR(50) DEFAULT NULL');

        // Add acceptable_use_policy field
        $this->addSql('ALTER TABLE asset ADD acceptable_use_policy LONGTEXT DEFAULT NULL');

        // Add handling_instructions field (supports Markdown)
        $this->addSql('ALTER TABLE asset ADD handling_instructions LONGTEXT DEFAULT NULL');

        // Add return_date field for asset return workflow
        $this->addSql('ALTER TABLE asset ADD return_date DATE DEFAULT NULL');

        // Note: Status field choices are enforced at application level (Asset Entity @Assert\Choice)
        // New status values: 'in_use', 'returned' added to existing: active, inactive, retired, disposed
    }

    public function down(Schema $schema): void
    {
        // Remove Phase 6F fields
        $this->addSql('ALTER TABLE asset DROP return_date');
        $this->addSql('ALTER TABLE asset DROP handling_instructions');
        $this->addSql('ALTER TABLE asset DROP acceptable_use_policy');
        $this->addSql('ALTER TABLE asset DROP data_classification');
        $this->addSql('ALTER TABLE asset DROP monetary_value');
    }
}
