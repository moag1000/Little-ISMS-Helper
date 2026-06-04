<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * GDPR Art. 10 (audit finding M-6): documented legal basis / authority for
 * processing criminal-conviction data. Mandatory when processesCriminalData is
 * set (enforced by an entity validator), mirroring the Art. 9(2) gate.
 */
final class Version20260704110000_ProcessingActivityCriminalDataLegalBasis extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add criminal_data_legal_basis to processing_activity (GDPR Art. 10)';
    }

    public function isTransactional(): bool
    {
        // DDL commits implicitly under MySQL — keep out of the per-migration
        // SAVEPOINT so a multi-migration run does not break.
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE processing_activity ADD criminal_data_legal_basis VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE processing_activity DROP criminal_data_legal_basis');
    }
}
