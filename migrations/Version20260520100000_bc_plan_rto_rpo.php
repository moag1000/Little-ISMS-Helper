<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * S4 Foundation P-2 / P0 — ISO 22301 Cl. 8.2.2 / 8.4.2.
 *
 * BC-Plans now persist plan-level Recovery Time Objective (RTO) and
 * Recovery Point Objective (RPO) targets in hours. These are P0 per
 * ISO 22301 because every BC-plan must declare measurable recovery
 * targets that can later be validated against actual exercise results
 * (see `bc_exercise.actual_rto_achieved` / `actual_rpo_achieved`).
 *
 * Previously the FormType silently dropped these into the catch-all
 * "Sonstiges" bucket — the new SectionPolicy puts them under the
 * `recovery` section. The Entity properties also exist now.
 *
 * `isTransactional() = false` — MySQL implicitly commits each ALTER
 * which invalidates Doctrine's per-migration SAVEPOINT.
 */
final class Version20260520100000_bc_plan_rto_rpo extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'S4 P-2 / P0: business_continuity_plan.rto + rpo (ISO 22301 8.2.2).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE business_continuity_plan
                ADD COLUMN rto INT DEFAULT NULL COMMENT 'Recovery Time Objective in hours (ISO 22301 8.2.2)',
                ADD COLUMN rpo INT DEFAULT NULL COMMENT 'Recovery Point Objective in hours (ISO 22301 8.2.2)'
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE business_continuity_plan
                DROP COLUMN rto,
                DROP COLUMN rpo
        SQL);
    }
}
