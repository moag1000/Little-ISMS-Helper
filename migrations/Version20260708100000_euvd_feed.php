<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * F39 — ENISA EU Vulnerability Database (EUVD) feed.
 *
 * Adds `in_euvd` (flag set by the EUVD connector) and `euvd_id` (EUVD upsert
 * key, distinct from cve_id) to the vulnerabilities table.
 */
final class Version20260708100000_euvd_feed extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'F39: add in_euvd + euvd_id columns to vulnerabilities (ENISA EUVD feed)';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE vulnerabilities ADD in_euvd TINYINT(1) DEFAULT 0 NOT NULL, ADD euvd_id VARCHAR(32) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_vuln_euvd_id ON vulnerabilities (euvd_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_vuln_euvd_id ON vulnerabilities');
        $this->addSql('ALTER TABLE vulnerabilities DROP in_euvd, DROP euvd_id');
    }
}
