<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Sprint 6a — F3 Notifications Wave 1
 *
 * Creates 5 notification tables, extends users table,
 * and inserts 6 global Tier-1 notification templates.
 *
 * isTransactional = false: required because MySQL DDL (CREATE TABLE)
 * implicitly commits and invalidates Doctrine's SAVEPOINT strategy.
 */
final class Version20260513160000_f3_notifications_wave1 extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'F3 Notifications Wave 1: 5 tables + 6 Tier-1 templates + User notification prefs';
    }

    public function up(Schema $schema): void
    {
        // 1. notification_channel (no FK to rule — rule owns the M2M)
        $this->addSql(<<<SQL
            CREATE TABLE IF NOT EXISTS notification_channel (
                id INT NOT NULL AUTO_INCREMENT,
                tenant_id INT NOT NULL,
                name VARCHAR(120) NOT NULL,
                type VARCHAR(32) NOT NULL,
                config JSON NOT NULL,
                secret_encrypted LONGTEXT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                verified_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                INDEX idx_notif_channel_tenant (tenant_id),
                INDEX idx_notif_channel_type (type),
                CONSTRAINT fk_notif_channel_tenant FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        // 2. notification_rule
        $this->addSql(<<<SQL
            CREATE TABLE IF NOT EXISTS notification_rule (
                id INT NOT NULL AUTO_INCREMENT,
                tenant_id INT NOT NULL,
                created_by_id INT NULL,
                name VARCHAR(120) NOT NULL,
                event_type VARCHAR(80) NOT NULL,
                conditions JSON NOT NULL,
                severity_filter VARCHAR(32) NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                evaluation_count INT NOT NULL DEFAULT 0,
                last_evaluated_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                INDEX idx_notif_rule_tenant (tenant_id),
                INDEX idx_notif_rule_event_type (event_type),
                INDEX idx_notif_rule_active (is_active),
                CONSTRAINT fk_notif_rule_tenant FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE,
                CONSTRAINT fk_notif_rule_created_by FOREIGN KEY (created_by_id) REFERENCES users (id) ON DELETE SET NULL
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        // 3. notification_rule_channel (M2M join table)
        $this->addSql(<<<SQL
            CREATE TABLE IF NOT EXISTS notification_rule_channel (
                rule_id INT NOT NULL,
                channel_id INT NOT NULL,
                PRIMARY KEY (rule_id, channel_id),
                INDEX idx_nrc_channel (channel_id),
                CONSTRAINT fk_nrc_rule FOREIGN KEY (rule_id) REFERENCES notification_rule (id) ON DELETE CASCADE,
                CONSTRAINT fk_nrc_channel FOREIGN KEY (channel_id) REFERENCES notification_channel (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        // 4. notification_delivery
        $this->addSql(<<<SQL
            CREATE TABLE IF NOT EXISTS notification_delivery (
                id INT NOT NULL AUTO_INCREMENT,
                tenant_id INT NOT NULL,
                rule_id INT NOT NULL,
                channel_id INT NOT NULL,
                status VARCHAR(32) NOT NULL DEFAULT 'pending',
                retries INT NOT NULL DEFAULT 0,
                response_payload JSON NULL,
                attempted_at DATETIME NOT NULL,
                delivered_at DATETIME NULL,
                error_message LONGTEXT NULL,
                PRIMARY KEY (id),
                INDEX idx_notif_delivery_tenant (tenant_id),
                INDEX idx_notif_delivery_status (status),
                INDEX idx_notif_delivery_rule (rule_id),
                INDEX idx_notif_delivery_attempted (attempted_at),
                CONSTRAINT fk_notif_delivery_tenant FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE,
                CONSTRAINT fk_notif_delivery_rule FOREIGN KEY (rule_id) REFERENCES notification_rule (id) ON DELETE CASCADE,
                CONSTRAINT fk_notif_delivery_channel FOREIGN KEY (channel_id) REFERENCES notification_channel (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        // 5. notification_template
        $this->addSql(<<<SQL
            CREATE TABLE IF NOT EXISTS notification_template (
                id INT NOT NULL AUTO_INCREMENT,
                tenant_id INT NULL,
                template_key VARCHAR(80) NOT NULL,
                name VARCHAR(255) NOT NULL,
                default_event_type VARCHAR(80) NOT NULL,
                default_conditions JSON NOT NULL,
                default_channels JSON NOT NULL,
                category VARCHAR(40) NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_template_key_tenant (template_key, tenant_id),
                INDEX idx_notif_tpl_category (category),
                CONSTRAINT fk_notif_tpl_tenant FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        // 6. Extend users table
        $this->addSql(<<<SQL
            ALTER TABLE users
                ADD COLUMN IF NOT EXISTS in_app_notifications_enabled TINYINT(1) NOT NULL DEFAULT 1,
                ADD COLUMN IF NOT EXISTS last_seen_notifications DATETIME NULL
        SQL);

        // 7. Seed 6 global Tier-1 notification templates (tenant_id = NULL)
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->addSql(
            'INSERT INTO notification_template (tenant_id, template_key, name, default_event_type, default_conditions, default_channels, category, created_at, updated_at) VALUES (NULL, :key, :name, :event, :conds, :chans, :cat, :created, :updated)',
            [
                'key'     => 'tier1.databreach_high_to_ciso_webhook',
                'name'    => 'Notify CISO via Webhook on DataBreach severity≥high',
                'event'   => 'data_breach.created',
                'conds'   => json_encode([['field' => 'severity', 'op' => '>=', 'value' => 'high']]),
                'chans'   => json_encode([['type' => 'webhook']]),
                'cat'     => 'privacy',
                'created' => $now,
                'updated' => $now,
            ],
        );

        $this->addSql(
            'INSERT INTO notification_template (tenant_id, template_key, name, default_event_type, default_conditions, default_channels, category, created_at, updated_at) VALUES (NULL, :key, :name, :event, :conds, :chans, :cat, :created, :updated)',
            [
                'key'     => 'tier1.incident_high_to_security_email',
                'name'    => 'Notify Security-Team via Email on Incident criticality≥high',
                'event'   => 'incident.created',
                'conds'   => json_encode([['field' => 'criticality', 'op' => '>=', 'value' => 'high']]),
                'chans'   => json_encode([['type' => 'email']]),
                'cat'     => 'incident',
                'created' => $now,
                'updated' => $now,
            ],
        );

        $this->addSql(
            'INSERT INTO notification_template (tenant_id, template_key, name, default_event_type, default_conditions, default_channels, category, created_at, updated_at) VALUES (NULL, :key, :name, :event, :conds, :chans, :cat, :created, :updated)',
            [
                'key'     => 'tier1.databreach_24h_unsolved_to_dpo_email',
                'name'    => 'Notify DPO via Email when DataBreach unsolved >24h',
                'event'   => 'data_breach.sla.approaching',
                'conds'   => json_encode([['field' => 'hours_elapsed', 'op' => '>=', 'value' => '24']]),
                'chans'   => json_encode([['type' => 'email']]),
                'cat'     => 'privacy',
                'created' => $now,
                'updated' => $now,
            ],
        );

        $this->addSql(
            'INSERT INTO notification_template (tenant_id, template_key, name, default_event_type, default_conditions, default_channels, category, created_at, updated_at) VALUES (NULL, :key, :name, :event, :conds, :chans, :cat, :created, :updated)',
            [
                'key'     => 'tier1.risk_exceeds_appetite_to_owner_email',
                'name'    => 'Notify Risk-Owner via Email on Risk exceeds-appetite',
                'event'   => 'risk.exceeds_appetite',
                'conds'   => json_encode([['field' => 'exceeds_appetite', 'op' => '==', 'value' => true]]),
                'chans'   => json_encode([['type' => 'email']]),
                'cat'     => 'compliance',
                'created' => $now,
                'updated' => $now,
            ],
        );

        $this->addSql(
            'INSERT INTO notification_template (tenant_id, template_key, name, default_event_type, default_conditions, default_channels, category, created_at, updated_at) VALUES (NULL, :key, :name, :event, :conds, :chans, :cat, :created, :updated)',
            [
                'key'     => 'tier1.workflow_step_completed_to_auditor_webhook',
                'name'    => 'Notify Auditor via Webhook on Workflow.step completed',
                'event'   => 'workflow.step.completed',
                'conds'   => json_encode([['field' => 'step_status', 'op' => '==', 'value' => 'completed']]),
                'chans'   => json_encode([['type' => 'webhook']]),
                'cat'     => 'compliance',
                'created' => $now,
                'updated' => $now,
            ],
        );

        $this->addSql(
            'INSERT INTO notification_template (tenant_id, template_key, name, default_event_type, default_conditions, default_channels, category, created_at, updated_at) VALUES (NULL, :key, :name, :event, :conds, :chans, :cat, :created, :updated)',
            [
                'key'     => 'tier1.control_overdue_to_ciso_inapp',
                'name'    => 'Notify CISO via In-App on ControlImplementation.overdueVerification',
                'event'   => 'control.verification.overdue',
                'conds'   => json_encode([['field' => 'verification_overdue', 'op' => '==', 'value' => true]]),
                'chans'   => json_encode([['type' => 'in_app']]),
                'cat'     => 'compliance',
                'created' => $now,
                'updated' => $now,
            ],
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP COLUMN IF EXISTS in_app_notifications_enabled, DROP COLUMN IF EXISTS last_seen_notifications');
        $this->addSql('DROP TABLE IF EXISTS notification_delivery');
        $this->addSql('DROP TABLE IF EXISTS notification_rule_channel');
        $this->addSql('DROP TABLE IF EXISTS notification_rule');
        $this->addSql('DROP TABLE IF EXISTS notification_template');
        $this->addSql('DROP TABLE IF EXISTS notification_channel');
    }
}
