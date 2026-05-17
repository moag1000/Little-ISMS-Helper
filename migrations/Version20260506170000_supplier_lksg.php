<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * LkSG-Felder auf Supplier ergänzen
 * (Lieferkettensorgfaltspflichtengesetz, Pflicht für Tenants ab 1000 MA;
 * Ausweitung auf 250+ MA absehbar).
 */
final class Version20260506170000_supplier_lksg extends AbstractMigration
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
        return 'Add LkSG due-diligence fields to supplier (human rights / environmental risk).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE supplier
                ADD lksg_human_rights_risk_score INT DEFAULT NULL,
                ADD lksg_environmental_risk_score INT DEFAULT NULL,
                ADD lksg_risk_category VARCHAR(20) DEFAULT NULL,
                ADD lksg_risk_analysis_date DATE DEFAULT NULL,
                ADD lksg_complaint_mechanism LONGTEXT DEFAULT NULL,
                ADD lksg_prevention_measures LONGTEXT DEFAULT NULL,
                ADD lksg_reporting_obligation TINYINT(1) NOT NULL DEFAULT 0
        SQL);
        $this->addSql('CREATE INDEX idx_supplier_lksg_category ON supplier (lksg_risk_category)');
        $this->addSql('CREATE INDEX idx_supplier_lksg_obligation ON supplier (lksg_reporting_obligation)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_supplier_lksg_category ON supplier');
        $this->addSql('DROP INDEX idx_supplier_lksg_obligation ON supplier');
        $this->addSql(<<<'SQL'
            ALTER TABLE supplier
                DROP lksg_human_rights_risk_score,
                DROP lksg_environmental_risk_score,
                DROP lksg_risk_category,
                DROP lksg_risk_analysis_date,
                DROP lksg_complaint_mechanism,
                DROP lksg_prevention_measures,
                DROP lksg_reporting_obligation
        SQL);
    }
}
