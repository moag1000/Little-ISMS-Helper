<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lifecycle — PolicyTemplate: add `status` + `lock_version` columns.
 *
 * Unblocks the `policy-template` slug in EntityTypeRegistry (was deferred in
 * X.1 because PolicyTemplate had no `status` field — used `isActive: bool`).
 *
 * Backfill: rows with `is_active = 1` receive `status = 'published'`;
 * all other rows default to `'draft'` (the column DEFAULT).
 *
 * CLAUDE.md pitfall #6: DDL migration — isTransactional() = false to avoid
 * SAVEPOINT errors when running multiple migrations in a single migrate call.
 */
final class Version20260527100000_PolicyTemplateLifecycle extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Lifecycle — add status + lock_version to policy_template; backfill active rows to published';
    }

    public function isTransactional(): bool
    {
        return false; // DDL — CLAUDE.md pitfall #6
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('policy_template')) {
            $this->write('Skipping — table policy_template not found');
            return;
        }

        $table = $schema->getTable('policy_template');

        if (!$table->hasColumn('status')) {
            $this->addSql("ALTER TABLE policy_template ADD COLUMN status VARCHAR(32) NOT NULL DEFAULT 'draft'");
            // Backfill: templates already marked active become 'published'
            $this->addSql("UPDATE policy_template SET status = 'published' WHERE is_active = 1");
        } else {
            $this->write('Skipping policy_template.status — column already exists');
        }

        if (!$table->hasColumn('lock_version')) {
            $this->addSql('ALTER TABLE policy_template ADD COLUMN lock_version INT NOT NULL DEFAULT 0');
        } else {
            $this->write('Skipping policy_template.lock_version — column already exists');
        }
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('policy_template')) {
            return;
        }

        $table = $schema->getTable('policy_template');

        if ($table->hasColumn('lock_version')) {
            $this->addSql('ALTER TABLE policy_template DROP COLUMN lock_version');
        }

        if ($table->hasColumn('status')) {
            $this->addSql('ALTER TABLE policy_template DROP COLUMN status');
        }
    }
}
