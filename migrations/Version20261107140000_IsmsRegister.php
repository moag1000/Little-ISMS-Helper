<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Paket 9 — norm registers: one generic register entry (ISO 27001 4.1, 4.2 b,
 * 4.3 c, 5.3, 6.1.1, 7.2, 7.4) with deputies and generic links, plus the
 * top-level policy as a document reference on the ISMS context (5.2).
 */
final class Version20261107140000_IsmsRegister extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Norm registers (isms_register_entry, deputies, links) and ISMS context policy document';
    }

    public function isTransactional(): bool
    {
        // CREATE/ALTER TABLE commit implicitly in MySQL.
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE isms_register_entry (id INT AUTO_INCREMENT NOT NULL, tenant_id INT NOT NULL, owner_user_id INT DEFAULT NULL, owner_person_id INT DEFAULT NULL, register_type VARCHAR(40) NOT NULL, title VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, category VARCHAR(40) DEFAULT NULL, rating SMALLINT DEFAULT NULL, reference VARCHAR(255) DEFAULT NULL, frequency VARCHAR(20) DEFAULT NULL, effective_from DATE DEFAULT NULL, next_review_date DATE DEFAULT NULL, last_reviewed_at DATETIME DEFAULT NULL, archived_at DATETIME DEFAULT NULL, origin VARCHAR(120) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, INDEX IDX_2D5573B32B18554A (owner_user_id), INDEX IDX_2D5573B32300C8F4 (owner_person_id), INDEX idx_isms_register_tenant_type (tenant_id, register_type), INDEX idx_isms_register_review (next_review_date), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE isms_register_entry_deputy (entry_id INT NOT NULL, person_id INT NOT NULL, INDEX IDX_EF499116BA364942 (entry_id), INDEX IDX_EF499116217BBB47 (person_id), PRIMARY KEY (entry_id, person_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE isms_register_link (id INT AUTO_INCREMENT NOT NULL, entry_id INT NOT NULL, field VARCHAR(40) NOT NULL, ref_id INT NOT NULL, INDEX idx_isms_register_link_target (field, ref_id), UNIQUE INDEX uniq_isms_register_link (entry_id, field, ref_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE isms_register_entry ADD CONSTRAINT FK_2D5573B39033212A FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE isms_register_entry ADD CONSTRAINT FK_2D5573B32B18554A FOREIGN KEY (owner_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE isms_register_entry ADD CONSTRAINT FK_2D5573B32300C8F4 FOREIGN KEY (owner_person_id) REFERENCES person (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE isms_register_entry_deputy ADD CONSTRAINT FK_EF499116BA364942 FOREIGN KEY (entry_id) REFERENCES isms_register_entry (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE isms_register_entry_deputy ADD CONSTRAINT FK_EF499116217BBB47 FOREIGN KEY (person_id) REFERENCES person (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE isms_register_link ADD CONSTRAINT FK_F4F745B4BA364942 FOREIGN KEY (entry_id) REFERENCES isms_register_entry (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE ismscontext ADD isms_policy_document_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE ismscontext ADD CONSTRAINT FK_ADA28D4A77F75317 FOREIGN KEY (isms_policy_document_id) REFERENCES document (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_ADA28D4A77F75317 ON ismscontext (isms_policy_document_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ismscontext DROP FOREIGN KEY FK_ADA28D4A77F75317');
        $this->addSql('DROP INDEX IDX_ADA28D4A77F75317 ON ismscontext');
        $this->addSql('ALTER TABLE ismscontext DROP isms_policy_document_id');
        $this->addSql('DROP TABLE isms_register_link');
        $this->addSql('DROP TABLE isms_register_entry_deputy');
        $this->addSql('DROP TABLE isms_register_entry');
    }
}
