<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add person_id foreign key to physical_access_log
 *
 * Links physical access logs to the centralized Person entity.
 * The person_name field is kept for backwards compatibility (deprecated).
 */
final class Version20251108000005 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add person_id foreign key to physical_access_log table';
    }

    public function up(Schema $schema): void
    {
        // Add person_id column to physical_access_log
        $this->addSql('ALTER TABLE physical_access_log ADD person_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE physical_access_log ADD INDEX IDX_PHYSICAL_PERSON (person_id)');
        $this->addSql('ALTER TABLE physical_access_log
            ADD CONSTRAINT FK_PHYSICAL_PERSON FOREIGN KEY (person_id) REFERENCES person (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE physical_access_log DROP FOREIGN KEY FK_PHYSICAL_PERSON');
        $this->addSql('DROP INDEX IDX_PHYSICAL_PERSON ON physical_access_log');
        $this->addSql('ALTER TABLE physical_access_log DROP person_id');
    }
}
