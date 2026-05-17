<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lifecycle X.1 — Optimistic-locking support for ProcessingActivity and ISMSObjective.
 *
 * Adds `lock_version INT NOT NULL DEFAULT 0` to each table so that the
 * Symfony Workflow / LifecycleService can detect and reject concurrent
 * status-transition conflicts (HTTP 409).
 *
 * CLAUDE.md pitfall #6: DDL migration — isTransactional() = false to avoid
 * SAVEPOINT errors when running multiple migrations in a single migrate call.
 */
final class Version20260526100000_AddLockVersionMulti extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Lifecycle X.1 — add lock_version to processing_activity and isms_objective';
    }

    public function isTransactional(): bool
    {
        return false; // DDL — CLAUDE.md pitfall #6
    }

    public function up(Schema $schema): void
    {
        foreach ($this->tableColumnPairs() as [$table, $column]) {
            if (!$schema->hasTable($table)) {
                $this->write(sprintf('Skipping %s.%s — table not found', $table, $column));
                continue;
            }
            if ($schema->getTable($table)->hasColumn($column)) {
                $this->write(sprintf('Skipping %s.%s — column already exists', $table, $column));
                continue;
            }
            $this->addSql(
                sprintf('ALTER TABLE %s ADD %s INT NOT NULL DEFAULT 0', $table, $column)
            );
        }
    }

    public function down(Schema $schema): void
    {
        foreach ($this->tableColumnPairs() as [$table, $column]) {
            if (!$schema->hasTable($table)) {
                continue;
            }
            if (!$schema->getTable($table)->hasColumn($column)) {
                continue;
            }
            $this->addSql(sprintf('ALTER TABLE %s DROP COLUMN %s', $table, $column));
        }
    }

    /** @return list<array{0: string, 1: string}> */
    private function tableColumnPairs(): array
    {
        return [
            ['processing_activity', 'lock_version'],
            ['isms_objective', 'lock_version'],
        ];
    }
}
