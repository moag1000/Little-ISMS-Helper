<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * GDPR Art. 30(2) (N-7): processor-role record — flag + identity of the
 * controller(s) on whose behalf the tenant processes (Art. 30(2)(a)).
 */
final class Version20260704150000_ProcessorRoleRecord extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add processor-role fields to processing_activity (GDPR Art. 30(2))';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE processing_activity
            ADD is_processor TINYINT(1) DEFAULT 0 NOT NULL,
            ADD processor_client_controller LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE processing_activity
            DROP is_processor,
            DROP processor_client_controller');
    }
}
