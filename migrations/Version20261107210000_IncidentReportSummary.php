<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * P6: the short report text of an incident (NIS-2 Art. 23 (4), DORA Art. 19),
 * written by a person or accepted from the "Entwurf vorschlagen" draft. The
 * NIS-2 MUS export sends it as the short description when it is set.
 */
final class Version20261107210000_IncidentReportSummary extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Incident: report summary text for authority reports';
    }

    public function isTransactional(): bool
    {
        // ALTER TABLE commits implicitly in MySQL (invalidates the SAVEPOINT).
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE incident ADD report_summary LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE incident DROP COLUMN report_summary');
    }
}
