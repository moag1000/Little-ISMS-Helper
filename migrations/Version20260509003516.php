<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ISO 27001 §6.2.b — Monitoring-Plan-Felder fuer ISMSObjective.
 *
 * Norm verlangt explizit fuer jedes Sicherheitsziel: was wird gemessen,
 * wie oft, wer ist verantwortlich. Bisher hatte ISMSObjective nur
 * targetValue/currentValue/measurableIndicators — die Wer/Wann/Wie-Frage
 * blieb offen.
 *
 * Felder:
 *  - measurement_frequency VARCHAR(32) — daily|weekly|monthly|quarterly|
 *    biannually|annually|on_event
 *  - measurement_method TEXT — Freitext-Beschreibung der Mess-Methode
 *  - responsible_for_measurement VARCHAR(100) — separater Steward (kann
 *    von responsible_person abweichen)
 */
final class Version20260509003516 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ISO 27001 §6.2.b: ISMSObjective monitoring fields (measurementFrequency, measurementMethod, responsibleForMeasurement)';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ismsobjective
            ADD COLUMN measurement_frequency VARCHAR(32) DEFAULT NULL,
            ADD COLUMN measurement_method LONGTEXT DEFAULT NULL,
            ADD COLUMN responsible_for_measurement VARCHAR(100) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ismsobjective
            DROP COLUMN measurement_frequency,
            DROP COLUMN measurement_method,
            DROP COLUMN responsible_for_measurement');
    }
}
