<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * B3: audit-log rows signed before this release covered the creation time with
 * microseconds, which the DATETIME column never stored — their signature cannot be
 * re-computed. The id of the newest such row is kept as
 * system_settings(audit_log, hmac_legacy_until_id); the chain check reports these
 * rows as "not verifiable" instead of "tampered", their chain links stay checked.
 *
 * Data only, re-runnable (inserts once).
 */
final class Version20261109200000_AuditLogHmacLegacyCutoff extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Audit log: remember the last row signed with the old (unverifiable) timestamp format';
    }

    public function up(Schema $schema): void
    {
        $exists = $this->connection->fetchOne(
            "SELECT COUNT(*) FROM system_settings WHERE category = 'audit_log' AND setting_key = 'hmac_legacy_until_id'",
        );
        if ((int) $exists > 0) {
            return;
        }
        $maxId = (int) $this->connection->fetchOne('SELECT COALESCE(MAX(id), 0) FROM audit_log');

        $this->addSql(
            'INSERT INTO system_settings (category, setting_key, value, is_encrypted, description, created_at)'
            . ' VALUES (:category, :key, :value, :encrypted, :description, :createdAt)',
            [
                'category' => 'audit_log',
                'key' => 'hmac_legacy_until_id',
                'value' => json_encode($maxId),
                'encrypted' => false,
                'description' => 'Audit-log rows up to this id were signed with microseconds and cannot be re-verified (B3).',
                'createdAt' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ],
            [
                'encrypted' => \Doctrine\DBAL\ParameterType::BOOLEAN,
            ],
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM system_settings WHERE category = 'audit_log' AND setting_key = 'hmac_legacy_until_id'");
    }
}
