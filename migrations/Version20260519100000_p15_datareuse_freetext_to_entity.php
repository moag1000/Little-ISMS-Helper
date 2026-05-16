<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * S4 Foundation-Pattern P-15 — DataReuse-Reflex.
 *
 * Replaces free-text fields with structured EntityType relations across three
 * entities (InternalAudit / Training / BCExercise). Pure additive DDL — every
 * legacy free-text column stays in place as read-only migration display, so
 * existing tenant data is never lost.
 *
 * Schema changes
 * --------------
 *   InternalAudit:
 *     + internal_audit.lead_auditor_user_id    (FK users   ON DELETE SET NULL)
 *     + internal_audit.lead_auditor_person_id  (FK person  ON DELETE SET NULL)
 *     ~ internal_audit.lead_auditor: NOT NULL → NULL (legacy free-text now
 *       optional once Pattern-A user/person is provided)
 *     + internal_audit_team_member (M2M: internal_audit_id × person_id)
 *
 *   BCExercise:
 *     + bc_exercise.facilitator_user_id        (FK users   ON DELETE SET NULL)
 *     + bc_exercise.facilitator_person_id      (FK person  ON DELETE SET NULL)
 *     ~ bc_exercise.facilitator: NOT NULL → NULL
 *     ~ bc_exercise.participants: NOT NULL → NULL
 *     + bc_exercise_participant_person (M2M: bc_exercise_id × person_id)
 *     + bc_exercise_observer_person    (M2M: bc_exercise_id × person_id)
 *
 *   Training:
 *     - none (participantUsers is a transient form property; the canonical
 *       persistence is `training_participation` which already exists).
 *
 * Idempotency: every ALTER/CREATE uses INFORMATION_SCHEMA checks, mirroring
 * the Apr-2026 person-rollout migrations (CLAUDE.md pitfall #6 — no
 * PREPARE/EXECUTE).
 *
 * `isTransactional()=false` — multiple ALTER/CREATE in one migration each
 * implicitly commit, which would break Doctrine's SAVEPOINT.
 */
