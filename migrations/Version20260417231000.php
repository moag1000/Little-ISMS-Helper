<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * WS-6 (DATA_REUSE_IMPROVEMENT_PLAN.md v1.1): Gap-Report with FTE effort estimation.
 *
 * Adds `base_effort_days` to ComplianceRequirement (consultant seed value) and
 * `adjusted_effort_days` + `adjusted_effort_reason` to ComplianceRequirementFulfillment
 * (tenant-specific override with ISB MINOR-3 justification requirement, min 20 chars).
 */
final class Version20260417231000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'WS-6: Gap-Report FTE effort fields on requirement + fulfillment';
    }

    public function up(Schema $schema): void
    {
        // Consultant-seed effort on the framework requirement definition
        $this->addSql('ALTER TABLE compliance_requirement
            ADD base_effort_days INT DEFAULT NULL');

        // Tenant-specific override + mandatory reason
        $this->addSql('ALTER TABLE compliance_requirement_fulfillment
            ADD adjusted_effort_days INT DEFAULT NULL,
            ADD adjusted_effort_reason LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compliance_requirement_fulfillment
            DROP adjusted_effort_days,
            DROP adjusted_effort_reason');

        $this->addSql('ALTER TABLE compliance_requirement
            DROP base_effort_days');
    }
}
