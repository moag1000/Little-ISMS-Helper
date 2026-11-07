<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * P5 "eine Liste statt zwei":
 *  - treatment plan controls (risk_treatment_plan_control) join the risk's control list
 *    (control_risk); the plan shows the risk's list from now on.
 *  - incident "realized risks" (incident_risk) become risk-incident links of type
 *    "materialized"; an existing link of the same pair keeps its type.
 * No row is lost: both old tables are copied before they are dropped.
 */
final class Version20261107230000_OneListRiskControlsAndIncidentLinks extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'One control list for risk + treatment plan, one risk list (links) for incidents';
    }

    public function isTransactional(): bool
    {
        // DROP TABLE commits implicitly in MySQL (invalidates the SAVEPOINT).
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'INSERT IGNORE INTO control_risk (control_id, risk_id)
             SELECT rtpc.control_id, rtp.risk_id
             FROM risk_treatment_plan_control rtpc
             JOIN risk_treatment_plan rtp ON rtp.id = rtpc.risk_treatment_plan_id
             WHERE rtp.risk_id IS NOT NULL'
        );
        $this->addSql('DROP TABLE risk_treatment_plan_control');

        $this->addSql(
            "INSERT IGNORE INTO risk_incident_link (tenant_id, risk_id, incident_id, link_type, linked_at)
             SELECT COALESCE(i.tenant_id, r.tenant_id), ir.risk_id, ir.incident_id, 'materialized', NOW()
             FROM incident_risk ir
             JOIN incident i ON i.id = ir.incident_id
             JOIN risk r ON r.id = ir.risk_id
             WHERE COALESCE(i.tenant_id, r.tenant_id) IS NOT NULL"
        );
        $this->addSql('DROP TABLE incident_risk');
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE incident_risk (incident_id INT NOT NULL, risk_id INT NOT NULL, INDEX IDX_930AA2FE59E53FB9 (incident_id), '
            . 'INDEX IDX_930AA2FE235B6D1 (risk_id), PRIMARY KEY (incident_id, risk_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`'
        );
        $this->addSql(
            "INSERT IGNORE INTO incident_risk (incident_id, risk_id)
             SELECT incident_id, risk_id FROM risk_incident_link WHERE link_type IN ('materialized', 'mitigation_failed')"
        );
        $this->addSql('ALTER TABLE incident_risk ADD CONSTRAINT FK_930AA2FE59E53FB9 FOREIGN KEY (incident_id) REFERENCES incident (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE incident_risk ADD CONSTRAINT FK_930AA2FE235B6D1 FOREIGN KEY (risk_id) REFERENCES risk (id) ON DELETE CASCADE');

        $this->addSql(
            'CREATE TABLE risk_treatment_plan_control (risk_treatment_plan_id INT NOT NULL, control_id INT NOT NULL, '
            . 'INDEX IDX_4117102F2EAFCFFC (risk_treatment_plan_id), INDEX IDX_4117102F32BEC70E (control_id), '
            . 'PRIMARY KEY (risk_treatment_plan_id, control_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`'
        );
        $this->addSql(
            'INSERT IGNORE INTO risk_treatment_plan_control (risk_treatment_plan_id, control_id)
             SELECT rtp.id, cr.control_id FROM risk_treatment_plan rtp JOIN control_risk cr ON cr.risk_id = rtp.risk_id'
        );
        $this->addSql('ALTER TABLE risk_treatment_plan_control ADD CONSTRAINT FK_4117102F2EAFCFFC FOREIGN KEY (risk_treatment_plan_id) REFERENCES risk_treatment_plan (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE risk_treatment_plan_control ADD CONSTRAINT FK_4117102F32BEC70E FOREIGN KEY (control_id) REFERENCES control (id) ON DELETE CASCADE');
    }
}
