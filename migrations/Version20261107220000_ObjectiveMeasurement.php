<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Fix-Paket P8 (ISO 27001 6.2 / 9.1): who measures an objective becomes a
 * Person, and the date of the last measurement is kept so that the next one
 * is due one cadence later (deadline source per objective).
 *
 * Existing free texts are matched to a person of the same organisation with
 * exactly that name; the text itself stays (shown as legacy until cleared).
 */
final class Version20261107220000_ObjectiveMeasurement extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ISMS objective: measurement person and last measured date';
    }

    public function isTransactional(): bool
    {
        // ALTER TABLE commits implicitly in MySQL.
        return false;
    }

    public function up(Schema $schema): void
    {
        // Re-runnable: the first release of this migration stopped after the
        // DDL on databases with objectives (issue #1171), so the columns, the
        // key and the index may already exist when it is applied again.
        $table = $schema->getTable('ismsobjective');
        if (!$table->hasColumn('measurement_person_id')) {
            $this->addSql('ALTER TABLE ismsobjective ADD measurement_person_id INT DEFAULT NULL');
        }
        if (!$table->hasColumn('last_measured_at')) {
            $this->addSql('ALTER TABLE ismsobjective ADD last_measured_at DATE DEFAULT NULL');
        }
        if (!$table->hasForeignKey('FK_C67C53B80996524')) {
            $this->addSql('ALTER TABLE ismsobjective ADD CONSTRAINT FK_C67C53B80996524 FOREIGN KEY (measurement_person_id) REFERENCES person (id) ON DELETE SET NULL');
        }
        if (!$table->hasIndex('IDX_C67C53B80996524')) {
            $this->addSql('CREATE INDEX IDX_C67C53B80996524 ON ismsobjective (measurement_person_id)');
        }

        // Free text → person of the same organisation, only on an unambiguous name match.
        $this->addSql(
            'UPDATE ismsobjective o'
            . ' JOIN (SELECT tenant_id, full_name, MIN(id) AS person_id FROM person'
            . '       WHERE full_name IS NOT NULL GROUP BY tenant_id, full_name HAVING COUNT(*) = 1) p'
            . '   ON p.tenant_id = o.tenant_id AND p.full_name = TRIM(o.responsible_for_measurement)'
            . ' SET o.measurement_person_id = p.person_id'
            . ' WHERE o.measurement_person_id IS NULL AND o.responsible_for_measurement IS NOT NULL'
        );
        // Objectives with a value count as measured at their last change.
        // current_value is DECIMAL: comparing it with '' makes strict mode
        // fail the UPDATE as soon as a row exists (1292, issue #1171).
        $this->addSql('UPDATE ismsobjective SET last_measured_at = DATE(COALESCE(updated_at, created_at)) WHERE current_value IS NOT NULL AND last_measured_at IS NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ismsobjective DROP FOREIGN KEY FK_C67C53B80996524');
        $this->addSql('DROP INDEX IDX_C67C53B80996524 ON ismsobjective');
        $this->addSql('ALTER TABLE ismsobjective DROP measurement_person_id, DROP last_measured_at');
    }
}
