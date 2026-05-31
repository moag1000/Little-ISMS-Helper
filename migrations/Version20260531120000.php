<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add the ISO/IEC 42001:2023 (AI Management System) ComplianceFramework row so
 * the framework becomes loadable end-to-end. The requirement catalogue
 * (LoadIso42001FullCommand — 38 Annex A controls + Clauses 4-10) was already
 * present but unreachable because no framework row existed and the loader was
 * not wired. Sibling cloud/AI frameworks (ISO27017/27018, EU-CRA, PCI-DSS)
 * already received their rows in Version20260506213310; ISO42001 was missing.
 */
final class Version20260531120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add ISO/IEC 42001:2023 AI Management System ComplianceFramework row (makes the AI-ISO catalogue loadable)';
    }

    public function isTransactional(): bool
    {
        // Data-only INSERT (no DDL) — default transactional behaviour is fine.
        return true;
    }

    public function up(Schema $schema): void
    {
        $this->addSql("
            INSERT INTO compliance_framework (code, name, version, applicable_industry, regulatory_body, mandatory, active, lifecycle_state, created_at, updated_at)
            SELECT 'ISO42001', 'ISO/IEC 42001:2023 AI Management System', '2023', 'all', 'ISO/IEC', 0, 1, 'active', NOW(), NOW()
            WHERE NOT EXISTS (SELECT 1 FROM compliance_framework WHERE code='ISO42001')
        ");
    }

    public function down(Schema $schema): void
    {
        // Only remove the row if it carries no requirements (avoid orphaning
        // tenant assessment data once the catalogue has been loaded).
        $this->addSql("
            DELETE FROM compliance_framework
            WHERE code='ISO42001'
              AND NOT EXISTS (
                  SELECT 1 FROM compliance_requirement r
                  WHERE r.framework_id = compliance_framework.id
              )
        ");
    }
}
