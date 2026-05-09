<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * T31 Sprint 8: ThreatIntelligence TLP/MITRE/IOC/Confidence fields + Risk FAIR quantitative subset
 *
 * ThreatIntelligence (7 fields, gated vulnerability_intel):
 *   tlpClassification, threatActorAttribution, mitreAttackTactics (JSON),
 *   mitreAttackTechniques (JSON), iocsList (JSON), confidenceLevel, sharedExternally
 *
 * Risk (13 fields, gated quantitative_risk — FAIR model):
 *   lossEventFrequency min/max/mode (NUMERIC 10,4)
 *   threatEventFrequency min/max/mode (NUMERIC 10,4)
 *   vulnerabilityProbability (NUMERIC 5,4)
 *   primaryLossMagnitude min/max/mode (NUMERIC 15,2)
 *   secondaryLossMagnitude min/max/mode (NUMERIC 15,2)
 */
final class Version20260508001342 extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'T31 Sprint 8: ThreatIntel TLP/MITRE/IOC fields + Risk FAIR quantitative subset (LEF/TEF/PLM/SLM)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE risk ADD loss_event_frequency_min NUMERIC(10, 4) DEFAULT NULL, ADD loss_event_frequency_max NUMERIC(10, 4) DEFAULT NULL, ADD loss_event_frequency_mode NUMERIC(10, 4) DEFAULT NULL, ADD threat_event_frequency_min NUMERIC(10, 4) DEFAULT NULL, ADD threat_event_frequency_max NUMERIC(10, 4) DEFAULT NULL, ADD threat_event_frequency_mode NUMERIC(10, 4) DEFAULT NULL, ADD vulnerability_probability NUMERIC(5, 4) DEFAULT NULL, ADD primary_loss_magnitude_min NUMERIC(15, 2) DEFAULT NULL, ADD primary_loss_magnitude_max NUMERIC(15, 2) DEFAULT NULL, ADD primary_loss_magnitude_mode NUMERIC(15, 2) DEFAULT NULL, ADD secondary_loss_magnitude_min NUMERIC(15, 2) DEFAULT NULL, ADD secondary_loss_magnitude_max NUMERIC(15, 2) DEFAULT NULL, ADD secondary_loss_magnitude_mode NUMERIC(15, 2) DEFAULT NULL');
        $this->addSql('ALTER TABLE threat_intelligence ADD tlp_classification VARCHAR(20) DEFAULT NULL, ADD threat_actor_attribution VARCHAR(255) DEFAULT NULL, ADD mitre_attack_tactics JSON DEFAULT NULL, ADD mitre_attack_techniques JSON DEFAULT NULL, ADD iocs_list JSON DEFAULT NULL, ADD confidence_level VARCHAR(20) DEFAULT NULL, ADD shared_externally TINYINT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE risk DROP loss_event_frequency_min, DROP loss_event_frequency_max, DROP loss_event_frequency_mode, DROP threat_event_frequency_min, DROP threat_event_frequency_max, DROP threat_event_frequency_mode, DROP vulnerability_probability, DROP primary_loss_magnitude_min, DROP primary_loss_magnitude_max, DROP primary_loss_magnitude_mode, DROP secondary_loss_magnitude_min, DROP secondary_loss_magnitude_max, DROP secondary_loss_magnitude_mode');
        $this->addSql('ALTER TABLE threat_intelligence DROP tlp_classification, DROP threat_actor_attribution, DROP mitre_attack_tactics, DROP mitre_attack_techniques, DROP iocs_list, DROP confidence_level, DROP shared_externally');
    }
}
