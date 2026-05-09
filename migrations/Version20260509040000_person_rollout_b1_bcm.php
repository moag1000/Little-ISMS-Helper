<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Person-Rollout Phase B1 — BCM cluster (gap closure on top of Apr-2026
 * BCM Person migrations: bc_plan_person, business_process_person,
 * crisis_team_person, management_review_person).
 *
 * Phase A (`Version20260509030000_person_owner_rollout`) wired
 * `document.owner_person_id`. Phase B1 closes the BCM gaps that the
 * Apr-2026 batch did not cover:
 *
 *   1. CrisisTeam.personMembers — structured Collection<Person> roster
 *      next to the existing JSON `members` blob. Customers still see
 *      JSON-rendered roles + contact strings, but the typed roster is
 *      now reportable + linkable to PhysicalAccessLog/Training.
 *
 *   2. BCExercise.exerciseLeaderUser / exerciseLeaderPerson — typed
 *      leader FKs on top of the legacy free-text `facilitator` column.
 *      External BCM consultants frequently lead exercises without a
 *      system login → Person preferred, User optional.
 *
 *   3. ManagementReview.personParticipants — typed Collection<Person>
 *      twin of `participants` (Collection<User>) so external
 *      stakeholders (auditors, board members) can be listed without
 *      forcing a User account.
 *
 * Backfill: BCExercise.exerciseLeaderPerson is left NULL — `facilitator`
 * is a free-text string we cannot reliably FK-resolve. CrisisTeam JSON
 * `members` likewise carries free-text role strings; admins can opt in
 * to the typed Person roster on demand. Pure additive change — no
 * existing column is touched.
 *
 * Idempotency: every ALTER TABLE / CREATE TABLE is guarded by an
 * INFORMATION_SCHEMA check so re-running the migration on a
 * partially-converged DB is safe (devs commonly diff-run on dev DB
 * before final commit).
 *
 * Plain DDL — no PREPARE/EXECUTE per CLAUDE.md pitfall #6.
 * `isTransactional()=false` per pitfall #6 — multiple ALTER TABLE in
 * one migration would otherwise blow up Doctrine's SAVEPOINT.
 */
