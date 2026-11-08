<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * P7 NIS-2 fine-tuning:
 *  - the intermediate report on request (NIS-2 Art. 23 (4) (c)) — when the
 *    CSIRT asked, by when, when it was sent;
 *  - BC plans without own recovery targets take RTO/RPO from the BIA of their
 *    business process (ISO 22301 8.2.2 → 8.4), as new plans do from now on.
 */
final class Version20261108020000_Nis2FineTuning extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Incident: NIS-2 intermediate report on request; BC plan RTO/RPO from the BIA';
    }

    public function isTransactional(): bool
    {
        // ALTER TABLE commits implicitly in MySQL (invalidates the SAVEPOINT).
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE incident ADD nis2_interim_requested_at DATETIME DEFAULT NULL, ADD nis2_interim_due_at DATETIME DEFAULT NULL, ADD nis2_interim_reported_at DATETIME DEFAULT NULL');
        $this->addSql('UPDATE business_continuity_plan p INNER JOIN business_process b ON b.id = p.business_process_id SET p.rto = b.rto WHERE p.rto IS NULL AND b.rto IS NOT NULL');
        $this->addSql('UPDATE business_continuity_plan p INNER JOIN business_process b ON b.id = p.business_process_id SET p.rpo = b.rpo WHERE p.rpo IS NULL AND b.rpo IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE incident DROP nis2_interim_requested_at, DROP nis2_interim_due_at, DROP nis2_interim_reported_at');
    }
}
