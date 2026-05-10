<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * V3 W2-FV-5 — Risk audit-trail for Incident-driven reassessment.
 *
 * Adds:
 *  - `last_incident_reassessment_at` (DATETIME, nullable)
 *  - `last_incident_reassessment_incident_id` (INT, nullable, FK Incident)
 *
 * Plain ALTER (CLAUDE.md pitfall #6). isTransactional=false.
 */
final class Version20260512210000_m8_risk_incident_reassessment extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'V3 W2-FV-5: Risk last_incident_reassessment_at / _incident_id audit-trail.';
    }

    public function up(Schema $schema): void
    {
        if (!$this->columnExists('risk', 'last_incident_reassessment_at')) {
            $this->addSql('ALTER TABLE risk ADD last_incident_reassessment_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        }
        if (!$this->columnExists('risk', 'last_incident_reassessment_incident_id')) {
            $this->addSql('ALTER TABLE risk ADD last_incident_reassessment_incident_id INT DEFAULT NULL');
            // FK is best-effort: if Incident table not present in some test fixture path, skip.
            $hasFk = $this->connection->fetchOne(
                'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS '
                . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t '
                . 'AND CONSTRAINT_TYPE = \'FOREIGN KEY\' AND CONSTRAINT_NAME = :c',
                ['t' => 'risk', 'c' => 'fk_risk_last_incident_reassess'],
            );
            if ((int) $hasFk === 0) {
                $this->addSql(
                    'ALTER TABLE risk ADD CONSTRAINT fk_risk_last_incident_reassess '
                    . 'FOREIGN KEY (last_incident_reassessment_incident_id) '
                    . 'REFERENCES incident (id) ON DELETE SET NULL'
                );
            }
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE risk DROP FOREIGN KEY fk_risk_last_incident_reassess');
        if ($this->columnExists('risk', 'last_incident_reassessment_incident_id')) {
            $this->addSql('ALTER TABLE risk DROP COLUMN last_incident_reassessment_incident_id');
        }
        if ($this->columnExists('risk', 'last_incident_reassessment_at')) {
            $this->addSql('ALTER TABLE risk DROP COLUMN last_incident_reassessment_at');
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        $count = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c',
            ['t' => $table, 'c' => $column],
        );
        return (int) $count > 0;
    }
}
