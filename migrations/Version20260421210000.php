<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Sprint 13 / P5: GuidedTourStepOverride.
 *
 * Tenant-/Global-Override-Tabelle für Tour-Step-Texte. tenant_id NULL =
 * systemweiter Override (nur SUPER_ADMIN).
 */
final class Version20260421210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'P5: guided_tour_step_override (tenant_id/tour_id/step_id/locale unique)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS guided_tour_step_override (
                id INT AUTO_INCREMENT NOT NULL,
                tenant_id INT DEFAULT NULL,
                tour_id VARCHAR(32) NOT NULL,
                step_id VARCHAR(64) NOT NULL,
                locale VARCHAR(5) NOT NULL,
                title_override VARCHAR(255) DEFAULT NULL,
                body_override LONGTEXT DEFAULT NULL,
                updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_by_email VARCHAR(180) DEFAULT NULL,
                UNIQUE INDEX uniq_tour_step_override (tenant_id, tour_id, step_id, locale),
                INDEX idx_tour_step_tenant (tenant_id),
                PRIMARY KEY (id),
                CONSTRAINT fk_tour_step_tenant FOREIGN KEY (tenant_id)
                    REFERENCES tenant (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE guided_tour_step_override');
    }
}
