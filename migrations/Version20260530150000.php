<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Rename organization_security_profile.`values` -> parameter_values.
 * `values` is a MariaDB/MySQL reserved word that Doctrine does not reliably
 * quote in hydration SQL, breaking reads of OrganizationSecurityProfile.
 */
final class Version20260530150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename organization_security_profile.values to parameter_values (reserved word)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE organization_security_profile CHANGE `values` parameter_values JSON NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE organization_security_profile CHANGE parameter_values `values` JSON NOT NULL');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
