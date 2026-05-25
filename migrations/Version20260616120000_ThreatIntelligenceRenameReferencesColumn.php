<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Rename `threat_intelligence.references` → `threat_references`.
 *
 * `references` is a MariaDB/MySQL reserved keyword. Doctrine quoted it on
 * initial CREATE TABLE but does NOT auto-quote subsequent DML (INSERT /
 * UPDATE), producing:
 *
 *   SQLSTATE[42000]: Syntax error or access violation: 1064 You have an
 *   error in your SQL syntax... near 'references, created_at, ...'
 *
 * Mirrors the earlier `vulnerabilities.references → vuln_references` rename
 * in Version20251127154814.
 */
final class Version20260616120000_ThreatIntelligenceRenameReferencesColumn extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename threat_intelligence.references to threat_references (reserved keyword).';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE threat_intelligence CHANGE `references` threat_references LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE threat_intelligence CHANGE threat_references `references` LONGTEXT DEFAULT NULL');
    }
}
