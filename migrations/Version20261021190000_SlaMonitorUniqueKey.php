<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * #1059 — at most one SLA clock per record and deadline type.
 *
 * The flush-time spawner and the cron retry (SlaDeadlineMonitorRetry) could
 * both insert a monitor for the same record. Existing duplicates are removed
 * first (the oldest row per key — the first clock started — is kept), then
 * the unique key makes the database reject any further duplicate; both
 * writers treat that rejection as "already started".
 */
final class Version20261021190000_SlaMonitorUniqueKey extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'SLA monitor: dedupe and add UNIQUE (entity_type, entity_id, deadline_type)';
    }

    public function isTransactional(): bool
    {
        // CREATE UNIQUE INDEX commits implicitly in MySQL.
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DELETE m FROM sla_deadline_monitor m
            INNER JOIN sla_deadline_monitor keep
                ON keep.entity_type = m.entity_type
               AND keep.entity_id = m.entity_id
               AND keep.deadline_type = m.deadline_type
               AND keep.id < m.id
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_sla_entity_deadline ON sla_deadline_monitor (entity_type, entity_id, deadline_type)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_sla_entity_deadline ON sla_deadline_monitor');
    }
}
