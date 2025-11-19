<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251119161401 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add category field to risk table (ISO 27005:2022 Section 8.2.3 - Risk Categorization)';
    }

    public function up(Schema $schema): void
    {
        // Add category field with default value for existing risks
        $this->addSql('ALTER TABLE risk ADD category VARCHAR(100) NOT NULL DEFAULT \'operational\'');

        // Remove default after adding the column (new risks must specify category)
        $this->addSql('ALTER TABLE risk ALTER COLUMN category DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE risk DROP category');
    }
}
