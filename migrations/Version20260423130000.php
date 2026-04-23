<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Phase 8L.F2 — IncidentSlaConfig pro (Tenant, Severity).
 *
 * Ersetzt SLA_LOW=48 / MEDIUM=24 / HIGH=8 / CRITICAL=2 / BREACH=1 aus
 * IncidentEscalationWorkflowService. Eine Zeile pro Severity pro Tenant.
 * Backfill: 5 Default-Records pro bestehendem Tenant.
 *
 * Regulatorische SLAs (GDPR-72h, NIS2-24h/72h, DORA-4h) bleiben fix im
 * Service — das sind Gesetze, nicht Business-SLAs.
 */
final class Version20260423130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 8L.F2: incident_sla_config per (tenant, severity) + backfill';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("
            CREATE TABLE incident_sla_config (
                id INT AUTO_INCREMENT NOT NULL,
                tenant_id INT NOT NULL,
                severity VARCHAR(20) NOT NULL,
                response_hours INT NOT NULL,
                escalation_hours INT DEFAULT NULL,
                resolution_hours INT DEFAULT NULL,
                updated_by_id INT DEFAULT NULL,
                updated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                note LONGTEXT DEFAULT NULL,
                INDEX idx_incident_sla_tenant (tenant_id),
                UNIQUE INDEX uniq_incident_sla_tenant_severity (tenant_id, severity),
                INDEX IDX_incident_sla_updated_by (updated_by_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ");
        $this->addSql("
            ALTER TABLE incident_sla_config
            ADD CONSTRAINT FK_incident_sla_tenant FOREIGN KEY (tenant_id)
                REFERENCES tenant (id) ON DELETE CASCADE
        ");
        $this->addSql("
            ALTER TABLE incident_sla_config
            ADD CONSTRAINT FK_incident_sla_updated_by FOREIGN KEY (updated_by_id)
                REFERENCES users (id) ON DELETE SET NULL
        ");

        // Backfill: 5 Rows pro Tenant. Idempotent via NOT EXISTS.
        $defaults = [
            ['low', 48],
            ['medium', 24],
            ['high', 8],
            ['critical', 2],
            ['breach', 1],
        ];
        foreach ($defaults as [$severity, $hours]) {
            $this->addSql(sprintf("
                INSERT INTO incident_sla_config (tenant_id, severity, response_hours, created_at)
                SELECT t.id, '%s', %d, NOW()
                FROM tenant t
                WHERE NOT EXISTS (
                    SELECT 1 FROM incident_sla_config isc
                    WHERE isc.tenant_id = t.id AND isc.severity = '%s'
                )
            ", $severity, $hours, $severity));
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE incident_sla_config');
    }
}
