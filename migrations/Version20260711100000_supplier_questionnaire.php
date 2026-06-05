<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * F23 — outbound supplier security questionnaire.
 */
final class Version20260711100000_supplier_questionnaire extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'F23: create supplier_questionnaires table (outbound supplier questionnaire)';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE supplier_questionnaires (
            id INT AUTO_INCREMENT NOT NULL,
            tenant_id INT NOT NULL,
            supplier_id INT NOT NULL,
            title VARCHAR(200) NOT NULL,
            public_token VARCHAR(64) NOT NULL,
            status VARCHAR(20) NOT NULL,
            questions JSON NOT NULL,
            answers JSON NOT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            sent_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            completed_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            UNIQUE INDEX UNIQ_supplier_questionnaire_token (public_token),
            INDEX IDX_sq_tenant (tenant_id),
            INDEX IDX_sq_supplier (supplier_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE supplier_questionnaires ADD CONSTRAINT FK_sq_tenant FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE supplier_questionnaires ADD CONSTRAINT FK_sq_supplier FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE supplier_questionnaires DROP FOREIGN KEY FK_sq_tenant');
        $this->addSql('ALTER TABLE supplier_questionnaires DROP FOREIGN KEY FK_sq_supplier');
        $this->addSql('DROP TABLE supplier_questionnaires');
    }
}
