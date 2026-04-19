<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * DORA Art. 26 TLPT entity + findings link-table.
 */
final class Version20260419180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'DORA Art. 26: threat_led_penetration_test + tlpt_finding join table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS threat_led_penetration_test (
            id INT AUTO_INCREMENT NOT NULL,
            tenant_id INT NOT NULL,
            engagement_number VARCHAR(50) DEFAULT NULL,
            title VARCHAR(255) DEFAULT NULL,
            scope LONGTEXT DEFAULT NULL,
            threat_intelligence_basis LONGTEXT DEFAULT NULL,
            provider_type VARCHAR(20) NOT NULL,
            test_provider VARCHAR(255) DEFAULT NULL,
            jurisdiction_codes JSON DEFAULT NULL COMMENT \'(DC2Type:json)\',
            status VARCHAR(30) NOT NULL,
            planned_start_date DATE DEFAULT NULL COMMENT \'(DC2Type:date_immutable)\',
            planned_end_date DATE DEFAULT NULL COMMENT \'(DC2Type:date_immutable)\',
            actual_start_date DATE DEFAULT NULL COMMENT \'(DC2Type:date_immutable)\',
            actual_end_date DATE DEFAULT NULL COMMENT \'(DC2Type:date_immutable)\',
            executive_summary LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX idx_tlpt_tenant (tenant_id),
            INDEX idx_tlpt_status (status),
            INDEX idx_tlpt_planned_date (planned_start_date),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql("SET @fk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='threat_led_penetration_test' AND CONSTRAINT_NAME='FK_TLPT_TENANT')");
        $this->addSql("SET @sql := IF(@fk = 0, 'ALTER TABLE threat_led_penetration_test ADD CONSTRAINT FK_TLPT_TENANT FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE', 'SELECT 1')");
        $this->addSql('PREPARE stmt FROM @sql'); $this->addSql('EXECUTE stmt'); $this->addSql('DEALLOCATE PREPARE stmt');

        $this->addSql('CREATE TABLE IF NOT EXISTS tlpt_finding (
            threat_led_penetration_test_id INT NOT NULL,
            audit_finding_id INT NOT NULL,
            INDEX idx_tf_tlpt (threat_led_penetration_test_id),
            INDEX idx_tf_finding (audit_finding_id),
            PRIMARY KEY (threat_led_penetration_test_id, audit_finding_id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql("SET @fk1 := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='tlpt_finding' AND CONSTRAINT_NAME='FK_TF_TLPT')");
        $this->addSql("SET @sql := IF(@fk1 = 0, 'ALTER TABLE tlpt_finding ADD CONSTRAINT FK_TF_TLPT FOREIGN KEY (threat_led_penetration_test_id) REFERENCES threat_led_penetration_test (id) ON DELETE CASCADE', 'SELECT 1')");
        $this->addSql('PREPARE stmt FROM @sql'); $this->addSql('EXECUTE stmt'); $this->addSql('DEALLOCATE PREPARE stmt');

        $this->addSql("SET @fk2 := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='tlpt_finding' AND CONSTRAINT_NAME='FK_TF_FINDING')");
        $this->addSql("SET @sql := IF(@fk2 = 0, 'ALTER TABLE tlpt_finding ADD CONSTRAINT FK_TF_FINDING FOREIGN KEY (audit_finding_id) REFERENCES audit_findings (id) ON DELETE CASCADE', 'SELECT 1')");
        $this->addSql('PREPARE stmt FROM @sql'); $this->addSql('EXECUTE stmt'); $this->addSql('DEALLOCATE PREPARE stmt');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tlpt_finding DROP FOREIGN KEY FK_TF_TLPT, DROP FOREIGN KEY FK_TF_FINDING');
        $this->addSql('DROP TABLE tlpt_finding');
        $this->addSql('ALTER TABLE threat_led_penetration_test DROP FOREIGN KEY FK_TLPT_TENANT');
        $this->addSql('DROP TABLE threat_led_penetration_test');
    }
}
