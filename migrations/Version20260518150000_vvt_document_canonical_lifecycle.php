<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * S3 P-4 — Canonical 5-stage lifecycle for ProcessingActivity (VVT) + Document.
 *
 * Consolidated data-migration: normalises both entities onto the canonical
 * 5-stage status lifecycle defined by LifecycleRegistry::STANDARD_5_STAGE.
 *
 * Changes:
 * 1. ProcessingActivity (VVT): legacy 3-stage (draft/active/archived) → 5-stage
 *    (draft/in_review/approved/published/archived). Maps `active` → `published`.
 *    Default column value bumps from 'draft' (unchanged); new intermediate stages
 *    (`in_review`, `approved`) start empty, no backfill required.
 * 2. Document: removes undocumented 6th status value `active`. Maps `active`
 *    → `published`. Default column value bumps from 'active' → 'draft'.
 *
 * Idempotency: both UPDATEs are no-ops when target rows are absent, so the
 * migration is safe to re-run via the schema reconciler.
 *
 * @see \App\Lifecycle\LifecycleRegistry
 * @see CLAUDE.md § "Status fields are first-class lifecycle fields"
 */
final class Version20260518150000_vvt_document_canonical_lifecycle extends AbstractMigration
{
    public function isTransactional(): bool
    {
        // Required: ALTER TABLE implicitly commits in MySQL → SAVEPOINT would break.
        return false;
    }

    public function getDescription(): string
    {
        return 'S3 P-4: VVT + Document canonical 5-stage lifecycle (data-migration + default-bump).';
    }

    public function up(Schema $schema): void
    {
        // ── 1) ProcessingActivity (VVT) — legacy 'active' → 'published' ──
        $this->addSql("UPDATE processing_activity SET status = 'published' WHERE status = 'active'");

        // ── 2) Document — legacy 'active' → 'published' ──
        $this->addSql("UPDATE document SET status = 'published' WHERE status = 'active'");

        // ── 3) Document — bump column default from 'active' → 'draft' so new rows
        //      created via raw SQL (fixtures, repair-flow) start in canonical 'draft'. ──
        $this->addSql("ALTER TABLE document MODIFY COLUMN status VARCHAR(50) NOT NULL DEFAULT 'draft'");
    }

    public function down(Schema $schema): void
    {
        // Best-effort rollback: re-tag 'published' rows as 'active'. NOTE — this is
        // lossy if any new 'published' rows were created post-migration; that's
        // accepted because S3 P-4 is a one-way semantic normalisation.
        $this->addSql("UPDATE processing_activity SET status = 'active' WHERE status = 'published'");
        $this->addSql("UPDATE document SET status = 'active' WHERE status = 'published'");
        $this->addSql("ALTER TABLE document MODIFY COLUMN status VARCHAR(50) NOT NULL DEFAULT 'active'");
    }
}
