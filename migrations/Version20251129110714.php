<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251129110714 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE consent (id INT AUTO_INCREMENT NOT NULL, data_subject_identifier VARCHAR(255) NOT NULL, identifier_type VARCHAR(50) NOT NULL, purposes JSON DEFAULT NULL, granted_at DATETIME NOT NULL, consent_method VARCHAR(50) NOT NULL, consent_text LONGTEXT NOT NULL, consent_channel VARCHAR(100) DEFAULT NULL, proof_metadata JSON DEFAULT NULL, documented_at DATETIME NOT NULL, status VARCHAR(50) NOT NULL, is_verified_by_dpo TINYINT(1) NOT NULL, verified_at DATETIME DEFAULT NULL, is_revoked TINYINT(1) NOT NULL, revoked_at DATETIME DEFAULT NULL, revocation_method VARCHAR(50) DEFAULT NULL, expires_at DATETIME DEFAULT NULL, notes LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, tenant_id INT DEFAULT NULL, processing_activity_id INT NOT NULL, proof_document_id INT DEFAULT NULL, documented_by_id INT DEFAULT NULL, verified_by_id INT DEFAULT NULL, revocation_documented_by_id INT DEFAULT NULL, revocation_proof_document_id INT DEFAULT NULL, INDEX IDX_6312081071231D30 (proof_document_id), INDEX IDX_63120810C3EA33DA (documented_by_id), INDEX IDX_6312081069F4B775 (verified_by_id), INDEX IDX_63120810BE84BFD5 (revocation_documented_by_id), INDEX IDX_63120810E1E16E2D (revocation_proof_document_id), INDEX idx_consent_data_subject (data_subject_identifier), INDEX idx_consent_status (status), INDEX idx_consent_granted_at (granted_at), INDEX idx_consent_tenant (tenant_id), INDEX idx_consent_processing_activity (processing_activity_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE consent ADD CONSTRAINT FK_631208109033212A FOREIGN KEY (tenant_id) REFERENCES tenant (id)');
        $this->addSql('ALTER TABLE consent ADD CONSTRAINT FK_6312081072D4D63B FOREIGN KEY (processing_activity_id) REFERENCES processing_activity (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE consent ADD CONSTRAINT FK_6312081071231D30 FOREIGN KEY (proof_document_id) REFERENCES document (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE consent ADD CONSTRAINT FK_63120810C3EA33DA FOREIGN KEY (documented_by_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE consent ADD CONSTRAINT FK_6312081069F4B775 FOREIGN KEY (verified_by_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE consent ADD CONSTRAINT FK_63120810BE84BFD5 FOREIGN KEY (revocation_documented_by_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE consent ADD CONSTRAINT FK_63120810E1E16E2D FOREIGN KEY (revocation_proof_document_id) REFERENCES document (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE consent DROP FOREIGN KEY FK_631208109033212A');
        $this->addSql('ALTER TABLE consent DROP FOREIGN KEY FK_6312081072D4D63B');
        $this->addSql('ALTER TABLE consent DROP FOREIGN KEY FK_6312081071231D30');
        $this->addSql('ALTER TABLE consent DROP FOREIGN KEY FK_63120810C3EA33DA');
        $this->addSql('ALTER TABLE consent DROP FOREIGN KEY FK_6312081069F4B775');
        $this->addSql('ALTER TABLE consent DROP FOREIGN KEY FK_63120810BE84BFD5');
        $this->addSql('ALTER TABLE consent DROP FOREIGN KEY FK_63120810E1E16E2D');
        $this->addSql('DROP TABLE consent');
    }
}
