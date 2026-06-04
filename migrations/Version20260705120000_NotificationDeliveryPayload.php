<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * F3 email-digest mode — add `payload` column to `notification_delivery`.
 *
 * Stores the entityState snapshot at dispatch time for digest-mode deliveries
 * (`status = pending_digest`). Null for immediate-mode deliveries (the
 * entityState lives only in the Messenger message for those).
 *
 * Also extends the `status` column (VARCHAR 32 → 32, no change needed in MySQL
 * since `pending_digest` fits in 32 chars) — no DDL for the enum values because
 * they are stored as plain strings, not a MySQL ENUM type.
 */
final class Version20260705120000_NotificationDeliveryPayload extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add payload column to notification_delivery for F3 email-digest mode';
    }

    public function isTransactional(): bool
    {
        // DDL (ALTER TABLE) commits implicitly under MySQL — keep outside the
        // per-migration SAVEPOINT so a multi-migration run does not break.
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE notification_delivery
                ADD COLUMN payload JSON DEFAULT NULL COMMENT 'F3 digest: entityState snapshot for pending_digest rows'
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE notification_delivery DROP COLUMN payload');
    }
}
