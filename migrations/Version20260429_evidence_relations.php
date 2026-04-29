<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Evidence Collection: join tables for Control and RiskTreatmentPlan evidence documents.
 */
final class Version20260429_evidence_relations extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create control_evidence and risk_treatment_plan_evidence join tables for evidence collection';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS control_evidence (
            control_id INT NOT NULL,
            document_id INT NOT NULL,
            PRIMARY KEY(control_id, document_id),
            INDEX IDX_control_evidence_control (control_id),
            INDEX IDX_control_evidence_document (document_id),
            CONSTRAINT FK_control_evidence_control FOREIGN KEY (control_id) REFERENCES control (id) ON DELETE CASCADE,
            CONSTRAINT FK_control_evidence_document FOREIGN KEY (document_id) REFERENCES document (id) ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE IF NOT EXISTS risk_treatment_plan_evidence (
            risk_treatment_plan_id INT NOT NULL,
            document_id INT NOT NULL,
            PRIMARY KEY(risk_treatment_plan_id, document_id),
            INDEX IDX_rtp_evidence_rtp (risk_treatment_plan_id),
            INDEX IDX_rtp_evidence_document (document_id),
            CONSTRAINT FK_rtp_evidence_rtp FOREIGN KEY (risk_treatment_plan_id) REFERENCES risk_treatment_plan (id) ON DELETE CASCADE,
            CONSTRAINT FK_rtp_evidence_document FOREIGN KEY (document_id) REFERENCES document (id) ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS control_evidence');
        $this->addSql('DROP TABLE IF EXISTS risk_treatment_plan_evidence');
    }
}