final class Version20260519100000_p15_datareuse_freetext_to_entity extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'S4 P-15 DataReuse: InternalAudit lead_auditor_{user,person} + team_member M2M; BCExercise facilitator_{user,person} + participant/observer M2M; relax NOT-NULL on legacy free-text columns.';
    }

    public function up(Schema $schema): void
    {
        // -------------------------------------------------------------
        // 1) InternalAudit — Pattern A lead auditor (User + Person) +
        //    typed audit-team-members M2M.
        // -------------------------------------------------------------
        $this->addSqlIfMissingColumn(
            'internal_audit',
            'lead_auditor_user_id',
            'ALTER TABLE internal_audit ADD lead_auditor_user_id INT DEFAULT NULL'
        );
        $this->addSqlIfMissingColumn(
            'internal_audit',
            'lead_auditor_person_id',
            'ALTER TABLE internal_audit ADD lead_auditor_person_id INT DEFAULT NULL'
        );
        $this->addSqlIfMissingConstraint(
            'internal_audit',
            'fk_internal_audit_lead_auditor_user',
            'ALTER TABLE internal_audit ADD CONSTRAINT fk_internal_audit_lead_auditor_user '
            . 'FOREIGN KEY (lead_auditor_user_id) REFERENCES users (id) ON DELETE SET NULL'
        );
        $this->addSqlIfMissingConstraint(
            'internal_audit',
            'fk_internal_audit_lead_auditor_person',
            'ALTER TABLE internal_audit ADD CONSTRAINT fk_internal_audit_lead_auditor_person '
            . 'FOREIGN KEY (lead_auditor_person_id) REFERENCES person (id) ON DELETE SET NULL'
        );
        $this->addSqlIfMissingIndex(
            'internal_audit',
            'idx_internal_audit_lead_auditor_user',
            'CREATE INDEX idx_internal_audit_lead_auditor_user ON internal_audit (lead_auditor_user_id)'
        );
        $this->addSqlIfMissingIndex(
            'internal_audit',
            'idx_internal_audit_lead_auditor_person',
            'CREATE INDEX idx_internal_audit_lead_auditor_person ON internal_audit (lead_auditor_person_id)'
        );

        // Relax legacy NOT-NULL on `lead_auditor` so Pattern-A flows without
        // legacy data can persist. Form-level Callback enforces "at least one
        // of user/person/legacy".
        $this->relaxNotNullIfRequired('internal_audit', 'lead_auditor', 'VARCHAR(100) DEFAULT NULL');

        // Team-member M2M (Collection<Person>)
        $this->addSql(
            'CREATE TABLE IF NOT EXISTS internal_audit_team_member ('
            . '  internal_audit_id INT NOT NULL,'
            . '  person_id INT NOT NULL,'
            . '  INDEX idx_iatm_audit (internal_audit_id),'
            . '  INDEX idx_iatm_person (person_id),'
            . '  PRIMARY KEY(internal_audit_id, person_id),'
            . '  CONSTRAINT fk_iatm_audit FOREIGN KEY (internal_audit_id) '
            . '    REFERENCES internal_audit (id) ON DELETE CASCADE,'
            . '  CONSTRAINT fk_iatm_person FOREIGN KEY (person_id) '
            . '    REFERENCES person (id) ON DELETE CASCADE'
            . ') DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB'
        );

        // -------------------------------------------------------------
        // 2) BCExercise — Pattern A facilitator (User + Person) +
        //    participant/observer M2M (Collection<Person>).
        // -------------------------------------------------------------
        $this->addSqlIfMissingColumn(
            'bc_exercise',
            'facilitator_user_id',
            'ALTER TABLE bc_exercise ADD facilitator_user_id INT DEFAULT NULL'
        );
        $this->addSqlIfMissingColumn(
            'bc_exercise',
            'facilitator_person_id',
            'ALTER TABLE bc_exercise ADD facilitator_person_id INT DEFAULT NULL'
        );
        $this->addSqlIfMissingConstraint(
            'bc_exercise',
            'fk_bc_exercise_facilitator_user',
            'ALTER TABLE bc_exercise ADD CONSTRAINT fk_bc_exercise_facilitator_user '
            . 'FOREIGN KEY (facilitator_user_id) REFERENCES users (id) ON DELETE SET NULL'
        );
        $this->addSqlIfMissingConstraint(
            'bc_exercise',
            'fk_bc_exercise_facilitator_person',
            'ALTER TABLE bc_exercise ADD CONSTRAINT fk_bc_exercise_facilitator_person '
            . 'FOREIGN KEY (facilitator_person_id) REFERENCES person (id) ON DELETE SET NULL'
        );
        $this->addSqlIfMissingIndex(
            'bc_exercise',
            'idx_bc_exercise_facilitator_user',
            'CREATE INDEX idx_bc_exercise_facilitator_user ON bc_exercise (facilitator_user_id)'
        );
        $this->addSqlIfMissingIndex(
            'bc_exercise',
            'idx_bc_exercise_facilitator_person',
            'CREATE INDEX idx_bc_exercise_facilitator_person ON bc_exercise (facilitator_person_id)'
        );

        // Relax legacy NOT-NULL on `facilitator` + `participants`.
        $this->relaxNotNullIfRequired('bc_exercise', 'facilitator', 'VARCHAR(100) DEFAULT NULL');
        $this->relaxNotNullIfRequired('bc_exercise', 'participants', 'LONGTEXT DEFAULT NULL');

        // Participant M2M (Collection<Person>)
        $this->addSql(
            'CREATE TABLE IF NOT EXISTS bc_exercise_participant_person ('
            . '  bc_exercise_id INT NOT NULL,'
            . '  person_id INT NOT NULL,'
            . '  INDEX idx_bcepp_exercise (bc_exercise_id),'
            . '  INDEX idx_bcepp_person (person_id),'
            . '  PRIMARY KEY(bc_exercise_id, person_id),'
            . '  CONSTRAINT fk_bcepp_exercise FOREIGN KEY (bc_exercise_id) '
            . '    REFERENCES bc_exercise (id) ON DELETE CASCADE,'
            . '  CONSTRAINT fk_bcepp_person FOREIGN KEY (person_id) '
            . '    REFERENCES person (id) ON DELETE CASCADE'
            . ') DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB'
        );

        // Observer M2M (Collection<Person>)
        $this->addSql(
            'CREATE TABLE IF NOT EXISTS bc_exercise_observer_person ('
            . '  bc_exercise_id INT NOT NULL,'
            . '  person_id INT NOT NULL,'
            . '  INDEX idx_bceop_exercise (bc_exercise_id),'
            . '  INDEX idx_bceop_person (person_id),'
            . '  PRIMARY KEY(bc_exercise_id, person_id),'
            . '  CONSTRAINT fk_bceop_exercise FOREIGN KEY (bc_exercise_id) '
            . '    REFERENCES bc_exercise (id) ON DELETE CASCADE,'
            . '  CONSTRAINT fk_bceop_person FOREIGN KEY (person_id) '
            . '    REFERENCES person (id) ON DELETE CASCADE'
            . ') DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB'
        );
    }

    public function down(Schema $schema): void
    {
        // BCExercise M2M tables
        $this->addSql('DROP TABLE IF EXISTS bc_exercise_observer_person');
        $this->addSql('DROP TABLE IF EXISTS bc_exercise_participant_person');

        // BCExercise facilitator FKs / columns
        $this->dropConstraintIfExists('bc_exercise', 'fk_bc_exercise_facilitator_person');
        $this->dropConstraintIfExists('bc_exercise', 'fk_bc_exercise_facilitator_user');
        $this->dropIndexIfExists('bc_exercise', 'idx_bc_exercise_facilitator_person');
        $this->dropIndexIfExists('bc_exercise', 'idx_bc_exercise_facilitator_user');
        $this->dropColumnIfExists('bc_exercise', 'facilitator_person_id');
        $this->dropColumnIfExists('bc_exercise', 'facilitator_user_id');
        // We intentionally do NOT re-tighten NOT-NULL on `facilitator` /
        // `participants` — relaxation is safe to leave behind.

        // InternalAudit team M2M
        $this->addSql('DROP TABLE IF EXISTS internal_audit_team_member');

        // InternalAudit lead-auditor FKs / columns
        $this->dropConstraintIfExists('internal_audit', 'fk_internal_audit_lead_auditor_person');
        $this->dropConstraintIfExists('internal_audit', 'fk_internal_audit_lead_auditor_user');
        $this->dropIndexIfExists('internal_audit', 'idx_internal_audit_lead_auditor_person');
        $this->dropIndexIfExists('internal_audit', 'idx_internal_audit_lead_auditor_user');
        $this->dropColumnIfExists('internal_audit', 'lead_auditor_person_id');
        $this->dropColumnIfExists('internal_audit', 'lead_auditor_user_id');
    }

    // ── helpers (CLAUDE.md pitfall #6: no PREPARE/EXECUTE) ───────────

    private function addSqlIfMissingColumn(string $table, string $column, string $alterSql): void
    {
        $exists = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.COLUMNS "
            . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
            [$table, $column]
        );
        if ($exists === 0) {
            $this->addSql($alterSql);
        }
    }

    private function addSqlIfMissingConstraint(string $table, string $constraint, string $alterSql): void
    {
        $exists = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS "
            . "WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?",
            [$table, $constraint]
        );
        if ($exists === 0) {
            $this->addSql($alterSql);
        }
    }

    private function addSqlIfMissingIndex(string $table, string $indexName, string $createSql): void
    {
        $exists = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.STATISTICS "
            . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?",
            [$table, $indexName]
        );
        if ($exists === 0) {
            $this->addSql($createSql);
        }
    }

    /**
     * Drop NOT NULL constraint by ALTER ... MODIFY COLUMN, but only when the
     * column is currently NOT NULL. Safe re-run on partially-converged DBs.
     */
    private function relaxNotNullIfRequired(string $table, string $column, string $definition): void
    {
        $isNullable = $this->connection->fetchOne(
            "SELECT IS_NULLABLE FROM information_schema.COLUMNS "
            . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
            [$table, $column]
        );
        if ($isNullable === 'NO') {
            $this->addSql(sprintf('ALTER TABLE %s MODIFY COLUMN %s %s', $table, $column, $definition));
        }
    }

    private function dropConstraintIfExists(string $table, string $constraint): void
    {
        $exists = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS "
            . "WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?",
            [$table, $constraint]
        );
        if ($exists > 0) {
            $this->addSql(sprintf('ALTER TABLE %s DROP FOREIGN KEY %s', $table, $constraint));
        }
    }

    private function dropIndexIfExists(string $table, string $indexName): void
    {
        $exists = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.STATISTICS "
            . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?",
            [$table, $indexName]
        );
        if ($exists > 0) {
            $this->addSql(sprintf('DROP INDEX %s ON %s', $indexName, $table));
        }
    }

    private function dropColumnIfExists(string $table, string $column): void
    {
        $exists = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.COLUMNS "
            . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
            [$table, $column]
        );
        if ($exists > 0) {
            $this->addSql(sprintf('ALTER TABLE %s DROP COLUMN %s', $table, $column));
        }
    }
}
