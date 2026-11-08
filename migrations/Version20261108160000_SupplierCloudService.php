<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * W5 norm rest: ISO 27001 A.5.23 (information security for use of cloud
 * services). A supplier marked as cloud service is checked for an
 * assessment, agreed security requirements and an exit strategy
 * (record_completeness check of the compliance wizard).
 */
final class Version20261108160000_SupplierCloudService extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Supplier: cloud service flag (ISO 27001 A.5.23)';
    }

    public function isTransactional(): bool
    {
        // ALTER TABLE commits implicitly in MySQL.
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE supplier ADD is_cloud_service TINYINT(1) DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE supplier DROP is_cloud_service');
    }
}
