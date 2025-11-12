<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add logoPath field to tenant table for logo upload support
 */
final class Version20251113000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add logoPath field to tenant table for logo upload support';
    }

    public function up(Schema $schema): void
    {
        // Add logo_path column to tenant table
        $this->addSql('ALTER TABLE tenant ADD logo_path VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // Remove logo_path column from tenant table
        $this->addSql('ALTER TABLE tenant DROP logo_path');
    }
}
