<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Sprint Y.0 — WorkflowInstance.status as Symfony state-machine.
 *
 * Adds two columns to `workflow_instances`:
 *   - lock_version INT NOT NULL DEFAULT 0  — Doctrine @Version for optimistic locking
 *   - current_step_index INT NOT NULL DEFAULT 0  — zero-based active-step pointer
 *
 * Backfill: existing rows keep their current status value unchanged; the
 * Symfony Workflow marking-store reads the `status` string property directly,
 * so all existing in-flight instances continue working without data migration.
 * lock_version defaults to 0 on all existing rows.
 *
 * CLAUDE.md pitfall #6: DDL migration — isTransactional() = false to avoid
 * SAVEPOINT errors when running multiple migrations in a single migrate call.
 */
final class Version20260602100000_WorkflowInstanceLifecycle extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Sprint Y.0 — add lock_version + current_step_index to workflow_instances';
    }

    public function isTransactional(): bool
    {
        return false; // DDL — CLAUDE.md pitfall #6
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('workflow_instances')) {
            $this->write('Skipping — workflow_instances table not found');
            return;
        }

        $table = $schema->getTable('workflow_instances');

        if (!$table->hasColumn('lock_version')) {
            $this->addSql('ALTER TABLE workflow_instances ADD lock_version INT NOT NULL DEFAULT 0');
        } else {
            $this->write('Skipping lock_version — column already exists');
        }

        if (!$table->hasColumn('current_step_index')) {
            $this->addSql('ALTER TABLE workflow_instances ADD current_step_index INT NOT NULL DEFAULT 0');
        } else {
            $this->write('Skipping current_step_index — column already exists');
        }
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('workflow_instances')) {
            return;
        }

        $table = $schema->getTable('workflow_instances');

        if ($table->hasColumn('current_step_index')) {
            $this->addSql('ALTER TABLE workflow_instances DROP COLUMN current_step_index');
        }

        if ($table->hasColumn('lock_version')) {
            $this->addSql('ALTER TABLE workflow_instances DROP COLUMN lock_version');
        }
    }
}
