<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260506213310 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Align ComplianceFramework codes with wizard registry + add missing framework records (BSI-C5-2026, NIST-CSF-2.0, ENISA-EUCS, EU-CRA, ISO22301)';
    }

    public function isTransactional(): bool
    {
        return true;
    }

    public function up(Schema $schema): void
    {
        // 1. Rename existing codes to match wizard registry
        $this->addSql("UPDATE compliance_framework SET code='EU-AI-ACT' WHERE code='EU-AI-Act'");
        $this->addSql("UPDATE compliance_framework SET code='KRITIS-DE' WHERE code='KRITIS-DachG'");
        $this->addSql("UPDATE compliance_framework SET code='ISO27701' WHERE code='ISO27701-2025'");

        // 2. Resolve BSI-Grundschutz duplicates: keep canonical 'BSI-GRUNDSCHUTZ', merge requirements from duplicates
        // Strategy: pick one canonical record; reassign requirements; delete losers.
        $this->addSql("
            UPDATE compliance_requirement SET framework_id = (SELECT id FROM compliance_framework WHERE code='BSI-Grundschutz' LIMIT 1)
            WHERE framework_id IN (SELECT id FROM compliance_framework WHERE code='BSI_GRUNDSCHUTZ')
        ");
        $this->addSql("DELETE FROM compliance_framework WHERE code='BSI_GRUNDSCHUTZ'");
        $this->addSql("UPDATE compliance_framework SET code='BSI-GRUNDSCHUTZ', name='BSI IT-Grundschutz' WHERE code='BSI-Grundschutz'");

        // 3. Insert missing framework records (loaders will fill requirements separately)
        $missing = [
            ['BSI-C5-2026', 'BSI C5:2026 Cloud Computing Compliance Criteria Catalogue'],
            ['NIST-CSF-2.0', 'NIST Cybersecurity Framework 2.0'],
            ['ENISA-EUCS', 'ENISA EUCS Cloud Services Certification Scheme'],
            ['EU-CRA', 'EU Cyber Resilience Act (Regulation 2024/2847)'],
            ['ISO22301', 'ISO/IEC 22301:2019 Business Continuity'],
            ['ISO27017', 'ISO/IEC 27017:2015 Cloud Security'],
            ['ISO27018', 'ISO/IEC 27018:2019 Cloud Privacy'],
            ['SOC2-TYPE-II', 'SOC 2 Type II (AICPA Trust Services)'],
            ['PCI-DSS-4.0.1', 'PCI DSS v4.0.1'],
            ['BSI-GRUNDSCHUTZ-STANDARD', 'BSI IT-Grundschutz Standard-Absicherung'],
            ['BSI-GRUNDSCHUTZ-KERN', 'BSI IT-Grundschutz Kern-Absicherung'],
        ];
        foreach ($missing as [$code, $name]) {
            $codeQuoted = $this->connection->quote($code);
            $nameQuoted = $this->connection->quote($name);
            $this->addSql("
                INSERT INTO compliance_framework (code, name, version, applicable_industry, regulatory_body, mandatory, active, lifecycle_state, created_at, updated_at)
                SELECT {$codeQuoted}, {$nameQuoted}, '1.0', 'all', 'EU', 0, 1, 'active', NOW(), NOW()
                WHERE NOT EXISTS (SELECT 1 FROM compliance_framework WHERE code={$codeQuoted})
            ");
        }
    }

    public function down(Schema $schema): void
    {
        // Code-renames are reversible; framework INSERTs are not (depend on requirements).
        $this->addSql("UPDATE compliance_framework SET code='EU-AI-Act' WHERE code='EU-AI-ACT'");
        $this->addSql("UPDATE compliance_framework SET code='KRITIS-DachG' WHERE code='KRITIS-DE'");
        $this->addSql("UPDATE compliance_framework SET code='ISO27701-2025' WHERE code='ISO27701'");
        $this->addSql("UPDATE compliance_framework SET code='BSI-Grundschutz' WHERE code='BSI-GRUNDSCHUTZ'");
    }
}
