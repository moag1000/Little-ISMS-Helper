<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Policy-Wizard Sprint W5-A — BSI PolicyTemplate column extensions.
 *
 * Adds two columns to `policy_template` so the BSI seed (W5-A Step 4)
 * can record the IT-Grundschutz tier (`basis|standard|kern`) and the
 * specific Baustein anchor IDs from `docs/plans/policy-wizard/02-bsi-input.md`
 * Anhang A:
 *
 *   • `bsi_tier`             — VARCHAR(16) NULL, indexed (drives the
 *                               §8 tier filter in DocumentGenerator)
 *   • `linked_bsi_bausteine` — JSON NULL, list of Baustein-Anforderung
 *                               IDs like `ISMS.1.A4` / `ORP.4.A1`.
 *
 * Idempotent: column adds are guarded via INFORMATION_SCHEMA lookups
 * so re-running the migration on a partially-applied DB is safe.
 *
 * Per CLAUDE.md pitfall #6: plain SQL only (no PREPARE/EXECUTE), and
 * `isTransactional()=false` because the ALTER TABLE ADD COLUMN
 * statements implicitly commit in MySQL — running this migration in the
 * same `migrate` invocation as the W5-A seed (Version20260508151000)
 * otherwise blows the SAVEPOINT.
 *
 * The IF-NOT-EXISTS check uses dynamic SQL that the wider codebase
 * forbids (pitfall #6); for additive idempotent ALTERs we instead read
 * INFORMATION_SCHEMA up-front and only emit the ALTER on cold runs.
 */
final class Version20260508150000_policy_wizard_w5_bsi_template_extensions extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Policy-Wizard W5-A: extend policy_template with bsi_tier '
            . '(basis|standard|kern, NULL for non-BSI rows) + linked_bsi_bausteine '
            . '(JSON list of Baustein-Anforderung IDs).';
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

        // Defensive: if the policy_template table is missing the W1
        // baseline migration hasn't run yet — bail with a clear error so
        // operators don't get a confusing DDL error downstream.
        $this->abortIf(
            !$tableExists,
            'policy_template table missing — run earlier W1 migrations first',
        );

        $hasBsiTier = (bool) $this->connection->fetchOne(
            <<<'SQL'
                SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'policy_template'
                   AND COLUMN_NAME = 'bsi_tier'
            SQL,
        );
        if (!$hasBsiTier) {
            $this->addSql(<<<'SQL'
                ALTER TABLE policy_template
                    ADD COLUMN bsi_tier VARCHAR(16) DEFAULT NULL
            SQL);
            $this->addSql(<<<'SQL'
                CREATE INDEX idx_policy_template_bsi_tier ON policy_template (bsi_tier)
            SQL);
        }

        $hasLinkedBsi = (bool) $this->connection->fetchOne(
            <<<'SQL'
                SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'policy_template'
                   AND COLUMN_NAME = 'linked_bsi_bausteine'
            SQL,
        );
        if (!$hasLinkedBsi) {
            $this->addSql(<<<'SQL'
                ALTER TABLE policy_template
                    ADD COLUMN linked_bsi_bausteine JSON DEFAULT NULL
            SQL);
        }
    }

    public function down(Schema $schema): void
    {
        $hasIndex = (bool) $this->connection->fetchOne(
            <<<'SQL'
                SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'policy_template'
                   AND INDEX_NAME = 'idx_policy_template_bsi_tier'
            SQL,
        );
        if ($hasIndex) {
            $this->addSql('DROP INDEX idx_policy_template_bsi_tier ON policy_template');
        }

        $hasBsiTier = (bool) $this->connection->fetchOne(
            <<<'SQL'
                SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'policy_template'
                   AND COLUMN_NAME = 'bsi_tier'
            SQL,
        );
        if ($hasBsiTier) {
            $this->addSql('ALTER TABLE policy_template DROP COLUMN bsi_tier');
        }

        $hasLinkedBsi = (bool) $this->connection->fetchOne(
            <<<'SQL'
                SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'policy_template'
                   AND COLUMN_NAME = 'linked_bsi_bausteine'
            SQL,
        );
        if ($hasLinkedBsi) {
            $this->addSql('ALTER TABLE policy_template DROP COLUMN linked_bsi_bausteine');
        }
    }
}
