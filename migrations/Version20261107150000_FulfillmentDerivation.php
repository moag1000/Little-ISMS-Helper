<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Paket 3 — fulfilment is a rule over the organisation's data.
 *
 * ComplianceRequirementFulfillment keeps fulfillment_percentage as the effective
 * value; the rule result (derived_*) sits next to it and a differing hand value
 * becomes an explicit override (override_*). Existing hand values are kept: the
 * first derivation run (nightly scheduler task or "Neu berechnen") turns every
 * value that differs from the rule into an override with source "legacy", listed
 * under Compliance > Übersteuerungen prüfen.
 */
final class Version20261107150000_FulfillmentDerivation extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fulfillment: derived value, derivation basis and explicit override columns';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE compliance_requirement_fulfillment
                ADD derived_percentage INT DEFAULT NULL,
                ADD derivation_kind VARCHAR(20) DEFAULT NULL,
                ADD derivation_basis JSON DEFAULT NULL,
                ADD derived_at DATETIME DEFAULT NULL,
                ADD override_source VARCHAR(20) DEFAULT NULL,
                ADD override_reason LONGTEXT DEFAULT NULL,
                ADD override_review_due DATE DEFAULT NULL,
                ADD overridden_at DATETIME DEFAULT NULL,
                ADD overridden_by_id INT DEFAULT NULL
            SQL);
        $this->addSql('ALTER TABLE compliance_requirement_fulfillment ADD CONSTRAINT FK_308AE2424594B015 FOREIGN KEY (overridden_by_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_308AE2424594B015 ON compliance_requirement_fulfillment (overridden_by_id)');
        $this->addSql('CREATE INDEX idx_crf_override ON compliance_requirement_fulfillment (tenant_id, override_source)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compliance_requirement_fulfillment DROP FOREIGN KEY FK_308AE2424594B015');
        $this->addSql('DROP INDEX IDX_308AE2424594B015 ON compliance_requirement_fulfillment');
        $this->addSql('DROP INDEX idx_crf_override ON compliance_requirement_fulfillment');
        $this->addSql(<<<'SQL'
            ALTER TABLE compliance_requirement_fulfillment
                DROP derived_percentage, DROP derivation_kind, DROP derivation_basis, DROP derived_at,
                DROP override_source, DROP override_reason, DROP override_review_due,
                DROP overridden_at, DROP overridden_by_id
            SQL);
    }
}
