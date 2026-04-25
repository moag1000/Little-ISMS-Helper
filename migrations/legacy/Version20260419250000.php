<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * TISAX / VDA-ISA 6.0 — information classification overlay.
 *
 * Adds `tisax_information_classification` to `asset` and `document`:
 *   public | internal | confidential | strictly_confidential | prototype
 *
 * Kept separate from Asset.data_classification (which uses the
 * ISO 27001-leaning vocabulary `public | internal | confidential |
 * restricted`) so the TISAX mapping is unambiguous without breaking
 * existing data or form choices.
 */
final class Version20260419250000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'TISAX: asset/document.tisax_information_classification (VDA-ISA 5-value enum)';
    }

    public function up(Schema $schema): void
    {
        foreach (['asset', 'document'] as $table) {
            $this->addSql(sprintf(
                "SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='%s' AND COLUMN_NAME='tisax_information_classification')",
                $table
            ));
            $this->addSql(sprintf(
                "SET @sql := IF(@col = 0, 'ALTER TABLE %s ADD tisax_information_classification VARCHAR(30) DEFAULT NULL', 'SELECT 1')",
                $table
            ));
            $this->addSql('PREPARE stmt FROM @sql');
            $this->addSql('EXECUTE stmt');
            $this->addSql('DEALLOCATE PREPARE stmt');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE asset DROP tisax_information_classification');
        $this->addSql('ALTER TABLE document DROP tisax_information_classification');
    }
}
