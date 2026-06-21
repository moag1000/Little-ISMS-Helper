<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Widen compliance_mapping.provenance_source from VARCHAR(255) to TEXT/LONGTEXT.
 *
 * Some authored library mappings (e.g. nisg-at) carry a provenance value
 * longer than 255 chars, which triggered an SQL 22001 truncation error on
 * `app:mapping:library:import`. TEXT removes the width ceiling.
 */
final class Version20260621121332 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Widen compliance_mapping.provenance_source VARCHAR(255) -> LONGTEXT to avoid import truncation';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compliance_mapping CHANGE provenance_source provenance_source LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compliance_mapping CHANGE provenance_source provenance_source VARCHAR(255) DEFAULT NULL');
    }
}
