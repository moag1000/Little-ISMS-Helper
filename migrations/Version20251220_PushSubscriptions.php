<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Push Subscriptions table for PWA push notifications
 */
final class Version20251220_PushSubscriptions extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create push_subscriptions table for PWA push notifications';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE push_subscriptions (
            id INT AUTO_INCREMENT NOT NULL,
            user_id INT NOT NULL,
            tenant_id INT NOT NULL,
            endpoint LONGTEXT NOT NULL,
            endpoint_hash VARCHAR(64) NOT NULL,
            public_key LONGTEXT NOT NULL,
            auth_token LONGTEXT NOT NULL,
            user_agent VARCHAR(500) DEFAULT NULL,
            device_name VARCHAR(100) DEFAULT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            last_used_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            failure_count INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            PRIMARY KEY(id),
            UNIQUE INDEX unique_endpoint (endpoint_hash),
            INDEX idx_push_subscription_user (user_id),
            INDEX idx_push_subscription_tenant (tenant_id),
            CONSTRAINT FK_push_subscription_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
            CONSTRAINT FK_push_subscription_tenant FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE push_subscriptions');
    }
}
