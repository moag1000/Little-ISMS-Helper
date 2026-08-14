<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Per-tenant module activation.
 *
 * Module activation used to live only in config/active_modules.yaml, i.e. one
 * shared set for the whole instance — activating a module for one tenant
 * activated it for every other tenant too. NULL keeps a tenant on the instance
 * default, so existing installations behave exactly as before until someone
 * edits modules for that tenant.
 */
final class Version20260813210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add tenant.active_modules (JSON, nullable) for per-tenant module activation';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE tenant ADD active_modules JSON DEFAULT NULL COMMENT 'Active module keys for this tenant; NULL = inherit instance default'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tenant DROP active_modules');
    }

    /** DDL commits implicitly on MySQL/MariaDB and would invalidate the savepoint. */
    public function isTransactional(): bool
    {
        return false;
    }
}
