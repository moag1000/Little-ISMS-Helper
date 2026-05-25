<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Bucket-6a (DORA RoI Sprint 9) — add `tenant.lei_code` + `tenant.reporting_currency`.
 *
 * Purpose:
 *   DORA Art. 28 Register of Information (RoI) XBRL export requires the
 *   reporting-entity LEI (B_01.01.0020) and ISO-4217 reporting currency
 *   (B_01.01.0040). Both were previously hardcoded to "N/A" / "EUR".
 *
 *   - `lei_code` VARCHAR(20) NULL — ISO 17442 LEI. Nullable because most
 *     non-DORA-obligated tenants do not have one. Validated form-side via
 *     regex /^[A-Z0-9]{18}\d{2}$/.
 *   - `reporting_currency` CHAR(3) NULL DEFAULT 'EUR' — ISO 4217. Default
 *     applied at column level so existing rows backfill to EUR on next
 *     INSERT/UPDATE; entity getter returns 'EUR' when NULL for safety.
 *
 * Both columns are additive (no DROP / data-loss). The Supplier-side
 * counterpart (`supplier.lei_code`) was already added in WS-3 (DORA ROI
 * Sprint 8).
 *
 * `isTransactional()=false` per CLAUDE.md pitfall #6 — MySQL `ALTER TABLE`
 * commits implicitly and would invalidate the Doctrine SAVEPOINT.
 */
final class Version20260616100000_TenantLeiCodeAndReportingCurrency extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Bucket-6a: add tenant.lei_code (ISO 17442) + tenant.reporting_currency (ISO 4217, default EUR) for DORA RoI XBRL export.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "ALTER TABLE tenant "
            . "ADD COLUMN lei_code VARCHAR(20) DEFAULT NULL, "
            . "ADD COLUMN reporting_currency VARCHAR(3) DEFAULT 'EUR'"
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tenant DROP COLUMN lei_code, DROP COLUMN reporting_currency');
    }
}
