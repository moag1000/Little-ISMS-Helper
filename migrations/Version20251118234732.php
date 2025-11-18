<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251118234732 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE dashboard_layouts (id INT AUTO_INCREMENT NOT NULL, layout_config JSON NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, user_id INT NOT NULL, tenant_id INT NOT NULL, INDEX idx_dashboard_user (user_id), INDEX idx_dashboard_tenant (tenant_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE dashboard_layouts ADD CONSTRAINT FK_E4D4A419A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE dashboard_layouts ADD CONSTRAINT FK_E4D4A4199033212A FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE compliance_framework CHANGE required_modules required_modules JSON DEFAULT NULL');
        $this->addSql('DROP INDEX IDX_compliance_mapping_requires_review ON compliance_mapping');
        $this->addSql('DROP INDEX IDX_compliance_mapping_quality_score ON compliance_mapping');
        $this->addSql('DROP INDEX IDX_compliance_mapping_analysis_confidence ON compliance_mapping');
        $this->addSql('DROP INDEX IDX_compliance_mapping_review_status ON compliance_mapping');
        $this->addSql('ALTER TABLE compliance_mapping CHANGE requires_review requires_review TINYINT(1) NOT NULL, CHANGE review_status review_status VARCHAR(30) NOT NULL, CHANGE reviewed_at reviewed_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE corporate_governance CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE corporate_governance ADD CONSTRAINT FK_F759C417B03A8386 FOREIGN KEY (created_by_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE corporate_governance RENAME INDEX idx_9815e5739033212a TO IDX_F759C4179033212A');
        $this->addSql('ALTER TABLE corporate_governance RENAME INDEX idx_9815e573727aca70 TO IDX_F759C417727ACA70');
        $this->addSql('ALTER TABLE corporate_governance RENAME INDEX idx_9815e573b03a8386 TO IDX_F759C417B03A8386');
        $this->addSql('ALTER TABLE internal_audit_subsidiary RENAME INDEX idx_audit_subsidiary_audit TO IDX_D5AC8E1728B7E81');
        $this->addSql('ALTER TABLE internal_audit_subsidiary RENAME INDEX idx_audit_subsidiary_tenant TO IDX_D5AC8E19033212A');
        $this->addSql('DROP INDEX IDX_mapping_gap_item_priority ON mapping_gap_item');
        $this->addSql('DROP INDEX IDX_mapping_gap_item_status ON mapping_gap_item');
        $this->addSql('ALTER TABLE mapping_gap_item CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE mapping_gap_item RENAME INDEX idx_mapping_gap_item_mapping TO IDX_26B6EE62FABB77CC');
        $this->addSql('ALTER TABLE user_sessions CHANGE is_active is_active TINYINT(1) NOT NULL, CHANGE created_at created_at DATETIME NOT NULL, CHANGE last_activity_at last_activity_at DATETIME NOT NULL, CHANGE ended_at ended_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE user_sessions RENAME INDEX uniq_31bbdc269ab44fe0 TO UNIQ_7AED7913613FECDF');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE dashboard_layouts DROP FOREIGN KEY FK_E4D4A419A76ED395');
        $this->addSql('ALTER TABLE dashboard_layouts DROP FOREIGN KEY FK_E4D4A4199033212A');
        $this->addSql('DROP TABLE dashboard_layouts');
        $this->addSql('ALTER TABLE compliance_framework CHANGE required_modules required_modules JSON DEFAULT NULL COMMENT \'(DC2Type:json)\'');
        $this->addSql('ALTER TABLE compliance_mapping CHANGE requires_review requires_review TINYINT(1) DEFAULT 0 NOT NULL, CHANGE review_status review_status VARCHAR(30) DEFAULT \'unreviewed\' NOT NULL, CHANGE reviewed_at reviewed_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE INDEX IDX_compliance_mapping_requires_review ON compliance_mapping (requires_review)');
        $this->addSql('CREATE INDEX IDX_compliance_mapping_quality_score ON compliance_mapping (quality_score)');
        $this->addSql('CREATE INDEX IDX_compliance_mapping_analysis_confidence ON compliance_mapping (analysis_confidence)');
        $this->addSql('CREATE INDEX IDX_compliance_mapping_review_status ON compliance_mapping (review_status)');
        $this->addSql('ALTER TABLE corporate_governance DROP FOREIGN KEY FK_F759C417B03A8386');
        $this->addSql('ALTER TABLE corporate_governance CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE updated_at updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE corporate_governance RENAME INDEX idx_f759c4179033212a TO IDX_9815E5739033212A');
        $this->addSql('ALTER TABLE corporate_governance RENAME INDEX idx_f759c417727aca70 TO IDX_9815E573727ACA70');
        $this->addSql('ALTER TABLE corporate_governance RENAME INDEX idx_f759c417b03a8386 TO IDX_9815E573B03A8386');
        $this->addSql('ALTER TABLE internal_audit_subsidiary RENAME INDEX idx_d5ac8e1728b7e81 TO IDX_audit_subsidiary_audit');
        $this->addSql('ALTER TABLE internal_audit_subsidiary RENAME INDEX idx_d5ac8e19033212a TO IDX_audit_subsidiary_tenant');
        $this->addSql('ALTER TABLE mapping_gap_item CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE updated_at updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE INDEX IDX_mapping_gap_item_priority ON mapping_gap_item (priority)');
        $this->addSql('CREATE INDEX IDX_mapping_gap_item_status ON mapping_gap_item (status)');
        $this->addSql('ALTER TABLE mapping_gap_item RENAME INDEX idx_26b6ee62fabb77cc TO IDX_mapping_gap_item_mapping');
        $this->addSql('ALTER TABLE user_sessions CHANGE is_active is_active TINYINT(1) DEFAULT 1 NOT NULL, CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE last_activity_at last_activity_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE ended_at ended_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE user_sessions RENAME INDEX uniq_7aed7913613fecdf TO UNIQ_31BBDC269AB44FE0');
    }
}
