<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * T31.3.1 Sprint 3 — Cross-Cutting Classifiers
 *
 * Adds classifier fields to existing entities (0 new entities — DRY, Data-Reuse):
 * - AuditFinding.source (ISO 27001 §10.1 — NC origin tracking)
 * - CorrectiveAction.actionType (ISO 27001 §10.1+§10.2 — corrective/preventive/improvement)
 * - ChangeRequest.clauseReference (§6.3/§8.1 differentiation tag)
 */
final class Version20260507234426 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'T31.3.1: Add AuditFinding.source, CorrectiveAction.actionType, ChangeRequest.clauseReference classifiers';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE audit_findings ADD source VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE change_request ADD clause_reference VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE corrective_actions ADD action_type VARCHAR(50) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE audit_findings DROP source');
        $this->addSql('ALTER TABLE change_request DROP clause_reference');
        $this->addSql('ALTER TABLE corrective_actions DROP action_type');
    }
}
