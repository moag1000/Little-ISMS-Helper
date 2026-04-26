<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MRIS Reifegrad-Tracking pro MHC.
 *
 * Fügt drei nullable-Felder auf compliance_requirement hinzu:
 * - maturity_current:    aktueller Stand (initial|defined|managed)
 * - maturity_target:     Soll-Stand
 * - maturity_reviewed_at: Zeitpunkt der letzten Bewertung
 *
 * NULL für alle nicht-MRIS-Requirements (kein Bruch für ISO/NIS2/DORA/etc).
 *
 * Quelle: Peddi, R. (2026). MRIS — Mythos-resistente Informationssicherheit, v1.5
 *         Kap. 9.5 (Reifegrad-Stufen pro MHC).
 * Lizenz: CC BY 4.0.
 */
final class Version20260426145831 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'MRIS v1.5: add maturity_current/target/reviewed_at to compliance_requirement';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compliance_requirement ADD maturity_current VARCHAR(20) DEFAULT NULL, ADD maturity_target VARCHAR(20) DEFAULT NULL, ADD maturity_reviewed_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compliance_requirement DROP maturity_current, DROP maturity_target, DROP maturity_reviewed_at');
    }
}
