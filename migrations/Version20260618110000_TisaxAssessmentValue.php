<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * VDA-ISA per-tier assessment models — Phase 2 DDL.
 *
 * Adds `assessment_value` VARCHAR(20) NULL to `compliance_requirement`.
 *
 * Purpose:
 *   Tier 1 (IS, Chapters 1-6) continues to store numeric Reifegrad 0-5 in
 *   `maturity_current` (existing column, unchanged).
 *
 *   Tier 2 (Prototype Protection, Chapters 7-8) and Tier 3 (Data Protection,
 *   Chapter 9) use binary/GDPR-conformance values that do NOT map to a 0-5 scale.
 *   These are stored in the new `assessment_value` column:
 *     - PP:  'compliant' | 'not_compliant' | 'na'
 *     - DP:  'in_place'  | 'partial'       | 'not_in_place' | 'na'
 *
 * isTransactional()=false required: MySQL ALTER TABLE auto-commits, invalidating
 * Doctrine's SAVEPOINT on multi-migration runs (see CLAUDE.md pitfall #6).
 */
final class Version20260618110000_TisaxAssessmentValue extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'VDA-ISA per-tier assessment models: add assessment_value column to compliance_requirement (Tier 2 binary + Tier 3 GDPR conformance)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "ALTER TABLE compliance_requirement
             ADD COLUMN IF NOT EXISTS assessment_value VARCHAR(20) NULL
             COMMENT 'Tier 2 (PP): compliant|not_compliant|na — Tier 3 (DP): in_place|partial|not_in_place|na'",
        );

        $this->addSql(
            'CREATE INDEX IF NOT EXISTS idx_cr_assessment_value
             ON compliance_requirement (assessment_value)',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compliance_requirement DROP INDEX IF EXISTS idx_cr_assessment_value');
        $this->addSql('ALTER TABLE compliance_requirement DROP COLUMN IF EXISTS assessment_value');
    }
}
