<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Policy-Wizard W7-B — witness-field on the approval-trail.
 *
 * Adds two nullable columns to `workflow_instances`:
 *   - `witness_user_id` (FK → users.id, ON DELETE SET NULL)
 *   - `witnessed_at`    (DATETIME_IMMUTABLE)
 *
 * Captures GDPR DPO/CISO joint sign-off + BSI 4-eyes co-signature
 * beside the regular approver chain stored in `approval_history`.
 *
 * Spec: docs/plans/policy-wizard/07-phase4-sprint-reconciliation.md
 *       lines 302-304 (CISO "What's missing" Witnessing).
 *
 * CLAUDE.md migration rules:
 *  - plain SQL only (no PREPARE/EXECUTE);
 *  - INFORMATION_SCHEMA-guarded for idempotency;
 *  - isTransactional()=false because ALTER TABLE implicitly commits.
 */
final class Version20260509010000_policy_wizard_w7_witness_field extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Policy-Wizard W7-B: add witness_user_id + witnessed_at to '
            . 'workflow_instances for DPO/CISO joint approval co-signature.';
    }

    public function up(Schema $schema): void
    {
        $tableExists = (bool) $this->connection->fetchOne(
            <<<'SQL'
                SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'workflow_instances'
            SQL,
        );
        $this->abortIf(
            !$tableExists,
            'workflow_instances table missing — run earlier workflow migrations first',
        );

        $hasWitnessUser = (bool) $this->connection->fetchOne(
            <<<'SQL'
                SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'workflow_instances'
                   AND COLUMN_NAME = 'witness_user_id'
            SQL,
        );
        if (!$hasWitnessUser) {
            $this->addSql(<<<'SQL'
                ALTER TABLE workflow_instances
                    ADD COLUMN witness_user_id INT DEFAULT NULL
            SQL);
        }

        $hasWitnessedAt = (bool) $this->connection->fetchOne(
            <<<'SQL'
                SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'workflow_instances'
                   AND COLUMN_NAME = 'witnessed_at'
            SQL,
        );
        if (!$hasWitnessedAt) {
            $this->addSql(<<<'SQL'
                ALTER TABLE workflow_instances
                    ADD COLUMN witnessed_at DATETIME DEFAULT NULL
                    COMMENT '(DC2Type:datetime_immutable)'
            SQL);
        }

        $hasFk = (bool) $this->connection->fetchOne(
            <<<'SQL'
                SELECT COUNT(*) FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'workflow_instances'
                   AND COLUMN_NAME = 'witness_user_id'
                   AND REFERENCED_TABLE_NAME IS NOT NULL
            SQL,
        );
        if (!$hasFk) {
            $this->addSql(<<<'SQL'
                ALTER TABLE workflow_instances
                    ADD CONSTRAINT fk_workflow_instances_witness_user
                    FOREIGN KEY (witness_user_id) REFERENCES users (id)
                    ON DELETE SET NULL
            SQL);
        }

        $hasIndex = (bool) $this->connection->fetchOne(
            <<<'SQL'
                SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'workflow_instances'
                   AND INDEX_NAME = 'idx_workflow_instances_witness_user'
            SQL,
        );
        if (!$hasIndex) {
            $this->addSql(<<<'SQL'
                CREATE INDEX idx_workflow_instances_witness_user
                    ON workflow_instances (witness_user_id)
            SQL);
        }
    }

    public function down(Schema $schema): void
    {
        $hasFk = (bool) $this->connection->fetchOne(
            <<<'SQL'
                SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'workflow_instances'
                   AND CONSTRAINT_NAME = 'fk_workflow_instances_witness_user'
            SQL,
        );
        if ($hasFk) {
            $this->addSql(<<<'SQL'
                ALTER TABLE workflow_instances
                    DROP FOREIGN KEY fk_workflow_instances_witness_user
            SQL);
        }

        $hasIndex = (bool) $this->connection->fetchOne(
            <<<'SQL'
                SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'workflow_instances'
                   AND INDEX_NAME = 'idx_workflow_instances_witness_user'
            SQL,
        );
        if ($hasIndex) {
            $this->addSql(<<<'SQL'
                DROP INDEX idx_workflow_instances_witness_user
                    ON workflow_instances
            SQL);
        }

        $hasWitnessedAt = (bool) $this->connection->fetchOne(
            <<<'SQL'
                SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'workflow_instances'
                   AND COLUMN_NAME = 'witnessed_at'
            SQL,
        );
        if ($hasWitnessedAt) {
            $this->addSql(<<<'SQL'
                ALTER TABLE workflow_instances DROP COLUMN witnessed_at
            SQL);
        }

        $hasWitnessUser = (bool) $this->connection->fetchOne(
            <<<'SQL'
                SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'workflow_instances'
                   AND COLUMN_NAME = 'witness_user_id'
            SQL,
        );
        if ($hasWitnessUser) {
            $this->addSql(<<<'SQL'
                ALTER TABLE workflow_instances DROP COLUMN witness_user_id
            SQL);
        }
    }
}
