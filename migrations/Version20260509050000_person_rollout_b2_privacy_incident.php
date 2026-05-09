<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Person-Rollout Phase B2 — Privacy + Incident + Audit cluster.
 *
 * Adds governance-side Person FKs alongside existing User/string FKs for
 * three remaining fields where the current schema only carries the
 * action-bound assignment:
 *
 *   - `incident.responsible_person_id` — long-term accountable Person
 *     (independent of `assigned_to` ticket assignee + `reported_by_*`
 *     audit-trail).
 *   - `data_subject_request.dpo_person_id` — governance-side DPO
 *     accountable for the DSR (vs `assigned_to` action handler).
 *   - `compliance_requirement_fulfillment.attestation_owner_person_id`
 *     — Person responsible for the yearly attestation sign-off (vs
 *     `responsible_person_*` day-to-day owner).
 *
 * Backfill: copies `linked_user_id` resolution from the closest existing
 * User FK on the same row (assigned_to / responsible_person_id /
 * assigned_to). Idempotent — re-running leaves rows already populated
 * untouched.
 *
 * Plain ALTER per CLAUDE.md pitfall #6 (no PREPARE/EXECUTE).
 * `isTransactional()=false` per pitfall #6 — multiple ALTER TABLE
 * statements implicitly commit and would invalidate Doctrine's
 * SAVEPOINT.
 */
final class Version20260509050000_person_rollout_b2_privacy_incident extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Person-Rollout Phase B2 — add Person FKs for Incident.responsiblePerson, DataSubjectRequest.dpoPerson, ComplianceRequirementFulfillment.attestationOwnerPerson; backfill from related User FK linked_user resolution.';
    }

    public function up(Schema $schema): void
    {
        // ---------------------------------------------------------------
        // 1) Incident.responsible_person_id — governance owner separate
        //    from the action-bound assigned_to / reported_by_user FKs.
        // ---------------------------------------------------------------
        $this->addSql('ALTER TABLE incident ADD responsible_person_id INT DEFAULT NULL');
        $this->addSql(
            'ALTER TABLE incident ADD CONSTRAINT fk_incident_responsible_person '
            . 'FOREIGN KEY (responsible_person_id) REFERENCES person (id) ON DELETE SET NULL'
        );
        $this->addSql('CREATE INDEX idx_incident_responsible_person ON incident (responsible_person_id)');

        // Backfill: when reported_by_user has a Person profile, treat as
        // initial governance owner. Idempotent guard: only NULL rows.
        $this->addSql(
            'UPDATE incident i '
            . 'INNER JOIN person p ON p.linked_user_id = i.reported_by_user_id '
            . 'SET i.responsible_person_id = p.id '
            . 'WHERE i.responsible_person_id IS NULL '
            . '  AND i.reported_by_user_id IS NOT NULL'
        );

        // ---------------------------------------------------------------
        // 2) DataSubjectRequest.dpo_person_id — governance DPO,
        //    distinct from assigned_to (action handler).
        // ---------------------------------------------------------------
        $this->addSql('ALTER TABLE data_subject_request ADD dpo_person_id INT DEFAULT NULL');
        $this->addSql(
            'ALTER TABLE data_subject_request ADD CONSTRAINT fk_dsr_dpo_person '
            . 'FOREIGN KEY (dpo_person_id) REFERENCES person (id) ON DELETE SET NULL'
        );
        $this->addSql('CREATE INDEX idx_dsr_dpo_person ON data_subject_request (dpo_person_id)');

        // Backfill: assigned_to.linkedPerson when present (the assigned
        // user is often the DPO in single-DPO orgs). Admins re-assign
        // later if the DPO is a different Person.
        $this->addSql(
            'UPDATE data_subject_request d '
            . 'INNER JOIN person p ON p.linked_user_id = d.assigned_to_id '
            . 'SET d.dpo_person_id = p.id '
            . 'WHERE d.dpo_person_id IS NULL '
            . '  AND d.assigned_to_id IS NOT NULL'
        );

        // ---------------------------------------------------------------
        // 3) ComplianceRequirementFulfillment.attestation_owner_person_id
        //    — yearly attestation sign-off Person, distinct from the
        //    day-to-day responsible_person_*.
        // ---------------------------------------------------------------
        $this->addSql('ALTER TABLE compliance_requirement_fulfillment ADD attestation_owner_person_id INT DEFAULT NULL');
        $this->addSql(
            'ALTER TABLE compliance_requirement_fulfillment ADD CONSTRAINT fk_crf_attestation_owner_person '
            . 'FOREIGN KEY (attestation_owner_person_id) REFERENCES person (id) ON DELETE SET NULL'
        );
        $this->addSql('CREATE INDEX idx_crf_attestation_owner_person ON compliance_requirement_fulfillment (attestation_owner_person_id)');

        // Backfill: copy the existing responsible_person_person_id when
        // present (pre-existing Person FK = governance) so the new
        // attestation-owner picker is pre-populated without forcing
        // admins to re-pick.
        $this->addSql(
            'UPDATE compliance_requirement_fulfillment c '
            . 'SET c.attestation_owner_person_id = c.responsible_person_person_id '
            . 'WHERE c.attestation_owner_person_id IS NULL '
            . '  AND c.responsible_person_person_id IS NOT NULL'
        );
        // Secondary backfill: where no Person FK exists yet but a User
        // FK does, resolve via linked_user_id.
        $this->addSql(
            'UPDATE compliance_requirement_fulfillment c '
            . 'INNER JOIN person p ON p.linked_user_id = c.responsible_person_id '
            . 'SET c.attestation_owner_person_id = p.id '
            . 'WHERE c.attestation_owner_person_id IS NULL '
            . '  AND c.responsible_person_id IS NOT NULL'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compliance_requirement_fulfillment DROP FOREIGN KEY fk_crf_attestation_owner_person');
        $this->addSql('DROP INDEX idx_crf_attestation_owner_person ON compliance_requirement_fulfillment');
        $this->addSql('ALTER TABLE compliance_requirement_fulfillment DROP COLUMN attestation_owner_person_id');

        $this->addSql('ALTER TABLE data_subject_request DROP FOREIGN KEY fk_dsr_dpo_person');
        $this->addSql('DROP INDEX idx_dsr_dpo_person ON data_subject_request');
        $this->addSql('ALTER TABLE data_subject_request DROP COLUMN dpo_person_id');

        $this->addSql('ALTER TABLE incident DROP FOREIGN KEY fk_incident_responsible_person');
        $this->addSql('DROP INDEX idx_incident_responsible_person ON incident');
        $this->addSql('ALTER TABLE incident DROP COLUMN responsible_person_id');
    }
}
