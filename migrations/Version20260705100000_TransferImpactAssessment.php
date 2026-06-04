<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Transfer Impact Assessment (TIA) — GDPR Art. 46/49 + Schrems-II.
 *
 * Creates the transfer_impact_assessment table.  A TIA evaluates whether a
 * third-country recipient can offer essentially equivalent data protection
 * despite divergent national surveillance law (CJEU C-311/18, Schrems-II).
 *
 * Design notes:
 *  - ManyToOne to processing_activity (SET NULL on delete — GDPR accountability)
 *  - ManyToOne to tenant (CASCADE on delete)
 *  - assessed_by FK to user (SET NULL on delete)
 *  - lock_version column for Doctrine optimistic locking (Lifecycle X.1)
 *  - Plain CREATE TABLE / ALTER TABLE — NO PREPARE/EXECUTE pattern (see CLAUDE.md §6)
 */
final class Version20260705100000_TransferImpactAssessment extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create transfer_impact_assessment table for GDPR Art. 46/49 Schrems-II TIA';
    }

    public function isTransactional(): bool
    {
        // DDL (CREATE TABLE / ALTER TABLE) commits implicitly under MySQL.
        // Keep outside the per-migration SAVEPOINT to avoid SAVEPOINT-does-not-exist
        // errors when multiple migrations run in a single migrate call.
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS transfer_impact_assessment (
                id                   INT AUTO_INCREMENT NOT NULL,
                tenant_id            INT NOT NULL,
                processing_activity_id INT DEFAULT NULL,
                assessed_by_id       INT DEFAULT NULL,
                created_by_id        INT DEFAULT NULL,
                updated_by_id        INT DEFAULT NULL,

                destination_country  VARCHAR(10)  NOT NULL,
                recipient_name       VARCHAR(255) NOT NULL,
                transfer_mechanism   VARCHAR(30)  NOT NULL,

                law_surveillance_risk LONGTEXT NOT NULL,
                supplementary_measures LONGTEXT DEFAULT NULL,
                residual_risk_rating VARCHAR(10) NOT NULL,
                conclusion           LONGTEXT DEFAULT NULL,

                status               VARCHAR(20) NOT NULL DEFAULT 'draft',
                assessed_at          DATE DEFAULT NULL,
                lock_version         INT NOT NULL DEFAULT 0,

                created_at           DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at           DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',

                INDEX idx_tia_tenant          (tenant_id),
                INDEX idx_tia_status          (status),
                INDEX idx_tia_residual_risk   (residual_risk_rating),
                INDEX IDX_tia_processing_activity (processing_activity_id),
                INDEX IDX_tia_assessed_by     (assessed_by_id),

                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE transfer_impact_assessment
                ADD CONSTRAINT FK_tia_tenant FOREIGN KEY (tenant_id)
                REFERENCES tenant (id) ON DELETE CASCADE
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE transfer_impact_assessment
                ADD CONSTRAINT FK_tia_processing_activity FOREIGN KEY (processing_activity_id)
                REFERENCES processing_activity (id) ON DELETE SET NULL
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE transfer_impact_assessment
                ADD CONSTRAINT FK_tia_assessed_by FOREIGN KEY (assessed_by_id)
                REFERENCES `user` (id) ON DELETE SET NULL
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE transfer_impact_assessment
                ADD CONSTRAINT FK_tia_created_by FOREIGN KEY (created_by_id)
                REFERENCES `user` (id) ON DELETE SET NULL
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE transfer_impact_assessment
                ADD CONSTRAINT FK_tia_updated_by FOREIGN KEY (updated_by_id)
                REFERENCES `user` (id) ON DELETE SET NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS transfer_impact_assessment');
    }
}
