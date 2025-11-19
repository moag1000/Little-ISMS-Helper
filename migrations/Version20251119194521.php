<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251119194521 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE risk ADD involves_personal_data TINYINT(1) DEFAULT 0 NOT NULL, ADD involves_special_category_data TINYINT(1) DEFAULT 0 NOT NULL, ADD legal_basis VARCHAR(50) DEFAULT NULL, ADD processing_scale VARCHAR(50) DEFAULT NULL, ADD requires_dpia TINYINT(1) DEFAULT 0 NOT NULL, ADD data_subject_impact LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE risk DROP involves_personal_data, DROP involves_special_category_data, DROP legal_basis, DROP processing_scale, DROP requires_dpia, DROP data_subject_impact');
    }
}
