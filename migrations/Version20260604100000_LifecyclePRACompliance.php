<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lifecycle Y.5 PR-A — Optimistic-locking support for compliance-critical entities.
 *
 * Adds `lock_version INT NOT NULL DEFAULT 0` to:
 *   - change_request          (ISO 27001 A.8.32, DORA Art. 16)
 *   - patches                 (ISO 27001 A.8.32 + A.8.8, NIS2 21(2)e)
 *   - management_review       (ISO 27001 Cl. 9.3)
 *   - risk_treatment_plan     (ISO 27001 Cl. 6.1.3)
 *
 * Required by Symfony Workflow / LifecycleService to detect concurrent
 * transition conflicts (HTTP 409).
 *
 * CLAUDE.md pitfall #6: DDL migration — isTransactional() = false to avoid
 * SAVEPOINT errors when running multiple migrations in a single migrate call.
 */
final class Version20260604100000_LifecyclePRACompliance extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Lifecycle Y.5 PR-A — add lock_version to change_request, patches, management_review, risk_treatment_plan';
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
            ['change_request', 'lock_version'],
            ['patches', 'lock_version'],
            ['management_review', 'lock_version'],
            ['risk_treatment_plan', 'lock_version'],
        ];
    }
}
