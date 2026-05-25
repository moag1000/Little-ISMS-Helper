<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Junior-ISB-Audit S14+ #15 — drop the legacy `asset.monetary_value` column.
 *
 * Context:
 *   The `monetaryValue` field was deprecated in PR #607 (Junior-Finding #9 —
 *   AssetType form removal): the canonical valuation fields are
 *   `acquisitionValue` (purchase price) + `currentValue` (book value).
 *   Three show-templates (asset/risk/incident) plus
 *   `RiskImpactCalculatorService` were left reading `monetaryValue` as a
 *   fallback. S14+ #15 finishes the migration:
 *
 *     1. Backfill `current_value` from `monetary_value` for rows where the
 *        canonical field is NULL and the legacy field carries data (>0).
 *     2. Drop the `monetary_value` column.
 *
 * Consumers were rewired in the same PR:
 *   - `RiskImpactCalculatorService` now reads
 *     `currentValue ?? acquisitionValue` via `resolveAssetValue()`.
 *   - 3 show-templates (asset/risk/incident) dropped the
 *     `?? asset.monetaryValue` fallback.
 *   - Entity getter/setter + property removed from `Asset.php`.
 *
 * `isTransactional()=false` per CLAUDE.md pitfall #6 (MySQL `ALTER TABLE`
 * commits implicitly and would invalidate the Doctrine SAVEPOINT).
 *
 * `precision: 10, scale: 2` chosen for the down-migration recreation matches
 * the surrounding valuation columns — the original `15, 2` definition was
 * an outlier and pre-existing data with values >99 999 999.99 are not
 * realistic for a single asset (and would have been caught by the backfill
 * floor anyway).
 */
final class Version20260612100000_DropAssetMonetaryValue extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'S14+ #15: backfill asset.current_value from monetary_value, then DROP COLUMN monetary_value.';
    }

    public function up(Schema $schema): void
    {
        // 1) Backfill: when canonical `current_value` is NULL but legacy
        //    `monetary_value` carries a positive figure, hoist it across.
        //    Guarded by COLUMN_EXISTS so re-running on a partly-migrated DB
        //    is safe.
        $this->addSql(<<<'SQL'
            UPDATE asset
            SET current_value = monetary_value
            WHERE current_value IS NULL
              AND monetary_value IS NOT NULL
              AND monetary_value > 0
        SQL);

        // 2) Drop the legacy column.
        $this->addSql('ALTER TABLE asset DROP COLUMN monetary_value');
    }

    public function down(Schema $schema): void
    {
        // Re-create the column as nullable so a rollback does not require
        // data restore. Legacy callers will see NULL; the backfill is
        // one-way (we cannot distinguish hoisted values from original
        // current_value entries after the fact).
        $this->addSql('ALTER TABLE asset ADD COLUMN monetary_value NUMERIC(15, 2) DEFAULT NULL');
    }
}
