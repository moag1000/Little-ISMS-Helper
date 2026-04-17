<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * WS-5 (DATA_REUSE_IMPROVEMENT_PLAN.md v1.1 + Anhang C ENT-1):
 * Polymorphic framework tagging with bulk operations + soft-delete history.
 *
 * Decision ENT-1: Option A (polymorphic). Single `entity_tag` table with
 * (entity_class, entity_id, tag_id) instead of per-entity tag columns.
 * Solves MINOR-2 (soft-delete history) centrally.
 *
 * Tables:
 * - tag: tag master data (name, type, framework_code, color, tenant_id)
 * - entity_tag: polymorphic join with soft-delete via tagged_until
 *
 * Uniqueness for active tags: MariaDB does not support partial WHERE-clause
 * unique indexes. We use a persistent generated column `active_marker`
 * that equals `entity_id` when active (tagged_until IS NULL) and is
 * NULL otherwise. Because MariaDB treats NULLs as distinct, this gives us
 * exactly one active (tag_id, entity_class, entity_id) row while allowing
 * unlimited historical (removed) rows.
 */
final class Version20260417230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'WS-5 / ENT-1: polymorphic framework tagging (tag + entity_tag) with soft-delete history';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE tag (
            id INT AUTO_INCREMENT NOT NULL,
            tenant_id INT DEFAULT NULL,
            name VARCHAR(100) NOT NULL,
            type VARCHAR(30) DEFAULT 'framework' NOT NULL,
            framework_code VARCHAR(50) DEFAULT NULL,
            color VARCHAR(20) DEFAULT 'secondary' NOT NULL,
            description LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            INDEX idx_tag_tenant (tenant_id),
            INDEX idx_tag_type (type),
            INDEX idx_tag_framework_code (framework_code),
            UNIQUE INDEX uniq_tag_tenant_name (tenant_id, name),
            PRIMARY KEY (id)
        ) DEFAULT CHARACTER SET utf8mb4");

        $this->addSql('ALTER TABLE tag
            ADD CONSTRAINT fk_tag_tenant FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE');

        // entity_tag: polymorphic join. active_marker is the uniqueness trick.
        $this->addSql("CREATE TABLE entity_tag (
            id INT AUTO_INCREMENT NOT NULL,
            tag_id INT NOT NULL,
            entity_class VARCHAR(150) NOT NULL,
            entity_id INT NOT NULL,
            tagged_by_id INT DEFAULT NULL,
            tagged_from DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            tagged_until DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
            removal_reason LONGTEXT DEFAULT NULL,
            active_marker INT AS (CASE WHEN tagged_until IS NULL THEN entity_id ELSE NULL END) PERSISTENT,
            INDEX idx_entity_tag_entity (entity_class, entity_id),
            INDEX idx_entity_tag_active (tagged_until),
            INDEX idx_entity_tag_tag (tag_id),
            INDEX idx_entity_tag_tagged_by (tagged_by_id),
            UNIQUE INDEX uniq_entity_tag_active (tag_id, entity_class, active_marker),
            PRIMARY KEY (id)
        ) DEFAULT CHARACTER SET utf8mb4");

        $this->addSql('ALTER TABLE entity_tag
            ADD CONSTRAINT fk_entity_tag_tag FOREIGN KEY (tag_id) REFERENCES tag (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE entity_tag
            ADD CONSTRAINT fk_entity_tag_tagged_by FOREIGN KEY (tagged_by_id) REFERENCES users (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE entity_tag DROP FOREIGN KEY fk_entity_tag_tagged_by');
        $this->addSql('ALTER TABLE entity_tag DROP FOREIGN KEY fk_entity_tag_tag');
        $this->addSql('DROP TABLE entity_tag');

        $this->addSql('ALTER TABLE tag DROP FOREIGN KEY fk_tag_tenant');
        $this->addSql('DROP TABLE tag');
    }
}
