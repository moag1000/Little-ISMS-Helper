<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * WS-1 — Anforderungstyp → AbsicherungsStufe backfill + 'kern' normalization.
 *
 * Background:
 *   `compliance_requirement` has TWO overlapping fields:
 *   - `absicherungs_stufe` (canonical, set by LoadBsiItGrundschutzCatalogueCommand)
 *   - `anforderungs_typ`   (legacy, partially populated, values: basis/standard/hoch/erhoeht/erhöht)
 *
 *   Additionally, BsiKompendiumXmlImporter historically wrote 'kern' into
 *   absicherungs_stufe for H-type requirements. 'kern' is a Vorgehensweise
 *   (level / approach), NOT a tier — the canonical tier vocabulary is
 *   basis / standard / hoch. This migration normalizes those rows.
 *
 *   Step 1 — normalize 'kern' → 'hoch' in the tier column (vocabulary fix):
 *     rows with absicherungs_stufe='kern' are updated to 'hoch'.
 *
 *   Step 2 — backfill absicherungs_stufe from anforderungs_typ for rows where
 *   absicherungs_stufe is NULL or empty, mapping legacy spelling variants:
 *     'erhoeht' → 'hoch'
 *     'erhöht'  → 'hoch'
 *
 *   Rows that already have a valid absicherungs_stufe value are NOT touched
 *   (canonical wins — conflict scenario Req B is intentionally preserved).
 *
 * This is a data-only migration (UPDATE-only, no DDL). isTransactional()=false
 * is kept as a defensive override — avoids SAVEPOINT conflict if this migration
 * is batched with DDL migrations in a single migrate run (CLAUDE.md Pitfall #6).
 *
 * down() is a no-op: backfilling cannot be reversed without data loss risk,
 * and the `anforderungs_typ` column remains intact as a rollback source.
 */
final class Version20260610090000_anforderungstyp_consolidation extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'WS-1: Backfill absicherungs_stufe from anforderungs_typ where null (canonical wins on conflict).';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        // Step 1: Normalize 'kern' → 'hoch' in the tier column.
        // 'kern' is a BSI Vorgehensweise (level/approach), NOT a tier value.
        // The canonical tier vocabulary is basis / standard / hoch only.
        // BsiKompendiumXmlImporter historically wrote 'kern' for H-type requirements —
        // this UPDATE corrects that vocabulary mismatch.
        $this->addSql(
            "UPDATE compliance_requirement
             SET absicherungs_stufe = 'hoch'
             WHERE absicherungs_stufe = 'kern'"
        );

        // Step 2: Backfill absicherungs_stufe from anforderungs_typ for rows where
        // absicherungs_stufe is NULL or empty string.
        // CASE maps legacy spelling variants to canonical values.
        // WHERE clause ensures canonical values are NEVER overwritten (Req B stays 'basis').
        $this->addSql(
            "UPDATE compliance_requirement
             SET absicherungs_stufe = CASE anforderungs_typ
                 WHEN 'erhoeht' THEN 'hoch'
                 WHEN 'erhöht'  THEN 'hoch'
                 ELSE anforderungs_typ
             END
             WHERE (absicherungs_stufe IS NULL OR absicherungs_stufe = '')
               AND anforderungs_typ IS NOT NULL
               AND anforderungs_typ <> ''"
        );
    }

    public function down(Schema $schema): void
    {
        // No-op: reversing the backfill would require knowing which rows were
        // updated, which is not tracked. The anforderungs_typ column is
        // preserved and can be used to re-derive values if needed.
    }
}
