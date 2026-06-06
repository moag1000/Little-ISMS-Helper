<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Resource-Planning foundation (PR-1): master-data tables + Person capacity field.
 *
 * Creates: teams (JOINED-inheritance base), team_members, roadmap_groups,
 * roadmap_tasks, roadmap_task_teams. Adds person.isms_availability_pct.
 *
 * One consolidated DDL migration (per house rule). isTransactional() = false
 * because MySQL CREATE/ALTER TABLE commit implicitly.
 */
final class Version20260712100000_resource_planning_foundation extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Resource-Planning PR-1: teams, roadmap_groups, roadmap_tasks (+ join tables) + person.isms_availability_pct';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE teams (
            id INT AUTO_INCREMENT NOT NULL,
            name VARCHAR(255) NOT NULL,
            description LONGTEXT DEFAULT NULL,
            type VARCHAR(30) DEFAULT NULL,
            is_active TINYINT DEFAULT 1 NOT NULL,
            valid_from DATE DEFAULT NULL,
            valid_until DATE DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            team_lead_id INT DEFAULT NULL,
            team_lead_person_id INT DEFAULT NULL,
            tenant_id INT DEFAULT NULL,
            team_kind VARCHAR(32) NOT NULL,
            INDEX IDX_96C22258FF2C34BA (team_lead_id),
            INDEX IDX_96C222583FA57888 (team_lead_person_id),
            INDEX idx_team_active (is_active),
            INDEX idx_team_tenant (tenant_id),
            PRIMARY KEY (id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE team_members (
            team_id INT NOT NULL,
            person_id INT NOT NULL,
            INDEX IDX_BAD9A3C8296CD8AE (team_id),
            INDEX IDX_BAD9A3C8217BBB47 (person_id),
            PRIMARY KEY (team_id, person_id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE roadmap_groups (
            id INT AUTO_INCREMENT NOT NULL,
            name VARCHAR(255) NOT NULL,
            sort_order INT DEFAULT 0 NOT NULL,
            color_token VARCHAR(40) DEFAULT NULL,
            icon VARCHAR(40) DEFAULT NULL,
            isms_domain VARCHAR(60) DEFAULT NULL,
            default_visibility VARCHAR(10) DEFAULT \'team\' NOT NULL,
            is_system_group TINYINT DEFAULT 0 NOT NULL,
            is_active TINYINT DEFAULT 1 NOT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            default_team_id INT DEFAULT NULL,
            tenant_id INT DEFAULT NULL,
            INDEX IDX_F7A04EA3DBE989EB (default_team_id),
            INDEX idx_roadmap_group_tenant (tenant_id),
            PRIMARY KEY (id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE roadmap_tasks (
            id INT AUTO_INCREMENT NOT NULL,
            name VARCHAR(255) NOT NULL,
            default_pt_per_week NUMERIC(4, 1) DEFAULT NULL,
            recurring TINYINT DEFAULT 0 NOT NULL,
            visibility VARCHAR(10) DEFAULT \'team\' NOT NULL,
            isms_domain VARCHAR(60) DEFAULT NULL,
            is_reactive_reservation TINYINT DEFAULT 0 NOT NULL,
            is_system_task TINYINT DEFAULT 0 NOT NULL,
            is_active TINYINT DEFAULT 1 NOT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            group_id INT DEFAULT NULL,
            default_team_id INT DEFAULT NULL,
            tenant_id INT DEFAULT NULL,
            INDEX IDX_F0EBAF93FE54D947 (group_id),
            INDEX IDX_F0EBAF93DBE989EB (default_team_id),
            INDEX idx_roadmap_task_tenant (tenant_id),
            INDEX idx_roadmap_task_active (is_active),
            PRIMARY KEY (id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE roadmap_task_teams (
            roadmap_task_id INT NOT NULL,
            team_id INT NOT NULL,
            INDEX IDX_D4096D7A926B4F9F (roadmap_task_id),
            INDEX IDX_D4096D7A296CD8AE (team_id),
            PRIMARY KEY (roadmap_task_id, team_id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE teams ADD CONSTRAINT FK_96C22258FF2C34BA FOREIGN KEY (team_lead_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE teams ADD CONSTRAINT FK_96C222583FA57888 FOREIGN KEY (team_lead_person_id) REFERENCES person (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE teams ADD CONSTRAINT FK_96C222589033212A FOREIGN KEY (tenant_id) REFERENCES tenant (id)');
        $this->addSql('ALTER TABLE team_members ADD CONSTRAINT FK_BAD9A3C8296CD8AE FOREIGN KEY (team_id) REFERENCES teams (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE team_members ADD CONSTRAINT FK_BAD9A3C8217BBB47 FOREIGN KEY (person_id) REFERENCES person (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE roadmap_groups ADD CONSTRAINT FK_F7A04EA3DBE989EB FOREIGN KEY (default_team_id) REFERENCES teams (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE roadmap_groups ADD CONSTRAINT FK_F7A04EA39033212A FOREIGN KEY (tenant_id) REFERENCES tenant (id)');
        $this->addSql('ALTER TABLE roadmap_tasks ADD CONSTRAINT FK_F0EBAF93FE54D947 FOREIGN KEY (group_id) REFERENCES roadmap_groups (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE roadmap_tasks ADD CONSTRAINT FK_F0EBAF93DBE989EB FOREIGN KEY (default_team_id) REFERENCES teams (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE roadmap_tasks ADD CONSTRAINT FK_F0EBAF939033212A FOREIGN KEY (tenant_id) REFERENCES tenant (id)');
        $this->addSql('ALTER TABLE roadmap_task_teams ADD CONSTRAINT FK_D4096D7A926B4F9F FOREIGN KEY (roadmap_task_id) REFERENCES roadmap_tasks (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE roadmap_task_teams ADD CONSTRAINT FK_D4096D7A296CD8AE FOREIGN KEY (team_id) REFERENCES teams (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE person ADD isms_availability_pct DOUBLE PRECISION DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE person DROP isms_availability_pct');
        $this->addSql('ALTER TABLE roadmap_task_teams DROP FOREIGN KEY FK_D4096D7A926B4F9F');
        $this->addSql('ALTER TABLE roadmap_task_teams DROP FOREIGN KEY FK_D4096D7A296CD8AE');
        $this->addSql('ALTER TABLE roadmap_tasks DROP FOREIGN KEY FK_F0EBAF93FE54D947');
        $this->addSql('ALTER TABLE roadmap_tasks DROP FOREIGN KEY FK_F0EBAF93DBE989EB');
        $this->addSql('ALTER TABLE roadmap_tasks DROP FOREIGN KEY FK_F0EBAF939033212A');
        $this->addSql('ALTER TABLE roadmap_groups DROP FOREIGN KEY FK_F7A04EA3DBE989EB');
        $this->addSql('ALTER TABLE roadmap_groups DROP FOREIGN KEY FK_F7A04EA39033212A');
        $this->addSql('ALTER TABLE team_members DROP FOREIGN KEY FK_BAD9A3C8296CD8AE');
        $this->addSql('ALTER TABLE team_members DROP FOREIGN KEY FK_BAD9A3C8217BBB47');
        $this->addSql('ALTER TABLE teams DROP FOREIGN KEY FK_96C22258FF2C34BA');
        $this->addSql('ALTER TABLE teams DROP FOREIGN KEY FK_96C222583FA57888');
        $this->addSql('ALTER TABLE teams DROP FOREIGN KEY FK_96C222589033212A');
        $this->addSql('DROP TABLE roadmap_task_teams');
        $this->addSql('DROP TABLE roadmap_tasks');
        $this->addSql('DROP TABLE roadmap_groups');
        $this->addSql('DROP TABLE team_members');
        $this->addSql('DROP TABLE teams');
    }
}
