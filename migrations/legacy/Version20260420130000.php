<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Phase 9.P2.1 — Policy inheritance flags on document.
 *
 *   inheritable       BOOL NOT NULL DEFAULT FALSE
 *   override_allowed  BOOL NOT NULL DEFAULT TRUE
 *
 * Default false for inheritable: existing holding documents are not
 * automatically propagated to subsidiaries; a holding operator has to
 * explicitly flip the flag per document (typical: ISMS Leitlinie,
 * Code of Conduct, Data Protection Policy under concentrated DPO).
 *
 * Default true for override_allowed: a subsidiary may author its own
 * local copy when the inheritable=true document is present. Holding
 * can mandate a policy verbatim by flipping this off.
 *
 * ADD COLUMN IF NOT EXISTS for idempotent re-run.
 */
final class Version20260420130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Holding policy inheritance flags on document (P2.1)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE document ADD COLUMN IF NOT EXISTS inheritable TINYINT(1) NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE document ADD COLUMN IF NOT EXISTS override_allowed TINYINT(1) NOT NULL DEFAULT 1');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE document DROP COLUMN IF EXISTS inheritable');
        $this->addSql('ALTER TABLE document DROP COLUMN IF EXISTS override_allowed');
    }
}
