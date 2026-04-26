<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * AI-Agent-Inventar als Asset-Subtyp (assetType='ai_agent').
 *
 * Erfüllt gleichzeitig:
 * - EU AI Act Art. 6 (Risikoklassifikation), Art. 9 (Risikomanagement),
 *   Art. 10 (Datengovernance), Art. 11 (Technische Dokumentation),
 *   Art. 14 (Human Oversight), Art. 16 (Anbieter-Pflichten)
 * - ISO/IEC 42001 Annex A (AIMS)
 * - MRIS v1.5 MHC-13 (AI-Agent-Governance, Peddi 2026, CC BY 4.0)
 * - ISO/IEC 27001:2022 A.5.16 (Identitätsmanagement), A.8.27 (Architektur)
 *
 * Alle Felder nullable — nur für Assets mit assetType='ai_agent' relevant.
 */
final class Version20260426153940 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'AI-Agent-Inventar: 9 nullable Felder auf asset für EU AI Act + ISO 42001 + MRIS MHC-13';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE asset ADD ai_agent_classification VARCHAR(30) DEFAULT NULL, ADD ai_agent_purpose LONGTEXT DEFAULT NULL, ADD ai_agent_data_sources LONGTEXT DEFAULT NULL, ADD ai_agent_oversight_mechanism VARCHAR(255) DEFAULT NULL, ADD ai_agent_provider VARCHAR(255) DEFAULT NULL, ADD ai_agent_model_version VARCHAR(100) DEFAULT NULL, ADD ai_agent_capability_scope JSON DEFAULT NULL, ADD ai_agent_threat_model_doc_id INT DEFAULT NULL, ADD ai_agent_extension_allowlist JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE asset DROP ai_agent_classification, DROP ai_agent_purpose, DROP ai_agent_data_sources, DROP ai_agent_oversight_mechanism, DROP ai_agent_provider, DROP ai_agent_model_version, DROP ai_agent_capability_scope, DROP ai_agent_threat_model_doc_id, DROP ai_agent_extension_allowlist');
    }
}
