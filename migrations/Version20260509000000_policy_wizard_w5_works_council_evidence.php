<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Policy-Wizard W5 Gap-C — Works-Council BR-evidence requirement.
 *
 * Adds the `requires_works_council_evidence` boolean to `policy_template`
 * and back-fills it for the topics that touch personal-data processing
 * or workplace-monitoring (logging, monitoring, telework, acceptable_use,
 * awareness_training, malware_protection, IT-administration policies).
 *
 * Spec: `docs/plans/policy-wizard/07-phase4-sprint-reconciliation.md`
 * line 261-262 (Auditor "Auditor-specific gaps" Works-Council).
 *
 * Per CLAUDE.md pitfall #6: plain SQL only (no PREPARE/EXECUTE), and
 * `isTransactional()=false` because the ALTER TABLE ADD COLUMN
 * statement implicitly commits in MySQL.
 */
final class Version20260509000000_policy_wizard_w5_works_council_evidence extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Policy-Wizard W5 Gap-C: extend policy_template with '
            . 'requires_works_council_evidence flag and backfill the '
            . 'workplace-monitoring topics (Auditor BR-evidence gap).';
    }

    public function up(Schema $schema): void
    {
        $tableExists = (bool) $this->connection->fetchOne(
            <<<'SQL'
                SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'policy_template'
            SQL,
        );
        $this->abortIf(
            !$tableExists,
            'policy_template table missing — run earlier W1 migrations first',
        );

        $hasColumn = (bool) $this->connection->fetchOne(
            <<<'SQL'
                SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'policy_template'
                   AND COLUMN_NAME = 'requires_works_council_evidence'
            SQL,
        );
        if (!$hasColumn) {
            $this->addSql(<<<'SQL'
                ALTER TABLE policy_template
                    ADD COLUMN requires_works_council_evidence TINYINT(1) NOT NULL DEFAULT 0
            SQL);
        }

        // Backfill — set the flag true for every template whose topic is
        // in the workplace-monitoring / personal-data scope. Idempotent:
        // re-running the UPDATE is a no-op once the rows already match.
        $this->addSql(<<<'SQL'
            UPDATE policy_template
               SET requires_works_council_evidence = 1
             WHERE topic IN (
                'logging',
                'monitoring',
                'telework',
                'teleworking_policy',
                'acceptable_use',
                'awareness_training',
                'malware_protection',
                'it_administration_policy',
                'remote_maintenance_policy',
                'it_administration_directive'
            )
        SQL);
    }

    public function down(Schema $schema): void
    {
        $hasColumn = (bool) $this->connection->fetchOne(
            <<<'SQL'
                SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'policy_template'
                   AND COLUMN_NAME = 'requires_works_council_evidence'
            SQL,
        );
        if ($hasColumn) {
            $this->addSql(<<<'SQL'
                ALTER TABLE policy_template DROP COLUMN requires_works_council_evidence
            SQL);
        }
    }
}
