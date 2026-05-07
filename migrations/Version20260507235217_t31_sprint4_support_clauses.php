<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * T31.4.1 Sprint 4 — ISO 27001 §7.2 Competence + §7.3 Awareness + §7.4 Communication
 *
 * - training.program_type VARCHAR(50) NULL — Awareness programme classification (§7.3)
 * - users.competencies JSON NULL — Structured competency tracking per user (§7.2)
 * - document 'communication_plan' type: form-only change, no schema migration needed (§7.4)
 */
final class Version20260507235217 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'T31.4.1: ISO 27001 §7.2/§7.3/§7.4 — Training programType + User competencies JSON';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE training ADD program_type VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD competencies JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE training DROP program_type');
        $this->addSql('ALTER TABLE users DROP competencies');
    }
}
