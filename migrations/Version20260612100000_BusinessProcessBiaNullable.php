<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Junior-ISB-Audit T4.2 — BIA Save-as-Draft.
 *
 * Makes the 3 BIA-impact columns nullable so juniors can save a business
 * process incrementally without rating reputational/regulatory/operational
 * impact upfront. Existing rows already have integer values (NotBlank was
 * enforced at form-level only); the DDL change widens the column without
 * data loss.
 *
 * ISO 22301 Cl. 8.2.2 still expects all three dimensions to be rated before
 * the BIA is formally signed off — surfaced via the show-page progress
 * indicator + criticality-alignment validator, not as a hard form gate.
 */
final class Version20260612100000_BusinessProcessBiaNullable extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'T4.2 — Allow null on BIA impact columns (reputational/regulatory/operational) for Save-as-Draft.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE business_process MODIFY reputational_impact INT NULL');
        $this->addSql('ALTER TABLE business_process MODIFY regulatory_impact INT NULL');
        $this->addSql('ALTER TABLE business_process MODIFY operational_impact INT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE business_process SET reputational_impact = 1 WHERE reputational_impact IS NULL");
        $this->addSql("UPDATE business_process SET regulatory_impact = 1 WHERE regulatory_impact IS NULL");
        $this->addSql("UPDATE business_process SET operational_impact = 1 WHERE operational_impact IS NULL");
        $this->addSql('ALTER TABLE business_process MODIFY reputational_impact INT NOT NULL');
        $this->addSql('ALTER TABLE business_process MODIFY regulatory_impact INT NOT NULL');
        $this->addSql('ALTER TABLE business_process MODIFY operational_impact INT NOT NULL');
    }
}
