<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Person-Rollout Phase A — additive Person FK on `document` table for the
 * governance-side document owner. Plus an indexed inverse-side hint
 * column for User → Person discovery.
 *
 * Asset, Risk and Control already carry their Person FK columns
 * (Pattern A live since 2026-04-30 / 2026-05-01). Phase B sprints will
 * cover BCM / Privacy / Incident / Compliance clusters per
 * `docs/plans/person-rollout-audit.md`.
 *
 * Backfill (idempotent):
 *   - For every `document` row whose `uploaded_by_id` points at a User
 *     that has a `person.linked_user_id = uploaded_by_id` row, copy
 *     that `person.id` into `document.owner_person_id`.
 *   - Rows where the uploader has no Person profile remain NULL —
 *     admins can pick the governance owner manually later.
 *
 * Plain ALTER TABLE per CLAUDE.md pitfall #6 (no PREPARE/EXECUTE).
 * `isTransactional()=false` per pitfall #6 — multiple DDL statements in
 * one migration would otherwise blow up Doctrine's SAVEPOINT.
 */
final class Version20260509030000_person_owner_rollout extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Person-Rollout Phase A — add document.owner_person_id (FK Person, nullable) for governance-side document owners; backfill from uploader.linkedPerson when present.';
    }

    public function up(Schema $schema): void
    {
        // 1) Add nullable Person FK on document.
        $this->addSql('ALTER TABLE document ADD owner_person_id INT DEFAULT NULL');
        $this->addSql(
            'ALTER TABLE document ADD CONSTRAINT fk_document_owner_person '
            . 'FOREIGN KEY (owner_person_id) REFERENCES person (id) ON DELETE SET NULL'
        );
        $this->addSql('CREATE INDEX idx_document_owner_person ON document (owner_person_id)');

        // 2) Backfill governance owner from uploader's linked Person.
        // Idempotent: WHERE owner_person_id IS NULL guards a re-run.
        // Uses INNER JOIN so rows without a matching Person are skipped.
        $this->addSql(
            'UPDATE document d '
            . 'INNER JOIN person p ON p.linked_user_id = d.uploaded_by_id '
            . 'SET d.owner_person_id = p.id '
            . 'WHERE d.owner_person_id IS NULL '
            . '  AND d.uploaded_by_id IS NOT NULL'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE document DROP FOREIGN KEY fk_document_owner_person');
        $this->addSql('DROP INDEX idx_document_owner_person ON document');
        $this->addSql('ALTER TABLE document DROP COLUMN owner_person_id');
    }
}
