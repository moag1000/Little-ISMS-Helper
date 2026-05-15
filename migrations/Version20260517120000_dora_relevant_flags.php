<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * DORA RoI Phase 1 — is_dora_relevant scope flags.
 *
 * Adds is_dora_relevant TINYINT(1) NOT NULL DEFAULT 0 to both
 * the supplier and asset tables so that operators can flag individual
 * entities for inclusion in the DORA Art. 28 Register of Information
 * XBRL export (filtered via repository helpers).
 *
 * DORA Art. 28 — Register of information on ICT third-party service providers.
 *
 * isTransactional() = false — ALTER TABLE commits implicitly in MySQL,
 * which invalidates Doctrine's SAVEPOINT-per-migration. This avoids
 * "SAVEPOINT DOCTRINE_X does not exist" errors in multi-migration runs.
 */
final class Version20260517120000_dora_relevant_flags extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'DORA Phase 1: Add is_dora_relevant flag columns to supplier and asset tables';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE supplier
                ADD COLUMN is_dora_relevant TINYINT(1) NOT NULL DEFAULT 0
                COMMENT 'DORA Art. 28 RoI scope flag — true = include in XBRL export'
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE asset
                ADD COLUMN is_dora_relevant TINYINT(1) NOT NULL DEFAULT 0
                COMMENT 'DORA Art. 28 RoI scope flag — true = include in XBRL export'
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE supplier DROP COLUMN is_dora_relevant');
        $this->addSql('ALTER TABLE asset DROP COLUMN is_dora_relevant');
    }
}
