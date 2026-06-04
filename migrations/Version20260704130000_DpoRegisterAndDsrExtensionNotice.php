<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * W4 maturity:
 *  - N-2 (BDSG § 38 / Art. 37): DPO appointment register fields on tenant.
 *  - N-3 (Art. 12(3)): proof timestamp that the data subject was informed of a
 *    deadline extension.
 */
final class Version20260704130000_DpoRegisterAndDsrExtensionNotice extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add DPO appointment register (tenant) + DSR extension-notified timestamp';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tenant
            ADD dpo_appointment_date DATE DEFAULT NULL COMMENT \'(DC2Type:date_immutable)\',
            ADD dpo_is_external TINYINT(1) DEFAULT 0 NOT NULL,
            ADD dpo_authority_notified_at DATE DEFAULT NULL COMMENT \'(DC2Type:date_immutable)\',
            ADD dpo_deputy_name VARCHAR(255) DEFAULT NULL');

        $this->addSql('ALTER TABLE data_subject_request
            ADD extension_notified_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tenant
            DROP dpo_appointment_date,
            DROP dpo_is_external,
            DROP dpo_authority_notified_at,
            DROP dpo_deputy_name');

        $this->addSql('ALTER TABLE data_subject_request DROP extension_notified_at');
    }
}
