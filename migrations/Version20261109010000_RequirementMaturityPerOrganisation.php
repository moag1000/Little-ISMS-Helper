<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * S18: the Reifegrad (Ist / Soll) of a GLOBAL catalogue requirement (MRIS MHC, industry baseline
 * targets) is the organisation's own assessment. It moves from the shared compliance_requirement
 * row onto the organisation's compliance_requirement_fulfillment row.
 *
 * Data:
 *  - every organisation that works with the framework (has a fulfilment row in it) — or the only
 *    organisation of a single-organisation instance — gets the value it saw until now, so nobody
 *    loses an assessment; from now on each organisation changes only its own;
 *  - the shared row keeps no Ist and no review date; its Soll stays only for TISAX (the VDA-ISA
 *    skeleton default "established", a catalogue default), otherwise it is cleared.
 * Rows an organisation uploaded itself (upload_tenant_id set, TISAX workbook) keep theirs.
 *
 * Re-runnable: the DDL is guarded, every data step only touches what is still to do.
 */
final class Version20261109010000_RequirementMaturityPerOrganisation extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Compliance: Reifegrad (Ist/Soll) of global requirements per organisation on the fulfilment row';
    }

    public function isTransactional(): bool
    {
        // ALTER TABLE commits implicitly in MySQL.
        return false;
    }

    public function up(Schema $schema): void
    {
        $table = $schema->hasTable('compliance_requirement_fulfillment')
            ? $schema->getTable('compliance_requirement_fulfillment')
            : null;
        if ($table === null) {
            return;
        }
        if (!$table->hasColumn('maturity_current')) {
            $this->addSql('ALTER TABLE compliance_requirement_fulfillment ADD maturity_current VARCHAR(20) DEFAULT NULL, ADD maturity_target VARCHAR(20) DEFAULT NULL, ADD maturity_reviewed_at DATETIME DEFAULT NULL');
        }

        // A global row with an organisation value. The TISAX skeleton Soll alone is the catalogue
        // default and stays on the row (read as fallback), so it is not copied.
        $globalWithMaturity = 'r.upload_tenant_id IS NULL AND (r.maturity_current IS NOT NULL'
            . " OR (r.maturity_target IS NOT NULL AND fw.code NOT LIKE 'TISAX%'))";

        // 1. Fulfilment rows for the organisations that work with the framework (or the only one).
        $this->addSql(
            'INSERT INTO compliance_requirement_fulfillment'
            . ' (tenant_id, requirement_id, applicable, fulfillment_percentage, status, created_at, evidence_outdated)'
            . ' SELECT t.id, r.id, 1, 0, \'not_started\', NOW(), 0'
            . ' FROM compliance_requirement r'
            . ' JOIN compliance_framework fw ON fw.id = r.framework_id'
            . ' CROSS JOIN tenant t'
            . ' WHERE ' . $globalWithMaturity
            . ' AND NOT EXISTS (SELECT 1 FROM compliance_requirement_fulfillment f0'
            . '     WHERE f0.tenant_id = t.id AND f0.requirement_id = r.id)'
            . ' AND ((SELECT COUNT(*) FROM tenant) = 1 OR EXISTS ('
            . '     SELECT 1 FROM compliance_requirement_fulfillment f1'
            . '     JOIN compliance_requirement r1 ON r1.id = f1.requirement_id'
            . '     WHERE f1.tenant_id = t.id AND r1.framework_id = r.framework_id))'
        );

        // 2. Copy the value each organisation saw until now onto its own row.
        $this->addSql(
            'UPDATE compliance_requirement_fulfillment f'
            . ' JOIN compliance_requirement r ON r.id = f.requirement_id'
            . ' JOIN compliance_framework fw ON fw.id = r.framework_id'
            . ' SET f.maturity_current = r.maturity_current,'
            . '     f.maturity_target = r.maturity_target,'
            . '     f.maturity_reviewed_at = r.maturity_reviewed_at'
            . ' WHERE ' . $globalWithMaturity
            . ' AND f.maturity_current IS NULL AND f.maturity_target IS NULL'
        );

        // 3. The shared row keeps no organisation value (TISAX Soll = catalogue default).
        $this->addSql(
            'UPDATE compliance_requirement r'
            . ' SET r.maturity_current = NULL, r.maturity_reviewed_at = NULL'
            . ' WHERE r.upload_tenant_id IS NULL'
            . ' AND (r.maturity_current IS NOT NULL OR r.maturity_reviewed_at IS NOT NULL)'
        );
        $this->addSql(
            'UPDATE compliance_requirement r'
            . ' JOIN compliance_framework fw ON fw.id = r.framework_id'
            . ' SET r.maturity_target = NULL'
            . ' WHERE r.upload_tenant_id IS NULL AND r.maturity_target IS NOT NULL'
            . " AND fw.code NOT LIKE 'TISAX%'"
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compliance_requirement_fulfillment DROP maturity_current, DROP maturity_target, DROP maturity_reviewed_at');
    }
}
