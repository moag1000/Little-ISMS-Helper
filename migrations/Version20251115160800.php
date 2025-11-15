<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add requiredModules JSON field to compliance_framework table
 */
final class Version20251115160800 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add requiredModules JSON field to compliance_framework table to track module dependencies';
    }

    public function up(Schema $schema): void
    {
        // Add requiredModules JSON column
        $this->addSql('ALTER TABLE compliance_framework ADD required_modules JSON DEFAULT NULL COMMENT \'(DC2Type:json)\'');
    }

    public function down(Schema $schema): void
    {
        // Remove requiredModules column
        $this->addSql('ALTER TABLE compliance_framework DROP required_modules');
    }
}
