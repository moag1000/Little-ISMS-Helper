<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add CASCADE to compliance_requirement_control join table foreign keys
 *
 * This fixes the issue where deleting a ComplianceFramework fails due to
 * foreign key constraints in the compliance_requirement_control join table.
 *
 * When a ComplianceRequirement is deleted (via cascade from framework deletion),
 * the join table entries must also be deleted automatically.
 */
final class Version20251119121140 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add ON DELETE CASCADE to compliance_requirement_control join table foreign keys';
    }

    public function up(Schema $schema): void
    {
        // Drop existing foreign keys
        $this->addSql('ALTER TABLE compliance_requirement_control DROP FOREIGN KEY FK_57D957D32BEC70E');
        $this->addSql('ALTER TABLE compliance_requirement_control DROP FOREIGN KEY FK_57D957D492951C7');

        // Recreate foreign keys with ON DELETE CASCADE
        $this->addSql('ALTER TABLE compliance_requirement_control
            ADD CONSTRAINT FK_57D957D32BEC70E
            FOREIGN KEY (compliance_requirement_id)
            REFERENCES compliance_requirement (id)
            ON DELETE CASCADE');

        $this->addSql('ALTER TABLE compliance_requirement_control
            ADD CONSTRAINT FK_57D957D492951C7
            FOREIGN KEY (control_id)
            REFERENCES control (id)
            ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // Revert to original foreign keys without CASCADE
        $this->addSql('ALTER TABLE compliance_requirement_control DROP FOREIGN KEY FK_57D957D32BEC70E');
        $this->addSql('ALTER TABLE compliance_requirement_control DROP FOREIGN KEY FK_57D957D492951C7');

        $this->addSql('ALTER TABLE compliance_requirement_control
            ADD CONSTRAINT FK_57D957D32BEC70E
            FOREIGN KEY (compliance_requirement_id)
            REFERENCES compliance_requirement (id)');

        $this->addSql('ALTER TABLE compliance_requirement_control
            ADD CONSTRAINT FK_57D957D492951C7
            FOREIGN KEY (control_id)
            REFERENCES control (id)');
    }
}
