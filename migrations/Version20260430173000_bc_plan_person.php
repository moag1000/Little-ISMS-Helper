<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * BusinessContinuityPlan: add `plan_owner_person_id` (FK Person) and deputies
 * join table `bc_plan_owner_deputy`. Table name is `business_continuity_plan`
 * (confirmed via #[ORM\Table(name: 'business_continuity_plan')] attribute).
 *
 * Plain DDL — no PREPARE/EXECUTE (CLAUDE.md pitfall #6).
 */
final class Version20260430173000_bc_plan_person extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'BusinessContinuityPlan Tri-State Person ownership: plan_owner_person_id FK + bc_plan_owner_deputy join table.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE business_continuity_plan ADD plan_owner_person_id INT DEFAULT NULL');
        $this->addSql(
            'ALTER TABLE business_continuity_plan ADD CONSTRAINT fk_bc_plan_owner_person '
            . 'FOREIGN KEY (plan_owner_person_id) REFERENCES person (id) ON DELETE SET NULL'
        );
        $this->addSql('CREATE INDEX idx_bc_plan_owner_person ON business_continuity_plan (plan_owner_person_id)');

        $this->addSql(
            'CREATE TABLE bc_plan_owner_deputy ('
            . '  bc_plan_id INT NOT NULL, '
            . '  person_id INT NOT NULL, '
            . '  INDEX idx_bc_plan_dep_plan (bc_plan_id), '
            . '  INDEX idx_bc_plan_dep_person (person_id), '
            . '  PRIMARY KEY(bc_plan_id, person_id), '
            . '  CONSTRAINT fk_bc_plan_dep_plan FOREIGN KEY (bc_plan_id) REFERENCES business_continuity_plan (id) ON DELETE CASCADE, '
            . '  CONSTRAINT fk_bc_plan_dep_person FOREIGN KEY (person_id) REFERENCES person (id) ON DELETE CASCADE'
            . ') DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE bc_plan_owner_deputy');
        $this->addSql('ALTER TABLE business_continuity_plan DROP FOREIGN KEY fk_bc_plan_owner_person');
        $this->addSql('DROP INDEX idx_bc_plan_owner_person ON business_continuity_plan');
        $this->addSql('ALTER TABLE business_continuity_plan DROP COLUMN plan_owner_person_id');
    }
}
