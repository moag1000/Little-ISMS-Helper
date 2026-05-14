<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Sprint 7A — F3 Notifications Wave 2: SLA Deadline Monitor
 *
 * Creates `sla_deadline_monitor` table for tracking regulatory SLA deadlines:
 *  - GDPR Art. 33 72h supervisory authority notification
 *  - DORA Art. 19 4h/24h/1mo ICT-incident reporting
 *  - NIS2 Art. 23 24h/72h/1mo significant-incident reporting
 *  - ISO 27001 Cl. 10.1 30-day corrective action
 *
 * isTransactional = false: required because MySQL CREATE TABLE implicitly
 * commits, which invalidates Doctrine's SAVEPOINT strategy.
 * See CLAUDE.md §Migrations pitfall #6.
 */
final class Version20260514120000_f3_sla_deadline_monitor extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Sprint 7A F3 Wave 2: sla_deadline_monitor table with status/deadline indices';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
            CREATE TABLE IF NOT EXISTS sla_deadline_monitor (
                id                      INT NOT NULL AUTO_INCREMENT,
                tenant_id               INT NOT NULL,
                entity_type             VARCHAR(80) NOT NULL,
                entity_id               INT NOT NULL,
                deadline_type           VARCHAR(40) NOT NULL,
                triggered_at            DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                deadline_at             DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                notify_at_checkpoints   JSON NOT NULL,
                last_notified_at_hours  INT DEFAULT NULL,
                status                  VARCHAR(20) NOT NULL DEFAULT 'active',
                satisfied_at            DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                satisfied_by_id         INT DEFAULT NULL,
                PRIMARY KEY (id),
                INDEX idx_sla_tenant       (tenant_id),
                INDEX idx_sla_status_deadline (status, deadline_at),
                INDEX idx_sla_entity       (entity_type, entity_id),
                CONSTRAINT fk_sla_tenant FOREIGN KEY (tenant_id)
                    REFERENCES tenant(id) ON DELETE CASCADE,
                CONSTRAINT fk_sla_satisfied_by FOREIGN KEY (satisfied_by_id)
                    REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS sla_deadline_monitor');
    }
}
