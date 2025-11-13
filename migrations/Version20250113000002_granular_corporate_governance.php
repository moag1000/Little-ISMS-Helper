<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Refactor corporate governance to be granular (per control/scope)
 * instead of global per tenant
 */
final class Version20250113000002_granular_corporate_governance extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create corporate_governance table for granular governance and migrate existing data';
    }

    public function up(Schema $schema): void
    {
        // Create new corporate_governance table
        $this->addSql('CREATE TABLE corporate_governance (
            id INT AUTO_INCREMENT NOT NULL,
            tenant_id INT NOT NULL,
            parent_id INT NOT NULL,
            scope VARCHAR(50) NOT NULL,
            scope_id VARCHAR(100) DEFAULT NULL,
            governance_model VARCHAR(20) NOT NULL,
            notes LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            created_by_id INT DEFAULT NULL,
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Add indices
        $this->addSql('CREATE INDEX IDX_9815E5739033212A ON corporate_governance (tenant_id)');
        $this->addSql('CREATE INDEX IDX_9815E573727ACA70 ON corporate_governance (parent_id)');
        $this->addSql('CREATE INDEX IDX_9815E573B03A8386 ON corporate_governance (created_by_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_tenant_scope ON corporate_governance (tenant_id, scope, scope_id)');

        // Add foreign key constraints
        $this->addSql('ALTER TABLE corporate_governance ADD CONSTRAINT FK_9815E5739033212A FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE corporate_governance ADD CONSTRAINT FK_9815E573727ACA70 FOREIGN KEY (parent_id) REFERENCES tenant (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE corporate_governance ADD CONSTRAINT FK_9815E573B03A8386 FOREIGN KEY (created_by_id) REFERENCES user (id) ON DELETE SET NULL');

        // Migrate existing governance_model data from tenant to corporate_governance
        // If a tenant has both parent and governance_model set, create a default governance rule
        $this->addSql('
            INSERT INTO corporate_governance (tenant_id, parent_id, scope, scope_id, governance_model, created_at)
            SELECT
                t.id as tenant_id,
                t.parent_id as parent_id,
                \'default\' as scope,
                NULL as scope_id,
                t.governance_model,
                NOW() as created_at
            FROM tenant t
            WHERE t.parent_id IS NOT NULL
              AND t.governance_model IS NOT NULL
        ');

        // Remove governance_model column from tenant table (no longer needed)
        $this->addSql('ALTER TABLE tenant DROP COLUMN governance_model');
    }

    public function down(Schema $schema): void
    {
        // Add governance_model back to tenant
        $this->addSql('ALTER TABLE tenant ADD governance_model VARCHAR(20) DEFAULT NULL');

        // Migrate default governance rules back to tenant (best effort)
        $this->addSql('
            UPDATE tenant t
            INNER JOIN corporate_governance cg ON cg.tenant_id = t.id
            SET t.governance_model = cg.governance_model
            WHERE cg.scope = \'default\'
              AND cg.scope_id IS NULL
        ');

        // Drop corporate_governance table
        $this->addSql('ALTER TABLE corporate_governance DROP FOREIGN KEY FK_9815E5739033212A');
        $this->addSql('ALTER TABLE corporate_governance DROP FOREIGN KEY FK_9815E573727ACA70');
        $this->addSql('ALTER TABLE corporate_governance DROP FOREIGN KEY FK_9815E573B03A8386');
        $this->addSql('DROP TABLE corporate_governance');
    }
}
