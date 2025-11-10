<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251105000006 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create document table for file management';
    }

    public function up(Schema $schema): void
    {
        // Document table
        $this->addSql('CREATE TABLE document (
            id INT AUTO_INCREMENT NOT NULL,
            uploaded_by_id INT NOT NULL,
            filename VARCHAR(255) NOT NULL,
            original_filename VARCHAR(255) NOT NULL,
            mime_type VARCHAR(100) NOT NULL,
            file_size INT NOT NULL,
            file_path VARCHAR(500) NOT NULL,
            category VARCHAR(100) NOT NULL,
            description LONGTEXT DEFAULT NULL,
            entity_type VARCHAR(100) DEFAULT NULL,
            entity_id INT DEFAULT NULL,
            uploaded_at DATETIME NOT NULL,
            updated_at DATETIME DEFAULT NULL,
            sha256_hash VARCHAR(64) DEFAULT NULL,
            is_public TINYINT(1) NOT NULL DEFAULT 0,
            is_archived TINYINT(1) NOT NULL DEFAULT 0,
            INDEX IDX_DOCUMENT_UPLOADED_BY (uploaded_by_id),
            INDEX IDX_DOCUMENT_ENTITY (entity_type, entity_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Foreign key to users
        $this->addSql('ALTER TABLE document
            ADD CONSTRAINT FK_DOCUMENT_USER
            FOREIGN KEY (uploaded_by_id) REFERENCES users (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE document DROP FOREIGN KEY FK_DOCUMENT_USER');
        $this->addSql('DROP TABLE document');
    }
}
