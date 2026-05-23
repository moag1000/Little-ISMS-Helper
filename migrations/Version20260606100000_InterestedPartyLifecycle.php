<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Junior-ISB-Audit-2026-05-22 S-01 — Sprint S13 InterestedParty lifecycle.
 *
 * Adds the marking-store column (`status`) and the optimistic-lock guard
 * (`lock_version`) to `interested_party` so the Symfony Workflow component
 * can drive the four-stage `interested_party_lifecycle`:
 *
 *     draft → active ⇄ in_review → archived → active (reactivate)
 *
 * ISO 27001 Cl. 4.2 + 9.3.2 c — Mgmt-Review input bundle must reference a
 * versioned stakeholder snapshot. Without the lifecycle the auditor cannot
 * tell which party-record fed which review cycle (Minor-NC risk).
 *
 * CLAUDE.md pitfall #6: DDL migration — isTransactional() = false to avoid
 * SAVEPOINT errors when running multiple DDL migrations in a single
 * migrate call.
 */
final class Version20260606100000_InterestedPartyLifecycle extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Junior-ISB-Audit-2026-05-22 S-01 — add status + lock_version to interested_party';
    }

    public function isTransactional(): bool
    {
        return false; // DDL — CLAUDE.md pitfall #6
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('interested_party')) {
            $this->write('Skipping S-01 lifecycle migration — interested_party table not found.');
            return;
        }

        $table = $schema->getTable('interested_party');

        if (!$table->hasColumn('status')) {
            $this->addSql(
                "ALTER TABLE interested_party "
                . "ADD status VARCHAR(30) NOT NULL DEFAULT 'draft'"
            );
        }

        if (!$table->hasColumn('lock_version')) {
            $this->addSql(
                'ALTER TABLE interested_party '
                . 'ADD lock_version INT NOT NULL DEFAULT 0'
            );
        }

        // Backfill: all pre-existing rows count as `active` (they exist in
        // the system because the org already curated them — `draft` would
        // erroneously hide them from the next Mgmt-Review input bundle).
        $this->addSql(
            "UPDATE interested_party SET status = 'active' WHERE status = 'draft'"
        );

        // Status index for filtered list queries.
        if (!$table->hasIndex('idx_interested_party_status')) {
            $this->addSql(
                'CREATE INDEX idx_interested_party_status ON interested_party (status)'
            );
        }
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('interested_party')) {
            return;
        }
        $table = $schema->getTable('interested_party');

        if ($table->hasIndex('idx_interested_party_status')) {
            $this->addSql('DROP INDEX idx_interested_party_status ON interested_party');
        }
        if ($table->hasColumn('lock_version')) {
            $this->addSql('ALTER TABLE interested_party DROP COLUMN lock_version');
        }
        if ($table->hasColumn('status')) {
            $this->addSql('ALTER TABLE interested_party DROP COLUMN status');
        }
    }
}
