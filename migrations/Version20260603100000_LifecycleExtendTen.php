<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Sprint Y.5 — Lifecycle extension for 10 additional entities.
 *
 * Adds `lock_version INT NOT NULL DEFAULT 0` to each of the 10 tables so the
 * Symfony Workflow / LifecycleService can detect and reject concurrent
 * status-transition conflicts (HTTP 409 via OptimisticLockException).
 *
 * Lifecycles activated by this migration (config/workflows/<name>.yaml):
 *   - training_lifecycle                          (ISO 27001 Cl. 7.2/7.3)
 *   - risk_treatment_plan_lifecycle               (ISO 27001 Cl. 6.1.3, 4-eyes on complete)
 *   - supplier_lifecycle                          (ISO 27001 A.5.19-A.5.22, 4-eyes on terminate)
 *   - prototype_protection_assessment_lifecycle   (TISAX VDA-ISA 6.0, 4-eyes on approve)
 *   - business_continuity_plan_lifecycle          (ISO 22301 Cl. 8.4, 4-eyes on activate)
 *   - patch_lifecycle                             (ISO 27001 A.8.32/A.8.8, 4-eyes on approve/deploy/rollback)
 *   - management_review_lifecycle                 (ISO 27001 Cl. 9.3, 4-eyes on complete)
 *   - change_request_lifecycle                    (ISO 27001 A.8.32, 4-eyes on approve/implement/close)
 *   - threat_intelligence_lifecycle               (NIS2 Art. 21(2)e + ISO 27001 A.5.7)
 *   - bc_exercise_lifecycle                       (ISO 22301 Cl. 8.5, 4-eyes on complete)
 *
 * CLAUDE.md pitfall #6: DDL migration — isTransactional() = false to avoid
 * SAVEPOINT errors when running multiple DDL migrations in a single migrate call.
 */
final class Version20260603100000_LifecycleExtendTen extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Sprint Y.5 — add lock_version to 10 lifecycle-extended entity tables';
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
            ['training', 'lock_version'],
            ['risk_treatment_plan', 'lock_version'],
            ['supplier', 'lock_version'],
            ['prototype_protection_assessment', 'lock_version'],
            ['business_continuity_plan', 'lock_version'],
            ['patches', 'lock_version'], // table name is plural — Patch entity
            ['management_review', 'lock_version'],
            ['change_request', 'lock_version'],
            ['threat_intelligence', 'lock_version'],
            ['bc_exercise', 'lock_version'],
        ];
    }
}
