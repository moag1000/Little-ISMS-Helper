<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * W2 norm rest:
 *  - TIA (GDPR Art. 46, EDPB 01/2020 step 6): owner + re-evaluation date
 *    (deadline type tia_review). Assessed TIAs get a date one year after
 *    their assessment, so the duty reaches the DPO without manual work.
 *  - NIS-2 registration (Art. 3 (4), BSIG § 33): fingerprint of the reported
 *    data and the date of the first unreported change (two weeks to report).
 */
final class Version20261108140000_TiaReviewAndNis2RegistrationChanges extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'TIA owner + next review date; NIS-2 registration change tracking';
    }

    public function isTransactional(): bool
    {
        // ALTER TABLE commits implicitly in MySQL.
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE transfer_impact_assessment ADD owner_id INT DEFAULT NULL, ADD next_review_date DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE transfer_impact_assessment ADD CONSTRAINT FK_67F6D9327E3C61F9 FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_67F6D9327E3C61F9 ON transfer_impact_assessment (owner_id)');
        $this->addSql("UPDATE transfer_impact_assessment SET owner_id = assessed_by_id,"
            . " next_review_date = DATE_ADD(COALESCE(assessed_at, DATE(updated_at)), INTERVAL 1 YEAR)"
            . " WHERE status = 'assessed'");

        $this->addSql('ALTER TABLE nis2_registration_profile ADD reported_fingerprint VARCHAR(64) DEFAULT NULL, ADD unreported_change_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE transfer_impact_assessment DROP FOREIGN KEY FK_67F6D9327E3C61F9');
        $this->addSql('DROP INDEX IDX_67F6D9327E3C61F9 ON transfer_impact_assessment');
        $this->addSql('ALTER TABLE transfer_impact_assessment DROP owner_id, DROP next_review_date');
        $this->addSql('ALTER TABLE nis2_registration_profile DROP reported_fingerprint, DROP unreported_change_at');
    }
}
