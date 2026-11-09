<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * S30: optimistic-lock column on the suggestion queue, so two decisions on the
 * same suggestion (double click, two people) cannot both apply it. Existing
 * rows start at 0.
 */
final class Version20261109200000_SuggestionLockVersion extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add lock_version to suggestion';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('suggestion') && $schema->getTable('suggestion')->hasColumn('lock_version')) {
            return;
        }
        $this->addSql('ALTER TABLE suggestion ADD lock_version INT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE suggestion DROP lock_version');
    }

    /** DDL commits implicitly on MySQL/MariaDB and would invalidate the savepoint. */
    public function isTransactional(): bool
    {
        return false;
    }
}
