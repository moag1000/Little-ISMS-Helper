<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Resource-Planning PR-2: ActionItem (UI "Maßnahme") + polymorphic provenance.
 *
 * Depends on PR-1 (roadmap_tasks, teams). isTransactional() = false.
 */
final class Version20260712110000_action_items extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Resource-Planning PR-2: action_items, action_item_teams, action_item_references';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE action_items (
            id INT AUTO_INCREMENT NOT NULL,
            title VARCHAR(255) NOT NULL,
            origin VARCHAR(40) DEFAULT \'internal\' NOT NULL,
            scopes JSON NOT NULL,
            due_date DATE NOT NULL COMMENT \'(DC2Type:date_immutable)\',
            planned_effort_pt NUMERIC(4, 1) DEFAULT NULL,
            status VARCHAR(20) DEFAULT \'open\' NOT NULL,
            completed_at DATE DEFAULT NULL COMMENT \'(DC2Type:date_immutable)\',
            recurrence_months INT DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            responsible_user_id INT DEFAULT NULL,
            responsible_person_id INT DEFAULT NULL,
            roadmap_task_id INT DEFAULT NULL,
            evidence_document_id INT DEFAULT NULL,
            next_action_item_id INT DEFAULT NULL,
            tenant_id INT DEFAULT NULL,
            INDEX IDX_6D025F1BDAD1998 (responsible_user_id),
            INDEX IDX_6D025F1EF64F467 (responsible_person_id),
            INDEX IDX_6D025F1180E73C8 (evidence_document_id),
            INDEX IDX_6D025F16C5C16D6 (next_action_item_id),
            INDEX IDX_6D025F19033212A (tenant_id),
            INDEX idx_action_item_tenant_status (tenant_id, status),
            INDEX idx_action_item_tenant_due (tenant_id, due_date),
            INDEX idx_action_item_task (roadmap_task_id),
            PRIMARY KEY (id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE action_item_teams (
            action_item_id INT NOT NULL,
            team_id INT NOT NULL,
            INDEX IDX_DFC75342C8CDDABF (action_item_id),
            INDEX IDX_DFC75342296CD8AE (team_id),
            PRIMARY KEY (action_item_id, team_id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE action_item_references (
            id INT AUTO_INCREMENT NOT NULL,
            ref_type VARCHAR(40) NOT NULL,
            ref_id INT NOT NULL,
            action_item_id INT NOT NULL,
            tenant_id INT DEFAULT NULL,
            INDEX IDX_41BD53E1C8CDDABF (action_item_id),
            INDEX IDX_41BD53E19033212A (tenant_id),
            INDEX idx_air_target (ref_type, ref_id),
            UNIQUE INDEX uniq_action_item_ref (action_item_id, ref_type, ref_id),
            PRIMARY KEY (id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE action_items ADD CONSTRAINT FK_6D025F1BDAD1998 FOREIGN KEY (responsible_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE action_items ADD CONSTRAINT FK_6D025F1EF64F467 FOREIGN KEY (responsible_person_id) REFERENCES person (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE action_items ADD CONSTRAINT FK_6D025F1926B4F9F FOREIGN KEY (roadmap_task_id) REFERENCES roadmap_tasks (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE action_items ADD CONSTRAINT FK_6D025F1180E73C8 FOREIGN KEY (evidence_document_id) REFERENCES document (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE action_items ADD CONSTRAINT FK_6D025F16C5C16D6 FOREIGN KEY (next_action_item_id) REFERENCES action_items (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE action_items ADD CONSTRAINT FK_6D025F19033212A FOREIGN KEY (tenant_id) REFERENCES tenant (id)');
        $this->addSql('ALTER TABLE action_item_teams ADD CONSTRAINT FK_DFC75342C8CDDABF FOREIGN KEY (action_item_id) REFERENCES action_items (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE action_item_teams ADD CONSTRAINT FK_DFC75342296CD8AE FOREIGN KEY (team_id) REFERENCES teams (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE action_item_references ADD CONSTRAINT FK_41BD53E1C8CDDABF FOREIGN KEY (action_item_id) REFERENCES action_items (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE action_item_references ADD CONSTRAINT FK_41BD53E19033212A FOREIGN KEY (tenant_id) REFERENCES tenant (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE action_item_references DROP FOREIGN KEY FK_41BD53E1C8CDDABF');
        $this->addSql('ALTER TABLE action_item_references DROP FOREIGN KEY FK_41BD53E19033212A');
        $this->addSql('ALTER TABLE action_item_teams DROP FOREIGN KEY FK_DFC75342C8CDDABF');
        $this->addSql('ALTER TABLE action_item_teams DROP FOREIGN KEY FK_DFC75342296CD8AE');
        $this->addSql('ALTER TABLE action_items DROP FOREIGN KEY FK_6D025F1BDAD1998');
        $this->addSql('ALTER TABLE action_items DROP FOREIGN KEY FK_6D025F1EF64F467');
        $this->addSql('ALTER TABLE action_items DROP FOREIGN KEY FK_6D025F1926B4F9F');
        $this->addSql('ALTER TABLE action_items DROP FOREIGN KEY FK_6D025F1180E73C8');
        $this->addSql('ALTER TABLE action_items DROP FOREIGN KEY FK_6D025F16C5C16D6');
        $this->addSql('ALTER TABLE action_items DROP FOREIGN KEY FK_6D025F19033212A');
        $this->addSql('DROP TABLE action_item_references');
        $this->addSql('DROP TABLE action_item_teams');
        $this->addSql('DROP TABLE action_items');
    }
}
