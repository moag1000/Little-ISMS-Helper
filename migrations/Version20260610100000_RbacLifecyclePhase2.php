<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Junior-ISB-Audit Phase-2 Lifecycle — RBAC core entities.
 *
 * Adds the marking-store column (`status`) and the optimistic-lock guard
 * (`lock_version`) to `permissions` and `roles` so the Symfony Workflow
 * component can drive:
 *
 *   permission_lifecycle (4 stages):
 *       draft → active → deprecated → archived
 *       (+ active→archived emergency path, + deprecated→active reinstatement)
 *
 *   role_lifecycle (3 stages):
 *       draft → active → archived
 *       (+ archived→active reactivation; archive + reactivate are 4-eyes)
 *
 * ISO 27001 A.5.15-A.5.18 — the role + permission catalogs are RBAC
 * artifacts that auditors evidence-walk. Versioning them makes
 * "which permission was active during incident X" audit-traceable.
 *
 * CLAUDE.md pitfall #6: DDL migration — isTransactional() = false to avoid
 * SAVEPOINT errors when running multiple DDL migrations in a single
 * migrate call.
 *
 * Per [[migration-consolidation]] — one migration covers both entities.
 */
final class Version20260610100000_RbacLifecyclePhase2 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Junior-ISB-Audit Phase-2 Lifecycle — add status + lock_version to permissions + roles';
    }

    public function isTransactional(): bool
    {
        return false; // DDL — CLAUDE.md pitfall #6
    }

    public function up(Schema $schema): void
    {
        // -------------------------------------------------------------
        // permissions table
        // -------------------------------------------------------------
        if ($schema->hasTable('permissions')) {
            $table = $schema->getTable('permissions');

            if (!$table->hasColumn('status')) {
                $this->addSql(
                    "ALTER TABLE permissions "
                    . "ADD status VARCHAR(30) NOT NULL DEFAULT 'draft'"
                );
            }

            if (!$table->hasColumn('lock_version')) {
                $this->addSql(
                    'ALTER TABLE permissions '
                    . 'ADD lock_version INT NOT NULL DEFAULT 0'
                );
            }

            // Backfill: every pre-existing permission row is "active" — the
            // catalog was already operational. `draft` would erroneously
            // hide them from voter lookups + role-binding UIs.
            $this->addSql(
                "UPDATE permissions SET status = 'active' WHERE status = 'draft'"
            );

            if (!$table->hasIndex('idx_permission_status')) {
                $this->addSql(
                    'CREATE INDEX idx_permission_status ON permissions (status)'
                );
            }
        } else {
            $this->write('Skipping permissions lifecycle migration — table not found.');
        }

        // -------------------------------------------------------------
        // roles table
        // -------------------------------------------------------------
        if ($schema->hasTable('roles')) {
            $table = $schema->getTable('roles');

            if (!$table->hasColumn('status')) {
                $this->addSql(
                    "ALTER TABLE roles "
                    . "ADD status VARCHAR(30) NOT NULL DEFAULT 'draft'"
                );
            }

            if (!$table->hasColumn('lock_version')) {
                $this->addSql(
                    'ALTER TABLE roles '
                    . 'ADD lock_version INT NOT NULL DEFAULT 0'
                );
            }

            // Backfill: every pre-existing role row is "active" — same
            // rationale as permissions; `draft` would orphan user-bindings
            // from the assignment UI.
            $this->addSql(
                "UPDATE roles SET status = 'active' WHERE status = 'draft'"
            );

            if (!$table->hasIndex('idx_role_status')) {
                $this->addSql(
                    'CREATE INDEX idx_role_status ON roles (status)'
                );
            }
        } else {
            $this->write('Skipping roles lifecycle migration — table not found.');
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('permissions')) {
            $table = $schema->getTable('permissions');
            if ($table->hasIndex('idx_permission_status')) {
                $this->addSql('DROP INDEX idx_permission_status ON permissions');
            }
            if ($table->hasColumn('lock_version')) {
                $this->addSql('ALTER TABLE permissions DROP COLUMN lock_version');
            }
            if ($table->hasColumn('status')) {
                $this->addSql('ALTER TABLE permissions DROP COLUMN status');
            }
        }

        if ($schema->hasTable('roles')) {
            $table = $schema->getTable('roles');
            if ($table->hasIndex('idx_role_status')) {
                $this->addSql('DROP INDEX idx_role_status ON roles');
            }
            if ($table->hasColumn('lock_version')) {
                $this->addSql('ALTER TABLE roles DROP COLUMN lock_version');
            }
            if ($table->hasColumn('status')) {
                $this->addSql('ALTER TABLE roles DROP COLUMN status');
            }
        }
    }
}
