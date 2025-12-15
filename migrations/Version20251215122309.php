<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251215122309 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE scheduled_report (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, report_type VARCHAR(50) NOT NULL, schedule VARCHAR(20) NOT NULL, format VARCHAR(10) NOT NULL, recipients JSON NOT NULL, description LONGTEXT DEFAULT NULL, is_active TINYINT NOT NULL, last_run_at DATETIME DEFAULT NULL, next_run_at DATETIME DEFAULT NULL, last_run_status INT DEFAULT NULL, last_run_message LONGTEXT DEFAULT NULL, preferred_time TIME DEFAULT NULL, day_of_week INT DEFAULT NULL, day_of_month INT DEFAULT NULL, locale VARCHAR(10) DEFAULT NULL, tenant_id INT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, created_by_id INT DEFAULT NULL, INDEX IDX_68D1F39DB03A8386 (created_by_id), INDEX idx_scheduled_report_tenant (tenant_id), INDEX idx_scheduled_report_active (is_active), INDEX idx_scheduled_report_next_run (next_run_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE scheduled_report ADD CONSTRAINT FK_68D1F39DB03A8386 FOREIGN KEY (created_by_id) REFERENCES users (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE scheduled_report DROP FOREIGN KEY FK_68D1F39DB03A8386');
        $this->addSql('DROP TABLE scheduled_report');
    }
}
