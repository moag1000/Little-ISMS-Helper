<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * WS-2: Add bsi_assurance_level to tenant table.
 * isTransactional() = false — ALTER TABLE commits implicitly (CLAUDE.md pitfall #6).
 */
final class Version20260610091000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'WS-2: tenant.bsi_assurance_level (basis/standard/kern, default standard)';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE tenant ADD bsi_assurance_level VARCHAR(20) DEFAULT 'standard' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tenant DROP bsi_assurance_level');
    }
}
