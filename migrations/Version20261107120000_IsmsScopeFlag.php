<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ISO 27001 clause 4.3: in_isms_scope flag on asset, location, business_process
 * and supplier. Existing rows stay in scope.
 */
final class Version20261107120000_IsmsScopeFlag extends AbstractMigration
{
    private const TABLES = ['asset', 'location', 'business_process', 'supplier'];

    public function getDescription(): string
    {
        return 'Add in_isms_scope (ISO 27001 4.3) to asset, location, business_process, supplier';
    }

    public function isTransactional(): bool
    {
        // ALTER TABLE commits implicitly in MySQL.
        return false;
    }

    public function up(Schema $schema): void
    {
        foreach (self::TABLES as $table) {
            $this->addSql(sprintf('ALTER TABLE %s ADD in_isms_scope TINYINT(1) DEFAULT 1 NOT NULL', $table));
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::TABLES as $table) {
            $this->addSql(sprintf('ALTER TABLE %s DROP in_isms_scope', $table));
        }
    }
}
