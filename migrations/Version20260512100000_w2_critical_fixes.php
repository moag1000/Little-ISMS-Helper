<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Audit V3 W2 critical-fixes — schema additions for C3 + C4.
 *
 * - C3: creates `training_participation` (M:N Training x User) so the
 *       Auto-Reaction-Training-Assign listener can persist structured
 *       audit-trail rows instead of mutating a free-text marker on
 *       Training.participants.
 * - C4: extends `policy_acknowledgement` with `status` + `requested_at`
 *       and relaxes `acknowledged_at` / `acknowledgement_method` to
 *       NULL so the Auto-Acknowledgement-Campaign listener can persist
 *       PENDING audit-trail rows that the inbox UI later upgrades to
 *       ACKNOWLEDGED.
 *
 * Idempotent: every step checks information_schema first so partial
 * re-runs never fail. Plain `ALTER TABLE` / `CREATE TABLE` only —
 * no PREPARE/EXECUTE (CLAUDE.md pitfall #6).
 *
 * `isTransactional() = false`: every ALTER/CREATE TABLE implicitly
 * commits in MySQL; running >1 DDL migration in a single `migrate`
 * call without this override fails on the SAVEPOINT.
 */
final class Version20260512100000_w2_critical_fixes extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Audit V3 W2: create training_participation + extend '
            . 'policy_acknowledgement with status/requested_at and nullable '
            . 'acknowledgement_at/method (W2-C3 + W2-C4).';
    }

    public function up(Schema $schema): void
    {
        // ---------------- C3: training_participation ----------------
        if (!$this->tableExists('training_participation')) {
            $this->addSql(<<<'SQL'
                CREATE TABLE training_participation (
                    id INT AUTO_INCREMENT NOT NULL,
                    tenant_id INT NOT NULL,
                    training_id INT NOT NULL,
                    user_id INT NOT NULL,
                    status VARCHAR(24) NOT NULL DEFAULT 'pending',
                    assigned_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                    completed_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                    assignment_source VARCHAR(64) DEFAULT NULL,
                    score INT DEFAULT NULL,
                    PRIMARY KEY (id),
                    UNIQUE KEY uq_training_participation_training_user (training_id, user_id),
                    KEY idx_training_participation_tenant (tenant_id),
                    KEY idx_training_participation_user (user_id),
                    KEY idx_training_participation_status (status),
                    CONSTRAINT fk_tp_tenant FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE,
                    CONSTRAINT fk_tp_training FOREIGN KEY (training_id) REFERENCES training (id) ON DELETE CASCADE,
                    CONSTRAINT fk_tp_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
                ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
        }

        // ---------------- C4: policy_acknowledgement extension ----------------
        if (!$this->columnExists('policy_acknowledgement', 'status')) {
            $this->addSql(<<<'SQL'
                ALTER TABLE policy_acknowledgement
                    ADD COLUMN status VARCHAR(16) NOT NULL DEFAULT 'acknowledged'
            SQL);
        }
        if (!$this->columnExists('policy_acknowledgement', 'requested_at')) {
            $this->addSql(<<<'SQL'
                ALTER TABLE policy_acknowledgement
                    ADD COLUMN requested_at DATETIME DEFAULT NULL
                    COMMENT '(DC2Type:datetime_immutable)'
            SQL);
            // Backfill existing rows: requested_at = acknowledged_at so the
            // audit-trail snapshot is preserved monotonically.
            $this->addSql(<<<'SQL'
                UPDATE policy_acknowledgement
                   SET requested_at = acknowledged_at
                 WHERE requested_at IS NULL
            SQL);
            $this->addSql(<<<'SQL'
                ALTER TABLE policy_acknowledgement
                    MODIFY COLUMN requested_at DATETIME NOT NULL
                    COMMENT '(DC2Type:datetime_immutable)'
            SQL);
        }
        // Relax acknowledged_at + acknowledgement_method to NULL so PENDING
        // rows can be persisted by the auto-campaign listener.
        if (!$this->columnIsNullable('policy_acknowledgement', 'acknowledged_at')) {
            $this->addSql(<<<'SQL'
                ALTER TABLE policy_acknowledgement
                    MODIFY COLUMN acknowledged_at DATETIME DEFAULT NULL
                    COMMENT '(DC2Type:datetime_immutable)'
            SQL);
        }
        if (!$this->columnIsNullable('policy_acknowledgement', 'acknowledgement_method')) {
            $this->addSql(<<<'SQL'
                ALTER TABLE policy_acknowledgement
                    MODIFY COLUMN acknowledgement_method VARCHAR(24) DEFAULT NULL
            SQL);
        }
        if (!$this->indexExists('policy_acknowledgement', 'idx_policy_acknowledgement_status')) {
            $this->addSql(<<<'SQL'
                CREATE INDEX idx_policy_acknowledgement_status
                    ON policy_acknowledgement (status)
            SQL);
        }
    }

    public function down(Schema $schema): void
    {
        // C4 rollback
        if ($this->indexExists('policy_acknowledgement', 'idx_policy_acknowledgement_status')) {
            $this->addSql('DROP INDEX idx_policy_acknowledgement_status ON policy_acknowledgement');
        }
        if ($this->columnExists('policy_acknowledgement', 'requested_at')) {
            $this->addSql('ALTER TABLE policy_acknowledgement DROP COLUMN requested_at');
        }
        if ($this->columnExists('policy_acknowledgement', 'status')) {
            $this->addSql('ALTER TABLE policy_acknowledgement DROP COLUMN status');
        }
        // Re-tighten acknowledgement columns (best-effort: only when no PENDING rows remain)
        $this->addSql(<<<'SQL'
            UPDATE policy_acknowledgement
               SET acknowledged_at = NOW()
             WHERE acknowledged_at IS NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE policy_acknowledgement
                MODIFY COLUMN acknowledged_at DATETIME NOT NULL
                COMMENT '(DC2Type:datetime_immutable)'
        SQL);
        $this->addSql(<<<'SQL'
            UPDATE policy_acknowledgement
               SET acknowledgement_method = 'web_click'
             WHERE acknowledgement_method IS NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE policy_acknowledgement
                MODIFY COLUMN acknowledgement_method VARCHAR(24) NOT NULL
        SQL);

        // C3 rollback
        if ($this->tableExists('training_participation')) {
            $this->addSql('DROP TABLE training_participation');
        }
    }

    private function tableExists(string $table): bool
    {
        $row = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.tables
                WHERE table_schema = DATABASE()
                  AND table_name = :t',
            ['t' => $table],
        );
        return ((int) $row) > 0;
    }

    private function columnExists(string $table, string $column): bool
    {
        $row = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.columns
                WHERE table_schema = DATABASE()
                  AND table_name = :t
                  AND column_name = :c',
            ['t' => $table, 'c' => $column],
        );
        return ((int) $row) > 0;
    }

    private function columnIsNullable(string $table, string $column): bool
    {
        $row = $this->connection->fetchOne(
            'SELECT IS_NULLABLE FROM information_schema.columns
                WHERE table_schema = DATABASE()
                  AND table_name = :t
                  AND column_name = :c',
            ['t' => $table, 'c' => $column],
        );
        return $row === 'YES';
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $row = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.statistics
                WHERE table_schema = DATABASE()
                  AND table_name = :t
                  AND index_name = :i',
            ['t' => $table, 'i' => $indexName],
        );
        return ((int) $row) > 0;
    }
}
