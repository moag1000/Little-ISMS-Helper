<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260507195627 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tier-3 Settings: Tenant.api_rate_limit_per_minute + 5 global SystemSettings seeds (backup, license, feature, telemetry, deployment)';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE tenant
            ADD COLUMN api_rate_limit_per_minute INT DEFAULT 600 COMMENT 'API requests/minute per tenant (default 600 = 10/sec)'");

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        foreach ([
            ['backup', 'schedule_cron', '"0 2 * * *"', 'Backup schedule cron-expression (default 02:00 daily)'],
            ['backup', 'retention_days', '90', 'Backup retention in days (90 = recommended for ISO 27001 evidence horizon)'],
            ['license', 'max_tenants', '0', 'Max number of tenants (0 = unlimited)'],
            ['license', 'max_users_per_tenant', '0', 'Max users per tenant (0 = unlimited)'],
            ['features', 'experimental_flags', '[]', 'Active experimental feature flags as JSON array'],
            ['telemetry', 'opt_in', 'false', 'Anonymous telemetry opt-in (DSGVO-konform off-by-default)'],
            ['deployment', 'environment_label', '"production"', 'Banner label shown across UI: production|staging|preview|dev'],
        ] as $setting) {
            [$category, $key, $value, $description] = $setting;
            $valueQ = $this->connection->quote($value);
            $descQ = $this->connection->quote($description);
            $catQ = $this->connection->quote($category);
            $keyQ = $this->connection->quote($key);
            $nowQ = $this->connection->quote($now);
            $this->addSql("
                INSERT INTO system_settings (category, setting_key, value, is_encrypted, description, created_at, updated_at, updated_by)
                SELECT {$catQ}, {$keyQ}, {$valueQ}, 0, {$descQ}, {$nowQ}, {$nowQ}, 'system'
                WHERE NOT EXISTS (SELECT 1 FROM system_settings WHERE category={$catQ} AND setting_key={$keyQ})
            ");
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE tenant DROP COLUMN api_rate_limit_per_minute");
        $this->addSql("DELETE FROM system_settings WHERE category IN ('backup','license','features','telemetry','deployment')");
    }
}
