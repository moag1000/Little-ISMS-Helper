<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Phase 6F-B1: Convert Risk Owner from string to User entity reference
 *
 * Changes:
 * - Remove risk_owner VARCHAR(100) field
 * - Add risk_owner_id INT field with foreign key to users table
 *
 * Breaking Change: Existing risk owner names (strings) will be lost.
 * Users must manually reassign risk owners after migration.
 */
final class Version20251110160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 6F-B1: Convert Risk.riskOwner from string to User entity reference';
    }

    public function up(Schema $schema): void
    {
        // Drop old string-based risk_owner field
        $this->addSql('ALTER TABLE risk DROP risk_owner');

        // Add new risk_owner_id field with foreign key to users table
        $this->addSql('ALTER TABLE risk ADD risk_owner_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE risk ADD CONSTRAINT FK_7906D541A65F9ED FOREIGN KEY (risk_owner_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_7906D541A65F9ED ON risk (risk_owner_id)');
    }

    public function down(Schema $schema): void
    {
        // Remove foreign key and risk_owner_id field
        $this->addSql('ALTER TABLE risk DROP FOREIGN KEY FK_7906D541A65F9ED');
        $this->addSql('DROP INDEX IDX_7906D541A65F9ED ON risk');
        $this->addSql('ALTER TABLE risk DROP risk_owner_id');

        // Restore old string-based risk_owner field (data will be empty)
        $this->addSql('ALTER TABLE risk ADD risk_owner VARCHAR(100) DEFAULT NULL');
    }
}
