<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * F16 Risk-Incident-Link — Sprint 9B
 *
 * Creates the `risk_incident_link` table that replaces the implicit
 * ManyToMany between Risk and Incident with a richer join-entity:
 *
 *   - link_type (materialized|suspected|related|mitigation_failed)
 *   - linked_at  — when the link was created
 *   - linked_by_id — which user created it (nullable)
 *   - notes — optional free-text annotation
 *
 * Cascade-delete: removing either Risk or Incident removes all its link rows.
 * Unique constraint: each (risk_id, incident_id) pair may only be linked once.
 *
 * isTransactional() = false: DDL on MySQL/MariaDB implicitly commits;
 * keeping it transactional causes SAVEPOINT failures in doctrine:migrations:migrate.
 */
final class Version20260517110000_f16_risk_incident_link extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'F16: create risk_incident_link table (Sprint 9B)';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `risk_incident_link` (
                `id`           INT NOT NULL AUTO_INCREMENT,
                `tenant_id`    INT NOT NULL,
                `risk_id`      INT NOT NULL,
                `incident_id`  INT NOT NULL,
                `link_type`    VARCHAR(32) NOT NULL DEFAULT 'related',
                `linked_at`    DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                `linked_by_id` INT DEFAULT NULL,
                `notes`        TEXT DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_risk_incident` (`risk_id`, `incident_id`),
                INDEX `idx_tenant_link_type` (`tenant_id`, `link_type`),
                INDEX `idx_risk_incident_link_risk` (`risk_id`),
                INDEX `idx_risk_incident_link_incident` (`incident_id`),
                INDEX `idx_risk_incident_link_linked_by` (`linked_by_id`),
                CONSTRAINT `fk_ril_tenant`
                    FOREIGN KEY (`tenant_id`) REFERENCES `tenant` (`id`),
                CONSTRAINT `fk_ril_risk`
                    FOREIGN KEY (`risk_id`) REFERENCES `risk` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_ril_incident`
                    FOREIGN KEY (`incident_id`) REFERENCES `incident` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_ril_linked_by`
                    FOREIGN KEY (`linked_by_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS `risk_incident_link`');
    }
}
