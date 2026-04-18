<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ISB Sprint-2 gate (docs/DATA_REUSE_PLAN_REVIEW_ISB.md): every audit row
 * must expose the acting user's RBAC role. Nullable column → no backfill
 * needed; pre-migration rows stay NULL and are reported as "role unknown".
 *
 * Deploy sequence:
 *   1. Run this migration.
 *   2. Deploy the app code that writes `actor_role` on every new row.
 *   3. Optional: run `bin/console app:audit-log:resign` once to include
 *      existing rows' (NULL) actor_role in the HMAC chain.
 */
final class Version20260418190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'AuditLog.actor_role column + idx_audit_actor_role (ISB Sprint-2 gate).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE audit_log ADD COLUMN actor_role VARCHAR(30) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_audit_actor_role ON audit_log (actor_role)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_audit_actor_role ON audit_log');
        $this->addSql('ALTER TABLE audit_log DROP COLUMN actor_role');
    }
}
