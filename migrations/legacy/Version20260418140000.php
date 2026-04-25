<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * AUD-02: Audit-Log integrity (HMAC chain) — NIS2 Art. 21.2 tamper-detection.
 */
final class Version20260418140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'AUD-02: audit_log.hmac + previous_hmac (HMAC chain)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE audit_log
            ADD hmac VARCHAR(64) DEFAULT NULL,
            ADD previous_hmac VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE audit_log
            DROP hmac,
            DROP previous_hmac');
    }
}
