<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251129102628 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add scheduled_task table for Symfony Scheduler support';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE scheduled_task (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(100) NOT NULL, description LONGTEXT DEFAULT NULL, cron_expression VARCHAR(100) NOT NULL, command VARCHAR(255) NOT NULL, arguments JSON DEFAULT NULL, enabled TINYINT(1) NOT NULL, last_run_at DATETIME DEFAULT NULL, next_run_at DATETIME DEFAULT NULL, last_output LONGTEXT DEFAULT NULL, last_status VARCHAR(20) DEFAULT NULL, tenant_id INT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE scheduled_task');
    }
}
