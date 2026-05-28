<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add assessment_state_dp column to compliance_requirement.
 *
 * Stores the Data Protection (Chapter 9) tristate compliance state for
 * VDA-ISA requirements. Values: not_applicable | compliant | non_compliant.
 * NULL for IS/PP tier requirements (those use maturity_current instead).
 *
 * Column is nullable — existing rows get NULL (unassessed) by default.
 */
final class Version20260619100000_AssessmentStateDp extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add assessment_state_dp (tristate DP compliance) to compliance_requirement';
    }

    /**
     * DDL — must disable transactions (MySQL auto-commits ALTER TABLE).
     */
    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE compliance_requirement ADD COLUMN assessment_state_dp VARCHAR(20) NULL DEFAULT NULL
             COMMENT "VDA-ISA Ch.9 tristate: not_applicable|compliant|non_compliant"',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compliance_requirement DROP COLUMN assessment_state_dp');
    }
}
