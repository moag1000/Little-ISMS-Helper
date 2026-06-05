<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * F-NEU — curated ICT-provider library (DORA Art. 28).
 */
final class Version20260710100000_ict_provider_library extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'F-NEU: create ict_provider_library table (curated DORA ICT-provider catalogue)';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE ict_provider_library (
            id INT AUTO_INCREMENT NOT NULL,
            code VARCHAR(60) NOT NULL,
            name VARCHAR(200) NOT NULL,
            category VARCHAR(30) NOT NULL,
            headquarters_country VARCHAR(2) DEFAULT NULL,
            service_type LONGTEXT DEFAULT NULL,
            default_criticality VARCHAR(20) NOT NULL,
            eea_hosted TINYINT(1) DEFAULT 0 NOT NULL,
            UNIQUE INDEX uniq_ict_provider_code (code),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE ict_provider_library');
    }
}