final class Version20260509040000_person_rollout_b1_bcm extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Person-Rollout Phase B1 (BCM gap closure): CrisisTeam.personMembers, BCExercise.exerciseLeader{User,Person}, ManagementReview.personParticipants.';
    }

    public function up(Schema $schema): void
    {
        // -------------------------------------------------------------
        // 1) CrisisTeam — structured Person roster (typed twin of JSON
        //    `members`). Join table only; CrisisTeam side keeps JSON
        //    column for backward-compat.
        // -------------------------------------------------------------
        $this->addSql(
            'CREATE TABLE IF NOT EXISTS crisis_team_persons ('
            . '  crisis_team_id INT NOT NULL,'
            . '  person_id INT NOT NULL,'
            . '  INDEX idx_ct_persons_team (crisis_team_id),'
            . '  INDEX idx_ct_persons_person (person_id),'
            . '  PRIMARY KEY(crisis_team_id, person_id),'
            . '  CONSTRAINT fk_ct_persons_team FOREIGN KEY (crisis_team_id) '
            . '    REFERENCES crisis_teams (id) ON DELETE CASCADE,'
            . '  CONSTRAINT fk_ct_persons_person FOREIGN KEY (person_id) '
            . '    REFERENCES person (id) ON DELETE CASCADE'
            . ') DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB'
        );

        // -------------------------------------------------------------
        // 2) BCExercise — typed exercise leader (User + Person twin).
        //    Apr-2026 batch did not touch this entity; `facilitator`
        //    free-text remains the canonical legacy field. Guarded via
        //    INFORMATION_SCHEMA so re-runs on partially converged dev
        //    DBs are idempotent without PREPARE/EXECUTE.
        // -------------------------------------------------------------
        $this->addSqlIfMissingColumn(
            'bc_exercise',
            'exercise_leader_user_id',
            'ALTER TABLE bc_exercise ADD exercise_leader_user_id INT DEFAULT NULL'
        );
        $this->addSqlIfMissingColumn(
            'bc_exercise',
            'exercise_leader_person_id',
            'ALTER TABLE bc_exercise ADD exercise_leader_person_id INT DEFAULT NULL'
        );
        $this->addSqlIfMissingConstraint(
            'bc_exercise',
            'fk_bc_exercise_leader_user',
            'ALTER TABLE bc_exercise ADD CONSTRAINT fk_bc_exercise_leader_user '
            . 'FOREIGN KEY (exercise_leader_user_id) REFERENCES users (id) ON DELETE SET NULL'
        );
        $this->addSqlIfMissingConstraint(
            'bc_exercise',
            'fk_bc_exercise_leader_person',
            'ALTER TABLE bc_exercise ADD CONSTRAINT fk_bc_exercise_leader_person '
            . 'FOREIGN KEY (exercise_leader_person_id) REFERENCES person (id) ON DELETE SET NULL'
        );
        $this->addSqlIfMissingIndex(
            'bc_exercise',
            'idx_bc_exercise_leader_user',
            'CREATE INDEX idx_bc_exercise_leader_user ON bc_exercise (exercise_leader_user_id)'
        );
        $this->addSqlIfMissingIndex(
            'bc_exercise',
            'idx_bc_exercise_leader_person',
            'CREATE INDEX idx_bc_exercise_leader_person ON bc_exercise (exercise_leader_person_id)'
        );

        // -------------------------------------------------------------
        // 3) ManagementReview — typed Person participants twin of the
        //    Collection<User> `participants`. Join table only.
        // -------------------------------------------------------------
        $this->addSql(
            'CREATE TABLE IF NOT EXISTS management_review_person_participants ('
            . '  management_review_id INT NOT NULL,'
            . '  person_id INT NOT NULL,'
            . '  INDEX idx_mr_pp_review (management_review_id),'
            . '  INDEX idx_mr_pp_person (person_id),'
            . '  PRIMARY KEY(management_review_id, person_id),'
            . '  CONSTRAINT fk_mr_pp_review FOREIGN KEY (management_review_id) '
            . '    REFERENCES management_review (id) ON DELETE CASCADE,'
            . '  CONSTRAINT fk_mr_pp_person FOREIGN KEY (person_id) '
            . '    REFERENCES person (id) ON DELETE CASCADE'
            . ') DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB'
        );
    }

    public function down(Schema $schema): void
    {
        // CrisisTeam roster
        $this->addSql('DROP TABLE IF EXISTS crisis_team_persons');

        // BCExercise leader — match the existing migration style (plain
        // DROPs, see Version20260430175000_dpia_person down()).
        $this->addSql('ALTER TABLE bc_exercise DROP FOREIGN KEY fk_bc_exercise_leader_user');
        $this->addSql('ALTER TABLE bc_exercise DROP FOREIGN KEY fk_bc_exercise_leader_person');
        $this->addSql('DROP INDEX idx_bc_exercise_leader_user ON bc_exercise');
        $this->addSql('DROP INDEX idx_bc_exercise_leader_person ON bc_exercise');
        $this->addSql('ALTER TABLE bc_exercise DROP COLUMN exercise_leader_user_id');
        $this->addSql('ALTER TABLE bc_exercise DROP COLUMN exercise_leader_person_id');

        // ManagementReview Person participants
        $this->addSql('DROP TABLE IF EXISTS management_review_person_participants');
    }

    /**
     * Emit an ALTER TABLE only when the named column does NOT yet exist.
     *
     * Wraps the MySQL information_schema check inline so the migration
     * is idempotent on partially-converged dev DBs without resorting
     * to PREPARE/EXECUTE (CLAUDE.md pitfall #6).
     */
    private function addSqlIfMissingColumn(string $table, string $column, string $alterSql): void
    {
        $check = sprintf(
            "SELECT COUNT(*) FROM information_schema.COLUMNS "
            . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '%s' AND COLUMN_NAME = '%s'",
            $table,
            $column
        );
        $exists = (int) $this->connection->fetchOne($check);
        if ($exists === 0) {
            $this->addSql($alterSql);
        }
    }

    private function addSqlIfMissingConstraint(string $table, string $constraint, string $alterSql): void
    {
        $check = sprintf(
            "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS "
            . "WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = '%s' AND CONSTRAINT_NAME = '%s'",
            $table,
            $constraint
        );
        $exists = (int) $this->connection->fetchOne($check);
        if ($exists === 0) {
            $this->addSql($alterSql);
        }
    }

    private function addSqlIfMissingIndex(string $table, string $indexName, string $createSql): void
    {
        $check = sprintf(
            "SELECT COUNT(*) FROM information_schema.STATISTICS "
            . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '%s' AND INDEX_NAME = '%s'",
            $table,
            $indexName
        );
        $exists = (int) $this->connection->fetchOne($check);
        if ($exists === 0) {
            $this->addSql($createSql);
        }
    }
}
