<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Policy-Wizard Sprint W6-B — ISO 27701 PIMS metadata columns.
 *
 * Adds two JSON columns to `policy_template` so the W6-B Privacy seed
 * (Version20260508162000) can record the ISO 27701 clause mapping per
 * `docs/plans/policy-wizard/06-dpo-input.md` §3.1:
 *
 *   • `iso27701_clauses_2025` — JSON NULL, list of 2025 clause refs
 *                               like `5.1`, `7.2.8`, `6.13`.
 *   • `iso27701_clauses_2019` — JSON NULL, list of 2019 clause refs
 *                               (e.g. 2025's `6.13` was `6.13.1.5` in 2019).
 *
 * Both columns are stored so the `iso27701.version` tenant setting can
 * pick the right clause set without re-querying the source-of-truth
 * doc — the 2019 → 2025 deltas (esp. 6.13 promotion) are non-trivial.
 *
 * Idempotent: column adds are guarded via INFORMATION_SCHEMA lookups
 * so re-running on a partially-applied DB is safe.
 *
 * Per CLAUDE.md pitfall #6: plain SQL only (no PREPARE/EXECUTE), and
 * `isTransactional()=false` because the ALTER TABLE ADD COLUMN
 * statements implicitly commit in MySQL — running this migration in
 * the same `migrate` invocation as the W6-B seed (Version20260508162000)
 * otherwise blows the SAVEPOINT.
 */
final class Version20260508161000_policy_wizard_w6_iso27701_metadata extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Policy-Wizard W6-B: extend policy_template with iso27701_clauses_2025 + '
            . 'iso27701_clauses_2019 (JSON NULL) for PIMS clause-mapping metadata.';
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

        $hasClauses2025 = (bool) $this->connection->fetchOne(
            <<<'SQL'
                SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'policy_template'
                   AND COLUMN_NAME = 'iso27701_clauses_2025'
            SQL,
        );
        if (!$hasClauses2025) {
            $this->addSql(<<<'SQL'
                ALTER TABLE policy_template
                    ADD COLUMN iso27701_clauses_2025 JSON DEFAULT NULL
            SQL);
        }

        $hasClauses2019 = (bool) $this->connection->fetchOne(
            <<<'SQL'
                SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'policy_template'
                   AND COLUMN_NAME = 'iso27701_clauses_2019'
            SQL,
        );
        if (!$hasClauses2019) {
            $this->addSql(<<<'SQL'
                ALTER TABLE policy_template
                    ADD COLUMN iso27701_clauses_2019 JSON DEFAULT NULL
            SQL);
        }
    }

    public function down(Schema $schema): void
    {
        $hasClauses2019 = (bool) $this->connection->fetchOne(
            <<<'SQL'
                SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'policy_template'
                   AND COLUMN_NAME = 'iso27701_clauses_2019'
            SQL,
        );
        if ($hasClauses2019) {
            $this->addSql('ALTER TABLE policy_template DROP COLUMN iso27701_clauses_2019');
        }

        $hasClauses2025 = (bool) $this->connection->fetchOne(
            <<<'SQL'
                SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'policy_template'
                   AND COLUMN_NAME = 'iso27701_clauses_2025'
            SQL,
        );
        if ($hasClauses2025) {
            $this->addSql('ALTER TABLE policy_template DROP COLUMN iso27701_clauses_2025');
        }
    }
}
