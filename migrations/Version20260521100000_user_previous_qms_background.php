<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Audit-S5 P-12 — User.previousQmsBackground.
 *
 * New nullable column on `users` that captures the user's prior QM-system
 * background (ISO 9001 / 14001 / other). Drives visibility of the
 * "Norm-Bridge" hints rendered underneath form-fields so users coming
 * from ISO 9001 can recognise familiar terms (Interested Party →
 * Stakeholder, Major Nonconformity → 27001 Finding, etc.).
 *
 * `isTransactional() = false` — MySQL implicitly commits ALTER TABLE
 * which invalidates Doctrine's per-migration SAVEPOINT (CLAUDE.md
 * Common-Pitfalls #6).
 */
final class Version20260521100000_user_previous_qms_background extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Audit-S5 P-12: users.previous_qms_background — drives Norm-Bridge visibility.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE users
                ADD COLUMN previous_qms_background VARCHAR(32) DEFAULT NULL
                COMMENT 'Prior QM-System background (iso_9001 / iso_14001 / other / none) — Audit-S5 P-12'
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE users
                DROP COLUMN previous_qms_background
        SQL);
    }
}
