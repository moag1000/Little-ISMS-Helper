<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260517100100_AddLockVersionToDocument extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Lifecycle Foundation Pilot — @Version column on documents for optimistic locking';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE documents ADD COLUMN lock_version INT NOT NULL DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE documents DROP COLUMN lock_version');
    }
}
