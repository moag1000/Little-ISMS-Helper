<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add CASCADE to compliance_mapping foreign keys for ComplianceRequirement
 *
 * This fixes the issue where deleting a ComplianceFramework fails because
 * ComplianceMapping entries reference ComplianceRequirements without CASCADE delete.
 *
 * When a ComplianceRequirement is deleted (via cascade from framework deletion),
 * the compliance_mapping entries that reference it must also be deleted automatically.
 */
final class Version20251119122239 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add ON DELETE CASCADE to compliance_mapping foreign keys for source_requirement_id and target_requirement_id';
    }

    public function up(Schema $schema): void
    {
        // Drop existing foreign keys
        $this->addSql('ALTER TABLE compliance_mapping DROP FOREIGN KEY FK_3D5913BD82E03D2');
        $this->addSql('ALTER TABLE compliance_mapping DROP FOREIGN KEY FK_3D5913BC72D605E');

        // Recreate foreign keys with ON DELETE CASCADE
        $this->addSql('ALTER TABLE compliance_mapping
            ADD CONSTRAINT FK_3D5913BD82E03D2
            FOREIGN KEY (source_requirement_id)
            REFERENCES compliance_requirement (id)
            ON DELETE CASCADE');

        $this->addSql('ALTER TABLE compliance_mapping
            ADD CONSTRAINT FK_3D5913BC72D605E
            FOREIGN KEY (target_requirement_id)
            REFERENCES compliance_requirement (id)
            ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // Revert to original foreign keys without CASCADE
        $this->addSql('ALTER TABLE compliance_mapping DROP FOREIGN KEY FK_3D5913BD82E03D2');
        $this->addSql('ALTER TABLE compliance_mapping DROP FOREIGN KEY FK_3D5913BC72D605E');

        $this->addSql('ALTER TABLE compliance_mapping
            ADD CONSTRAINT FK_3D5913BD82E03D2
            FOREIGN KEY (source_requirement_id)
            REFERENCES compliance_requirement (id)');

        $this->addSql('ALTER TABLE compliance_mapping
            ADD CONSTRAINT FK_3D5913BC72D605E
            FOREIGN KEY (target_requirement_id)
            REFERENCES compliance_requirement (id)');
    }
}
