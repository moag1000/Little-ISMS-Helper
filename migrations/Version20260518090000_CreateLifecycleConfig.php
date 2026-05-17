<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lifecycle Foundation Pilot — tenant-scoped overrides for workflow metadata.
 *
 * Idempotent: skips when the table already exists. Required because Sprint X.0
 * shipped the LifecycleConfig entity without a dedicated migration on main
 * (lost during cherry-pick rebase); fresh-DB installs need this to bring the
 * schema in sync.
 */
final class Version20260518090000_CreateLifecycleConfig extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Lifecycle Foundation Pilot — tenant-scoped overrides for workflow metadata.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('lifecycle_config')) {
            $this->warnIf(true, 'Skipping lifecycle_config create: table already present.');
            return;
        }

        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS lifecycle_config (
                id INT AUTO_INCREMENT NOT NULL,
                tenant_id INT NOT NULL,
                workflow_name VARCHAR(64) NOT NULL,
                transition_name VARCHAR(64) NOT NULL,
                config_key VARCHAR(64) NOT NULL,
                config_value JSON NOT NULL,
                updated_at DATETIME NOT NULL,
                updated_by_user_id INT DEFAULT NULL,
                PRIMARY KEY(id),
                UNIQUE KEY uniq_lifecycle_override (tenant_id, workflow_name, transition_name, config_key),
                KEY idx_lifecycle_lookup (tenant_id, workflow_name),
                CONSTRAINT fk_lifecycle_tenant FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE,
                CONSTRAINT fk_lifecycle_user FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS lifecycle_config');
    }
}
