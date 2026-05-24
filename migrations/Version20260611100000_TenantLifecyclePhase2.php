<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Junior-ISB-Audit Phase-2 Lifecycle — Tenant (security-critical, 30+ isActive callsites preserved via wrapper).
 *
 * Adds the marking-store column (`status`) and the optimistic-lock guard
 * (`lock_version`) to `tenant` so the Symfony Workflow component can drive
 * the 5-stage `tenant_lifecycle`:
 *
 *     draft → active ⇄ suspended → terminated → archived
 *
 * Backfill strategy (additive — `is_active` column preserved for the 30+
 * legacy readers that hit it directly via Doctrine
 * `findBy(['isActive' => true])`):
 *
 *     existing row with is_active = 1 → status = 'active'
 *     existing row with is_active = 0 → status = 'suspended'
 *
 * New rows default to `status = 'draft'` so the lifecycle initial-marking
 * boots correctly; the SetupWizard / TenantManagementController explicitly
 * flips it to 'active' on create.
 *
 * Tenant is the multi-tenancy root — security-critical: SUPER_ADMIN gates
 * every transition, 4-eyes mandatory on suspend / reactivate / terminate.
 *
 * CLAUDE.md pitfall #6: DDL migration — isTransactional() = false to avoid
 * SAVEPOINT errors when running multiple DDL migrations in a single
 * migrate call.
 *
 * Per [[migration-consolidation]] — one migration for the Tenant lifecycle.
 */
final class Version20260611100000_TenantLifecyclePhase2 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Junior-ISB-Audit Phase-2 Lifecycle — add status + lock_version to tenant (additive, isActive preserved)';
    }

    public function isTransactional(): bool
    {
        return false; // DDL — CLAUDE.md pitfall #6
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('tenant')) {
            $this->write('Skipping Tenant lifecycle migration — tenant table not found.');
            return;
        }

        $table = $schema->getTable('tenant');

        if (!$table->hasColumn('status')) {
            $this->addSql(
                "ALTER TABLE tenant "
                . "ADD status VARCHAR(30) NOT NULL DEFAULT 'draft'"
            );
        }

        if (!$table->hasColumn('lock_version')) {
            $this->addSql(
                'ALTER TABLE tenant '
                . 'ADD lock_version INT NOT NULL DEFAULT 0'
            );
        }

        // Backfill — preserve the operational reality:
        //   is_active = 1 → status = 'active'
        //   is_active = 0 → status = 'suspended'
        // Pre-existing tenants were never `draft` (they all completed
        // onboarding before the lifecycle existed); `draft` would
        // erroneously hide them from voter lookups + login flows.
        $this->addSql(
            "UPDATE tenant SET status = 'active' "
            . "WHERE is_active = 1 AND status = 'draft'"
        );
        $this->addSql(
            "UPDATE tenant SET status = 'suspended' "
            . "WHERE is_active = 0 AND status = 'draft'"
        );

        if (!$table->hasIndex('idx_tenant_status')) {
            $this->addSql(
                'CREATE INDEX idx_tenant_status ON tenant (status)'
            );
        }
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('tenant')) {
            return;
        }

        $table = $schema->getTable('tenant');

        if ($table->hasIndex('idx_tenant_status')) {
            $this->addSql('DROP INDEX idx_tenant_status ON tenant');
        }
        if ($table->hasColumn('lock_version')) {
            $this->addSql('ALTER TABLE tenant DROP COLUMN lock_version');
        }
        if ($table->hasColumn('status')) {
            $this->addSql('ALTER TABLE tenant DROP COLUMN status');
        }
        // Note: `is_active` is intentionally NOT touched — it predates the
        // lifecycle and is preserved by design.
    }
}
