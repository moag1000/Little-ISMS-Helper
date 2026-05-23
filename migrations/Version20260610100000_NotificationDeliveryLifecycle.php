<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Junior-ISB-Audit Phase-2 Lifecycle — NotificationDelivery state-machine.
 *
 * Adds the optimistic-lock guard (`lock_version`) to `notification_delivery`
 * so the Symfony Workflow component can drive the six-stage
 * `notification_delivery_lifecycle`:
 *
 *   pending → sent → delivered → archived
 *                 ↘            ↗
 *                   failed → retrying → sent
 *                        ↘
 *                          archived (after retention window)
 *
 * The status column itself is unchanged: it is already VARCHAR(32), which
 * accommodates the new `delivered` (9 chars) and `archived` (8 chars) values
 * without a schema change. No data backfill is needed — existing rows in
 * pending/sent/failed/retrying remain valid lifecycle states.
 *
 * ISO 27001 Cl. 7.4 + DORA Art. 19 — incident reporting evidence must be
 * end-to-end-traceable, not just dispatch-logged. The `delivered` stage
 * records positive ACK from the receiver (read-receipt, webhook ACK body,
 * SMTP 250).
 *
 * CLAUDE.md pitfall #6: DDL migration — isTransactional() = false to avoid
 * SAVEPOINT errors when running multiple DDL migrations in a single migrate
 * call.
 */
final class Version20260610100000_NotificationDeliveryLifecycle extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Junior-ISB-Audit Phase-2 Lifecycle — add lock_version to notification_delivery (6-stage state-machine)';
    }

    public function isTransactional(): bool
    {
        return false; // DDL — CLAUDE.md pitfall #6
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('notification_delivery')) {
            $this->write('Skipping Phase-2 lifecycle migration — notification_delivery table not found.');
            return;
        }

        $table = $schema->getTable('notification_delivery');

        if (!$table->hasColumn('lock_version')) {
            $this->addSql(
                'ALTER TABLE notification_delivery '
                . 'ADD lock_version INT NOT NULL DEFAULT 0'
            );
        }
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('notification_delivery')) {
            return;
        }
        $table = $schema->getTable('notification_delivery');

        if ($table->hasColumn('lock_version')) {
            $this->addSql('ALTER TABLE notification_delivery DROP COLUMN lock_version');
        }
    }
}
