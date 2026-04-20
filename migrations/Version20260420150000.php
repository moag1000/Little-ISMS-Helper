<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * B4 — InternalAudit.additionalScopedFrameworks (Multi-Framework-Audit).
 *
 * Allows one internal audit to cover 27001 + NIS2 + DORA simultaneously.
 * Additive join table; existing single-framework audits keep the
 * `scoped_framework_id` column untouched.
 */
final class Version20260420150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'B4: internal_audit_additional_framework (M:M for multi-framework audits)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS internal_audit_additional_framework (
                internal_audit_id INT NOT NULL,
                compliance_framework_id INT NOT NULL,
                INDEX idx_iaaf_audit (internal_audit_id),
                INDEX idx_iaaf_framework (compliance_framework_id),
                CONSTRAINT fk_iaaf_audit FOREIGN KEY (internal_audit_id) REFERENCES internal_audit (id) ON DELETE CASCADE,
                CONSTRAINT fk_iaaf_framework FOREIGN KEY (compliance_framework_id) REFERENCES compliance_framework (id) ON DELETE CASCADE,
                PRIMARY KEY(internal_audit_id, compliance_framework_id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS internal_audit_additional_framework');
    }
}
