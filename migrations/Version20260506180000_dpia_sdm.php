<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * SDM 3.1 (Standard-Datenschutzmodell, DSK) auf DPIA aufsetzen.
 * Speichert die Bewertung der sieben Gewährleistungsziele neben dem
 * GDPR-Art.-35-Narrativ.
 */
final class Version20260506180000_dpia_sdm extends AbstractMigration
{
    /**
     * DDL migration — MySQL implicitly commits ALTER/CREATE/DROP which
     * invalidates Doctrine's per-migration SAVEPOINT (CLAUDE.md Pitfall #6).
     */
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Add SDM 3.1 protection-goal assessment fields to data_protection_impact_assessment.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE data_protection_impact_assessment
                ADD sdm_assessment JSON DEFAULT NULL,
                ADD sdm_assessment_summary LONGTEXT DEFAULT NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE data_protection_impact_assessment
                DROP sdm_assessment,
                DROP sdm_assessment_summary
        SQL);
    }
}
