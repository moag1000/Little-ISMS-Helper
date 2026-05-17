<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lifecycle X.2 — Optimistic-locking support for 10 custom-stage entities.
 *
 * Adds `lock_version INT NOT NULL DEFAULT 0` to each table so that the
 * Symfony Workflow / LifecycleService can detect and reject concurrent
 * status-transition conflicts (HTTP 409).
 *
 * Entities covered:
 *   - DataBreach              → data_breach
 *   - Incident                → incident
 *   - Risk                    → risk
 *   - DataProtectionImpact.   → data_protection_impact_assessment
 *   - CorrectiveAction        → corrective_actions
 *   - AuditFinding            → audit_findings
 *   - InternalAudit           → internal_audit
 *   - Vulnerability           → vulnerabilities
 *   - DataSubjectRequest      → data_subject_request
 *   - Consent                 → consent
 *
 * CLAUDE.md pitfall #6: DDL migration — isTransactional() = false to avoid
 * SAVEPOINT errors when running multiple migrations in a single migrate call.
 */
final class Version20260601000000_AddLockVersionMulti_X2 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Lifecycle X.2 — add lock_version to 10 custom-stage entity tables';
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
            ['data_breach', 'lock_version'],
            ['incident', 'lock_version'],
            ['risk', 'lock_version'],
            ['data_protection_impact_assessment', 'lock_version'],
            ['corrective_actions', 'lock_version'],
            ['audit_findings', 'lock_version'],
            ['internal_audit', 'lock_version'],
            ['vulnerabilities', 'lock_version'],
            ['data_subject_request', 'lock_version'],
            ['consent', 'lock_version'],
        ];
    }
}
