<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * W3b — VVT completeness:
 *  - M-5 (Art. 30(1)(a) / Art. 27): controller postal address + representative
 *    fields on tenant.
 *  - M-7 (Art. 44–49): third-country transfer + safeguard fields on supplier,
 *    so the SCC/adequacy status lives where the (sub-)processor sits.
 */
final class Version20260704120000_ControllerAddressAndSupplierTransfer extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add controller postal address + Art. 27 representative (tenant) and third-country transfer fields (supplier)';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tenant
            ADD address_street VARCHAR(255) DEFAULT NULL,
            ADD address_postal_code VARCHAR(20) DEFAULT NULL,
            ADD address_city VARCHAR(120) DEFAULT NULL,
            ADD address_country VARCHAR(2) DEFAULT NULL,
            ADD representative_name VARCHAR(255) DEFAULT NULL,
            ADD representative_contact VARCHAR(255) DEFAULT NULL');

        $this->addSql('ALTER TABLE supplier
            ADD third_country_transfer TINYINT(1) DEFAULT 0 NOT NULL,
            ADD transfer_safeguards VARCHAR(50) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tenant
            DROP address_street,
            DROP address_postal_code,
            DROP address_city,
            DROP address_country,
            DROP representative_name,
            DROP representative_contact');

        $this->addSql('ALTER TABLE supplier
            DROP third_country_transfer,
            DROP transfer_safeguards');
    }
}
