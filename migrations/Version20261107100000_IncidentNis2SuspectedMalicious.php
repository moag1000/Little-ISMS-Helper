<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * NIS-2 Art. 23 (4) (a): the early warning states whether the incident is
 * suspected of being caused by unlawful or malicious acts. The BSI export
 * used to send "yes" for every incident; it now reads this assessment
 * (null = not yet assessed).
 */
final class Version20261107100000_IncidentNis2SuspectedMalicious extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Incident: add nis2_suspected_malicious (NIS-2 Art. 23 (4) (a) early-warning statement)';
    }

    public function isTransactional(): bool
    {
        // ALTER TABLE commits implicitly in MySQL.
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE incident ADD nis2_suspected_malicious TINYINT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE incident DROP nis2_suspected_malicious');
    }
}
