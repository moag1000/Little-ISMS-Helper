<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lifecycle Sprint Y.5 PR B — Optimistic-locking support for BCM/TISAX bundle.
 *
 * Adds `lock_version INT NOT NULL DEFAULT 0` to each table so that the
 * Symfony Workflow / LifecycleService can detect and reject concurrent
 * status-transition conflicts (HTTP 409).
 *
 * Entities covered:
 *   - BusinessContinuityPlan          → business_continuity_plan
 *     ISO 22301 Cl. 8.4 + BSI 200-4
 *   - BCExercise                      → bc_exercise
 *     ISO 22301 Cl. 8.5
 *   - PrototypeProtectionAssessment   → prototype_protection_assessment
 *     TISAX / VDA-ISA 6.0 Kapitel 8
 *
 * CLAUDE.md pitfall #6: DDL migration — isTransactional() = false to avoid
 * SAVEPOINT errors when running multiple migrations in a single migrate call.
 */
final class Version20260603100000_AddLockVersionBcmBundle extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Lifecycle Y.5 PR B — add lock_version to BCM/TISAX entity tables (BCP, BCExercise, PrototypeProtection)';
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
            ['business_continuity_plan', 'lock_version'],
            ['bc_exercise', 'lock_version'],
            ['prototype_protection_assessment', 'lock_version'],
        ];
    }
}
