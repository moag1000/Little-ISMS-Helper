<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251119203730 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE risk DROP FOREIGN KEY `FK_7906D5415DA1941`');
        $this->addSql('ALTER TABLE risk ADD person_id INT DEFAULT NULL, ADD location_id INT DEFAULT NULL, ADD supplier_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE risk ADD CONSTRAINT FK_7906D5415DA1941 FOREIGN KEY (asset_id) REFERENCES asset (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE risk ADD CONSTRAINT FK_7906D541217BBB47 FOREIGN KEY (person_id) REFERENCES person (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE risk ADD CONSTRAINT FK_7906D54164D218E FOREIGN KEY (location_id) REFERENCES location (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE risk ADD CONSTRAINT FK_7906D5412ADD6D8C FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_7906D541217BBB47 ON risk (person_id)');
        $this->addSql('CREATE INDEX IDX_7906D54164D218E ON risk (location_id)');
        $this->addSql('CREATE INDEX IDX_7906D5412ADD6D8C ON risk (supplier_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE risk DROP FOREIGN KEY FK_7906D5415DA1941');
        $this->addSql('ALTER TABLE risk DROP FOREIGN KEY FK_7906D541217BBB47');
        $this->addSql('ALTER TABLE risk DROP FOREIGN KEY FK_7906D54164D218E');
        $this->addSql('ALTER TABLE risk DROP FOREIGN KEY FK_7906D5412ADD6D8C');
        $this->addSql('DROP INDEX IDX_7906D541217BBB47 ON risk');
        $this->addSql('DROP INDEX IDX_7906D54164D218E ON risk');
        $this->addSql('DROP INDEX IDX_7906D5412ADD6D8C ON risk');
        $this->addSql('ALTER TABLE risk DROP person_id, DROP location_id, DROP supplier_id');
        $this->addSql('ALTER TABLE risk ADD CONSTRAINT `FK_7906D5415DA1941` FOREIGN KEY (asset_id) REFERENCES asset (id)');
    }
}
