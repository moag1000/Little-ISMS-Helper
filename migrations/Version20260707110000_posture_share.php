<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * F43 Trust-Center — add public posture sharing columns to `tenant`.
 *
 * publicPostureEnabled: opt-in gate (default FALSE — no unauthenticated
 *   tenant data is exposed until an admin explicitly enables sharing).
 * publicPostureToken: random 32+ char URL token for /trust/{token} endpoint.
 *   UNIQUE constraint prevents token collisions across tenants.
 *
 * TENANT-DISCLOSURE-SAFE: these columns only gate the §4 compliance-posture
 * surface. They carry NO sensitive data themselves.
 */
final class Version20260707110000_posture_share extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'F43: Add public_posture_enabled (BOOL) and public_posture_token (VARCHAR 64 UNIQUE NULL) to tenant table';
    }

    public function isTransactional(): bool
    {
        // DDL (ALTER TABLE) commits implicitly under MySQL — must run outside
        // the per-migration SAVEPOINT to avoid SAVEPOINT-does-not-exist errors
        // when multiple DDL migrations run in a single `migrate` call.
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE tenant
                ADD COLUMN public_posture_enabled TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'F43: Opt-in gate — tenant allows public compliance-posture sharing',
                ADD COLUMN public_posture_token VARCHAR(64) DEFAULT NULL COMMENT 'F43: Random bearer token for /trust/{token} URL (32+ chars)',
                ADD UNIQUE INDEX UNIQ_posture_token (public_posture_token)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE tenant
                DROP INDEX UNIQ_posture_token,
                DROP COLUMN public_posture_token,
                DROP COLUMN public_posture_enabled
        SQL);
    }
}
