<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * CRITICAL-03: Multi-Tenancy Fix - ComplianceRequirementFulfillment
 *
 * Creates new ComplianceRequirementFulfillment entity for tenant-specific fulfillment tracking.
 * Migrates existing global fulfillment data from ComplianceRequirement to new tenant-specific table.
 *
 * Architecture: Definition-Fulfillment Separation Pattern
 * - ComplianceFramework: Global standards (ISO 27001, GDPR, NIS2)
 * - ComplianceRequirement: Global requirement definitions
 * - ComplianceRequirementFulfillment: Tenant-specific implementation & progress (NEW)
 */
final class Version20251119010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'CRITICAL-03: Multi-Tenancy Fix - Create ComplianceRequirementFulfillment entity and migrate data';
    }

    public function up(Schema $schema): void
    {
        // ========================================
        // SCHEMA: Create ComplianceRequirementFulfillment table
        // ========================================
        $this->addSql('
            CREATE TABLE IF NOT EXISTS compliance_requirement_fulfillment (
                id INT AUTO_INCREMENT NOT NULL,
                tenant_id INT NOT NULL,
                requirement_id INT NOT NULL,
                applicable TINYINT(1) NOT NULL DEFAULT 1,
                applicability_justification LONGTEXT DEFAULT NULL,
                fulfillment_percentage INT NOT NULL DEFAULT 0,
                fulfillment_notes LONGTEXT DEFAULT NULL,
                evidence_description LONGTEXT DEFAULT NULL,
                last_review_date DATE DEFAULT NULL,
                next_review_date DATE DEFAULT NULL,
                responsible_person_id INT DEFAULT NULL,
                last_updated_by_id INT DEFAULT NULL,
                status VARCHAR(50) NOT NULL DEFAULT "not_started",
                created_at DATETIME NOT NULL,
                updated_at DATETIME DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE INDEX unique_tenant_requirement (tenant_id, requirement_id),
                INDEX idx_tenant (tenant_id),
                INDEX idx_requirement (requirement_id),
                INDEX idx_fulfillment_percentage (fulfillment_percentage),
                INDEX IDX_308AE242EF64F467 (responsible_person_id),
                INDEX IDX_308AE242E562D849 (last_updated_by_id),
                CONSTRAINT FK_308AE2429033212A
                    FOREIGN KEY (tenant_id)
                    REFERENCES tenant (id)
                    ON DELETE CASCADE,
                CONSTRAINT FK_308AE2427B576F77
                    FOREIGN KEY (requirement_id)
                    REFERENCES compliance_requirement (id)
                    ON DELETE CASCADE,
                CONSTRAINT FK_308AE242EF64F467
                    FOREIGN KEY (responsible_person_id)
                    REFERENCES users (id)
                    ON DELETE SET NULL,
                CONSTRAINT FK_308AE242E562D849
                    FOREIGN KEY (last_updated_by_id)
                    REFERENCES users (id)
                    ON DELETE SET NULL
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
        ');

        // ========================================
        // DATA MIGRATION: ComplianceRequirement → ComplianceRequirementFulfillment
        // ========================================
        // Problem: Old ComplianceRequirement stored fulfillment data globally (no tenant_id)
        // This was a multi-tenancy violation - all tenants shared the same fulfillment data
        // Solution: Migrate existing data to appropriate tenant(s)

        $this->write('🔄 Migrating ComplianceRequirement fulfillment data...');

        // Get all tenants
        $tenants = $this->connection->fetchAllAssociative('SELECT id FROM tenant ORDER BY id ASC');

        if (empty($tenants)) {
            $this->write('⚠️  WARNING: No tenants found in database');
            $this->write('💡 Skipping data migration - fulfillment data will be preserved in ComplianceRequirement');
        } elseif (count($tenants) === 1) {
            // Single tenant system - migrate all data to this tenant
            $tenantId = $tenants[0]['id'];
            $this->write("✅ Single tenant detected (ID: {$tenantId}) - migrating all fulfillment data");

            $this->addSql("
                INSERT INTO compliance_requirement_fulfillment
                (tenant_id, requirement_id, applicable, applicability_justification,
                 fulfillment_percentage, fulfillment_notes, evidence_description,
                 last_review_date, status, created_at)
                SELECT
                    {$tenantId} as tenant_id,
                    id as requirement_id,
                    applicable,
                    applicability_justification,
                    fulfillment_percentage,
                    fulfillment_notes,
                    evidence_description,
                    last_assessment_date as last_review_date,
                    CASE
                        WHEN fulfillment_percentage >= 100 THEN 'implemented'
                        WHEN fulfillment_percentage > 0 THEN 'in_progress'
                        ELSE 'not_started'
                    END as status,
                    NOW() as created_at
                FROM compliance_requirement
            ");

            $count = $this->connection->fetchOne('SELECT COUNT(*) FROM compliance_requirement');
            $this->write("✅ Migrated {$count} requirement fulfillments to tenant {$tenantId}");
        } else {
            // Multi-tenant system - migrate to primary tenant (lowest ID)
            $primaryTenantId = $tenants[0]['id'];
            $otherTenantsCount = count($tenants) - 1;

            $this->write("⚠️  Multi-tenant system detected (" . count($tenants) . " tenants)");
            $this->write("📊 Primary tenant ID: {$primaryTenantId}");
            $this->write("🔄 Migrating fulfillment data to primary tenant only...");

            $this->addSql("
                INSERT INTO compliance_requirement_fulfillment
                (tenant_id, requirement_id, applicable, applicability_justification,
                 fulfillment_percentage, fulfillment_notes, evidence_description,
                 last_review_date, status, created_at)
                SELECT
                    {$primaryTenantId} as tenant_id,
                    id as requirement_id,
                    applicable,
                    applicability_justification,
                    fulfillment_percentage,
                    fulfillment_notes,
                    evidence_description,
                    last_assessment_date as last_review_date,
                    CASE
                        WHEN fulfillment_percentage >= 100 THEN 'implemented'
                        WHEN fulfillment_percentage > 0 THEN 'in_progress'
                        ELSE 'not_started'
                    END as status,
                    NOW() as created_at
                FROM compliance_requirement
            ");

            $count = $this->connection->fetchOne('SELECT COUNT(*) FROM compliance_requirement');
            $this->write("✅ Migrated {$count} requirement fulfillments to primary tenant {$primaryTenantId}");
            $this->write("⚠️  WARNING: {$otherTenantsCount} other tenant(s) will have EMPTY fulfillment data");
            $this->write("💡 TIP: Use Corporate Governance 'hierarchical' model to inherit from parent");
            $this->write("💡 TIP: Or manually create fulfillment records for subsidiary tenants");
        }

        $this->write('✅ Data migration completed successfully');
        $this->write('📝 NOTE: ComplianceRequirement fulfillment fields are now @deprecated');
        $this->write('📝 NOTE: They will be removed in Phase 2B after controllers/services are updated');
    }

    public function down(Schema $schema): void
    {
        $this->write('⚠️  Rolling back ComplianceRequirementFulfillment migration...');
        $this->write('⚠️  WARNING: This will DELETE all tenant-specific fulfillment data!');

        // Drop table with all data
        $this->addSql('DROP TABLE IF EXISTS compliance_requirement_fulfillment');

        $this->write('❌ ComplianceRequirementFulfillment table dropped');
        $this->write('💡 NOTE: Original fulfillment data remains in ComplianceRequirement (deprecated fields)');
    }
}
