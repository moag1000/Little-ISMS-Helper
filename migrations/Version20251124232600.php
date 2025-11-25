<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251124232600 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE management_review_user (management_review_id INT NOT NULL, user_id INT NOT NULL, INDEX IDX_D90BF5F6A211E8FF (management_review_id), INDEX IDX_D90BF5F6A76ED395 (user_id), PRIMARY KEY (management_review_id, user_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE management_review_user ADD CONSTRAINT FK_D90BF5F6A211E8FF FOREIGN KEY (management_review_id) REFERENCES management_review (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE management_review_user ADD CONSTRAINT FK_D90BF5F6A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE management_review ADD incidents_review LONGTEXT DEFAULT NULL, ADD risks_review LONGTEXT DEFAULT NULL, ADD objectives_review LONGTEXT DEFAULT NULL, ADD context_changes LONGTEXT DEFAULT NULL, ADD summary LONGTEXT DEFAULT NULL, ADD improvement_opportunities LONGTEXT DEFAULT NULL, ADD resources_needed LONGTEXT DEFAULT NULL, ADD reviewed_by_id INT DEFAULT NULL, CHANGE participants nonconformities_review LONGTEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE management_review ADD CONSTRAINT FK_4F5A850CFC6B21F1 FOREIGN KEY (reviewed_by_id) REFERENCES users (id)');
        $this->addSql('CREATE INDEX IDX_4F5A850CFC6B21F1 ON management_review (reviewed_by_id)');
        $this->addSql('ALTER TABLE training ADD delivery_method VARCHAR(50) DEFAULT NULL, ADD mandatory TINYINT(1) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE management_review_user DROP FOREIGN KEY FK_D90BF5F6A211E8FF');
        $this->addSql('ALTER TABLE management_review_user DROP FOREIGN KEY FK_D90BF5F6A76ED395');
        $this->addSql('DROP TABLE management_review_user');
        $this->addSql('ALTER TABLE management_review DROP FOREIGN KEY FK_4F5A850CFC6B21F1');
        $this->addSql('DROP INDEX IDX_4F5A850CFC6B21F1 ON management_review');
        $this->addSql('ALTER TABLE management_review ADD participants LONGTEXT DEFAULT NULL, DROP nonconformities_review, DROP incidents_review, DROP risks_review, DROP objectives_review, DROP context_changes, DROP summary, DROP improvement_opportunities, DROP resources_needed, DROP reviewed_by_id');
        $this->addSql('ALTER TABLE training DROP delivery_method, DROP mandatory');
    }
}
