<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Phase 3.1 — Add menu_density column to users table.
 * Backed enum MenuDensity (basic|standard|expert), default: standard.
 */
final class Version20260619100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add menu_density column to users table for UI density preference (Phase 3.1)';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "ALTER TABLE users ADD COLUMN IF NOT EXISTS menu_density VARCHAR(16) NOT NULL DEFAULT 'standard'"
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP COLUMN IF EXISTS menu_density');
    }
}
