<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Assistant connection (MCP): a token may write records only when its owner
 * ticked "Assistent darf schreiben" at creation. Existing tokens stay read-only.
 */
final class Version20261108120000_ApiTokenCanWrite extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'API token: write permission for the assistant connection (MCP)';
    }

    public function isTransactional(): bool
    {
        // ALTER TABLE commits implicitly in MySQL (invalidates the SAVEPOINT).
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE api_tokens ADD can_write TINYINT(1) DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE api_tokens DROP COLUMN can_write');
    }
}
