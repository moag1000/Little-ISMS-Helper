<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Compliance-Wizard — manual-check confirmations.
 *
 * Backs the residual genuinely-manual wizard checks (clauses with no entity to
 * auto-detect from, e.g. ISO 27001 Cl. 5.1 leadership commitment). A row marks
 * one (tenant, wizard, check) as addressed so the wizard stops reporting it as a
 * permanent critical gap. Entity-backed checks never touch this table.
 */
final class Version20260704100000_WizardManualConfirmation extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create wizard_manual_confirmation table for compliance-wizard manual-check sign-off';
    }

    public function isTransactional(): bool
    {
        // DDL (CREATE TABLE) commits implicitly under MySQL — keep out of the
        // per-migration SAVEPOINT so a multi-migration run does not break.
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS wizard_manual_confirmation (
                id INT AUTO_INCREMENT NOT NULL,
                tenant_id INT NOT NULL,
                confirmed_by_id INT DEFAULT NULL,
                wizard_key VARCHAR(64) NOT NULL,
                check_key VARCHAR(128) NOT NULL,
                confirmed TINYINT(1) DEFAULT 1 NOT NULL,
                confirmed_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                note LONGTEXT DEFAULT NULL,
                INDEX idx_wizard_manual_tenant (tenant_id),
                INDEX IDX_wizard_manual_confirmed_by (confirmed_by_id),
                UNIQUE INDEX uniq_wizard_manual_check (tenant_id, wizard_key, check_key),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE wizard_manual_confirmation
                ADD CONSTRAINT FK_wizard_manual_tenant FOREIGN KEY (tenant_id)
                REFERENCES tenant (id) ON DELETE CASCADE
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE wizard_manual_confirmation
                ADD CONSTRAINT FK_wizard_manual_confirmed_by FOREIGN KEY (confirmed_by_id)
                REFERENCES `user` (id) ON DELETE SET NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS wizard_manual_confirmation');
    }
}
