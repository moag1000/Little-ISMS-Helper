<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MRIS-Klassifikation auf der Control-Entität.
 *
 * Fügt zwei nullable-Felder hinzu, die die MRIS-Bewertung pro ISO-27002-Annex-A-Control
 * persistieren:
 * - mythos_resilience: enum-ähnliches VARCHAR (standfest|degradiert|reibung|nicht_betroffen)
 * - mythos_flanking_mhcs: JSON-Liste der flankierenden MHC-IDs
 *
 * Quelle: Peddi, R. (2026). MRIS — Mythos-resistente Informationssicherheit, v1.5
 * Lizenz: CC BY 4.0 (https://creativecommons.org/licenses/by/4.0/)
 */
final class Version20260426132821 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'MRIS v1.5: add mythos_resilience + mythos_flanking_mhcs to control';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE control ADD mythos_resilience VARCHAR(20) DEFAULT NULL, ADD mythos_flanking_mhcs JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE control DROP mythos_resilience, DROP mythos_flanking_mhcs');
    }
}
