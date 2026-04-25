<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MINOR-6 (docs/DATA_REUSE_PLAN_REVIEW_ISB.md): DORA ITS Register of Information
 * conformance. Adds the two remaining ITS-mandated columns for the CSV export.
 *
 * - nace_code: EU NACE Rev.2 industry code of the ICT third-party provider.
 * - country_of_head_office: ISO-3166 alpha-2 country code of the provider HQ.
 *
 * Both columns are nullable — no backfill needed, existing rows stay NULL and
 * render as empty string in the export until data is captured.
 */
final class Version20260418220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Supplier: add nace_code + country_of_head_office for DORA ITS ROI export (MINOR-6).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE supplier ADD COLUMN nace_code VARCHAR(10) DEFAULT NULL, ADD COLUMN country_of_head_office VARCHAR(2) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE supplier DROP COLUMN nace_code, DROP COLUMN country_of_head_office');
    }
}
