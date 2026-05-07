<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260507194430 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tier-2 Operational Settings on Tenant: risk methodology, matrix size, wizard maturity target, notification preferences, CSIRT endpoints, on-call rotation';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE tenant
            ADD COLUMN risk_methodology VARCHAR(32) DEFAULT 'iso_27005' COMMENT 'iso_27005|nist_800_30|fair|custom',
            ADD COLUMN risk_matrix_size SMALLINT DEFAULT 5 COMMENT 'Risk matrix size 3, 4, 5',
            ADD COLUMN wizard_maturity_target VARCHAR(32) DEFAULT 'baseline' COMMENT 'baseline|enhanced',
            ADD COLUMN notification_preferences JSON DEFAULT NULL COMMENT 'Notification preferences keyed by event type',
            ADD COLUMN csirt_endpoints JSON DEFAULT NULL COMMENT 'CSIRT endpoints for automated incident reporting',
            ADD COLUMN crisis_team_on_call JSON DEFAULT NULL COMMENT 'Crisis team on-call rotation entries'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE tenant
            DROP COLUMN risk_methodology,
            DROP COLUMN risk_matrix_size,
            DROP COLUMN wizard_maturity_target,
            DROP COLUMN notification_preferences,
            DROP COLUMN csirt_endpoints,
            DROP COLUMN crisis_team_on_call");
    }
}
