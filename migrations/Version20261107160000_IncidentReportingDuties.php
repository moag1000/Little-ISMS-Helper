<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Paket 4 (Meldepflicht), one field per fact on the incident:
 *  - notification_required becomes the organisation's NIS-2 assessment
 *    (NULL = not assessed yet). No form ever set it, so every stored 0 is
 *    "not assessed", not "assessed as not reportable".
 *  - DORA final report timestamp (Art. 19 (4) (c)).
 *  - The second, never-used DORA column set and economic_impact merge into
 *    the fields the forms, exports and reports use (a non-empty value wins
 *    only where the target is empty), then they are dropped.
 */
final class Version20261107160000_IncidentReportingDuties extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Incident: NIS-2 assessment tri-state, DORA final report, merge duplicate DORA columns';
    }

    public function isTransactional(): bool
    {
        // ALTER TABLE commits implicitly in MySQL (invalidates the SAVEPOINT).
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE incident MODIFY notification_required TINYINT(1) DEFAULT NULL');
        $this->addSql('UPDATE incident SET notification_required = NULL WHERE notification_required = 0');
        $this->addSql("ALTER TABLE incident ADD dora_final_report_submitted_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'");

        $this->addSql('UPDATE incident SET estimated_financial_impact = economic_impact WHERE estimated_financial_impact IS NULL AND economic_impact IS NOT NULL');
        $this->addSql('UPDATE incident SET estimated_financial_impact = dora_economic_impact_eur WHERE estimated_financial_impact IS NULL AND dora_economic_impact_eur IS NOT NULL');
        $this->addSql('UPDATE incident SET clients_affected = dora_clients_impacted WHERE clients_affected IS NULL AND dora_clients_impacted IS NOT NULL');
        $this->addSql('UPDATE incident SET data_loss_occurred = 1 WHERE dora_data_loss_occurred = 1');
        $this->addSql("UPDATE incident SET ict_incident_classification = 'major_ict_incident' WHERE ict_incident_classification IS NULL AND dora_classification = 'major'");

        $this->addSql('ALTER TABLE incident DROP COLUMN economic_impact, DROP COLUMN dora_clients_impacted, DROP COLUMN dora_reputation_impact, DROP COLUMN dora_service_downtime_minutes, DROP COLUMN dora_geographical_spread, DROP COLUMN dora_data_loss_occurred, DROP COLUMN dora_economic_impact_eur, DROP COLUMN dora_classification');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE incident ADD economic_impact NUMERIC(15, 2) DEFAULT NULL, ADD dora_clients_impacted INT DEFAULT NULL, ADD dora_reputation_impact VARCHAR(30) DEFAULT NULL, ADD dora_service_downtime_minutes INT DEFAULT NULL, ADD dora_geographical_spread JSON DEFAULT NULL, ADD dora_data_loss_occurred TINYINT(1) DEFAULT NULL, ADD dora_economic_impact_eur INT DEFAULT NULL, ADD dora_classification VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE incident DROP COLUMN dora_final_report_submitted_at');
        $this->addSql('UPDATE incident SET notification_required = 0 WHERE notification_required IS NULL');
        $this->addSql('ALTER TABLE incident MODIFY notification_required TINYINT(1) NOT NULL');
    }
}
