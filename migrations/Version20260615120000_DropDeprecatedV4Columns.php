<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Bucket 4 (TODO-Remediation-Plan 2026-05) — drop three columns deprecated
 * since 2026-05-14 (v3.8-era) that carry no remaining code references.
 *
 * Columns dropped:
 *   1. `document.is_public`                — flagged @deprecated; only test
 *                                            references (also removed in
 *                                            the same PR). No form,
 *                                            renderer, or query consumed it.
 *   2. `compliance_requirement.assessment_level` — TISAX VDA ISA tag; only
 *                                            historical references in the
 *                                            original ADD migration
 *                                            (Version20260424150000). No
 *                                            form, template, or query.
 *   3. `business_process.mbco_percentage`  — numeric duplicate of the
 *                                            `mbco` text field, never wired
 *                                            to any UI or report.
 *
 * Verification grep (executed prior to drop) returned zero refs in
 * `src/`, `templates/`, `config/`, and `tests/` (other than the entity
 * declaration itself and the historical ADD-COLUMN migration). The entity
 * property + getter + setter were removed in the same PR.
 *
 * `isTransactional()=false` per CLAUDE.md pitfall #6 — every MySQL
 * `ALTER TABLE` commits implicitly, and multiple DDLs in one migration
 * would otherwise invalidate Doctrine's per-migration SAVEPOINT.
 *
 * Down-migration recreates the columns as NULL/false defaults so a
 * rollback does not require data restore. The drop is one-way for data;
 * affected rows had no UI surface so business impact is nil.
 */
final class Version20260615120000_DropDeprecatedV4Columns extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Bucket 4: drop 3 deprecated-since-v4 columns (document.is_public, compliance_requirement.assessment_level, business_process.mbco_percentage).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE document DROP COLUMN is_public');
        $this->addSql('ALTER TABLE compliance_requirement DROP COLUMN assessment_level');
        $this->addSql('ALTER TABLE business_process DROP COLUMN mbco_percentage');
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE document ADD COLUMN is_public TINYINT(1) NOT NULL DEFAULT 0");
        $this->addSql('ALTER TABLE compliance_requirement ADD COLUMN assessment_level VARCHAR(10) DEFAULT NULL');
        $this->addSql('ALTER TABLE business_process ADD COLUMN mbco_percentage INT DEFAULT NULL');
    }
}
