<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ISMS objective: optional KPI-catalog key and site scope, so the current
 * value is computed instead of maintained by hand.
 */
final class Version20261106100000_ObjectiveKpiLink extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ISMS objective: add kpi_key and kpi_location_id';
    }

    public function isTransactional(): bool
    {
        // ALTER TABLE commits implicitly in MySQL.
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ismsobjective ADD kpi_key VARCHAR(64) DEFAULT NULL, ADD kpi_location_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE ismsobjective ADD CONSTRAINT FK_C67C53BB35D616 FOREIGN KEY (kpi_location_id) REFERENCES location (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_C67C53BB35D616 ON ismsobjective (kpi_location_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ismsobjective DROP FOREIGN KEY FK_C67C53BB35D616');
        $this->addSql('DROP INDEX IDX_C67C53BB35D616 ON ismsobjective');
        $this->addSql('ALTER TABLE ismsobjective DROP kpi_key, DROP kpi_location_id');
    }
}
