<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lifecycle PR C — Supplier (ISO 27001 A.5.19–A.5.22) state-machine.
 *
 * Adds the `lock_version` optimistic-locking column required by
 * LifecycleService to detect concurrent transition conflicts (HTTP 409).
 *
 * Workflow definition: config/workflows/supplier.yaml
 *   Places: active, inactive, evaluation, terminated
 *   Initial marking: evaluation
 *   Four-eyes: `terminate` (ISO 27001 A.5.20 — contractual termination).
 *
 * No status backfill needed — the Supplier entity defaults to 'evaluation'
 * and existing rows already carry valid status values from the legacy
 * Assert\Choice constraint (active / inactive / evaluation / terminated).
 *
 * CLAUDE.md pitfall #6: DDL migration — isTransactional() = false.
 */
final class Version20260603120000_SupplierLifecycle extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Lifecycle PR C — add lock_version to supplier for Symfony Workflow optimistic locking';
    }

    public function isTransactional(): bool
    {
        return false; // DDL — CLAUDE.md pitfall #6
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('supplier')) {
            $this->write('Skipping — supplier table not found');
            return;
        }

        $table = $schema->getTable('supplier');

        if (!$table->hasColumn('lock_version')) {
            $this->addSql('ALTER TABLE supplier ADD COLUMN lock_version INT NOT NULL DEFAULT 0');
        } else {
            $this->write('Skipping supplier.lock_version — column already exists');
        }
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('supplier')) {
            return;
        }

        $table = $schema->getTable('supplier');

        if ($table->hasColumn('lock_version')) {
            $this->addSql('ALTER TABLE supplier DROP COLUMN lock_version');
        }
    }
}
