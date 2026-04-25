<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Phase 8L.F1 — RiskApprovalConfig pro Tenant.
 *
 * Ersetzt die PHP-Constants APPROVAL_AUTOMATIC=3 / MANAGER=7 / EXECUTIVE=25
 * aus RiskAcceptanceWorkflowService. Pro Tenant genau ein Record (UNIQUE).
 *
 * Backfill: Jeder bestehende Tenant bekommt Default-Werte (3/7/25),
 * identisch zum bisherigen Verhalten — kein Funktionswechsel bis Admin
 * die Werte editiert.
 */
final class Version20260423110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 8L.F1: risk_approval_config per tenant + backfill defaults';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("
            CREATE TABLE risk_approval_config (
                id INT AUTO_INCREMENT NOT NULL,
                tenant_id INT NOT NULL,
                threshold_automatic INT NOT NULL,
                threshold_manager INT NOT NULL,
                threshold_executive INT NOT NULL,
                updated_by_id INT DEFAULT NULL,
                updated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                note LONGTEXT DEFAULT NULL,
                UNIQUE INDEX uniq_risk_approval_config_tenant (tenant_id),
                INDEX IDX_risk_approval_config_updated_by (updated_by_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ");
        $this->addSql("
            ALTER TABLE risk_approval_config
            ADD CONSTRAINT FK_risk_approval_config_tenant FOREIGN KEY (tenant_id)
                REFERENCES tenant (id) ON DELETE CASCADE
        ");
        $this->addSql("
            ALTER TABLE risk_approval_config
            ADD CONSTRAINT FK_risk_approval_config_updated_by FOREIGN KEY (updated_by_id)
                REFERENCES users (id) ON DELETE SET NULL
        ");

        // Backfill: Default-Werte (3/7/25) für jeden bestehenden Tenant.
        // Idempotent via NOT EXISTS — Migration kann mehrfach laufen.
        $this->addSql("
            INSERT INTO risk_approval_config (tenant_id, threshold_automatic, threshold_manager, threshold_executive, created_at)
            SELECT t.id, 3, 7, 25, NOW()
            FROM tenant t
            WHERE NOT EXISTS (
                SELECT 1 FROM risk_approval_config rac WHERE rac.tenant_id = t.id
            )
        ");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE risk_approval_config');
    }
}
