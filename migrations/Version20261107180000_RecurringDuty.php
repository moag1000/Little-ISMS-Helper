<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Paket 6 (Pflichtenkalender): recurring duties of the norms without a date
 * field on a record. Rows are seeded by the application per organisation when
 * the duty's module is active (RecurringDutyService), not by this migration.
 */
final class Version20261107180000_RecurringDuty extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Recurring duties (duty calendar) per organisation';
    }

    public function isTransactional(): bool
    {
        // CREATE TABLE commits implicitly in MySQL.
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE IF NOT EXISTS recurring_duty ('
            . ' id INT AUTO_INCREMENT NOT NULL,'
            . ' tenant_id INT NOT NULL,'
            . ' owner_id INT DEFAULT NULL,'
            . ' last_done_by_id INT DEFAULT NULL,'
            . ' last_evidence_id INT DEFAULT NULL,'
            . ' duty_key VARCHAR(64) NOT NULL,'
            . ' interval_months INT NOT NULL,'
            . ' next_due_at DATE NOT NULL,'
            . ' last_done_at DATE DEFAULT NULL,'
            . ' last_note LONGTEXT DEFAULT NULL,'
            . ' created_at DATETIME NOT NULL,'
            . ' INDEX IDX_A5DCD887E3C61F9 (owner_id),'
            . ' INDEX IDX_A5DCD88DCDA5413 (last_done_by_id),'
            . ' INDEX IDX_A5DCD88F51E831D (last_evidence_id),'
            . ' UNIQUE INDEX uniq_recurring_duty_tenant_key (tenant_id, duty_key),'
            . ' PRIMARY KEY(id),'
            . ' CONSTRAINT FK_A5DCD889033212A FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE,'
            . ' CONSTRAINT FK_A5DCD887E3C61F9 FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE SET NULL,'
            . ' CONSTRAINT FK_A5DCD88DCDA5413 FOREIGN KEY (last_done_by_id) REFERENCES users (id) ON DELETE SET NULL,'
            . ' CONSTRAINT FK_A5DCD88F51E831D FOREIGN KEY (last_evidence_id) REFERENCES document (id) ON DELETE SET NULL'
            . ') DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS recurring_duty');
    }
}
