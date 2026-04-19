<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add skip_welcome_page boolean field to users table.
 * Persists the "skip welcome page" preference in the entity instead of session.
 */
final class Version20260419130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add skip_welcome_page column to users table for persisted welcome preference';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD skip_welcome_page TINYINT(1) DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP skip_welcome_page');
    }
}
