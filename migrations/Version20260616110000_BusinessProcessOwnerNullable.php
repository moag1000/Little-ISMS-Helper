<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260616110000_BusinessProcessOwnerNullable extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make business_process.process_owner nullable — Pattern A dual-state owner '
            . '(processOwnerUser / processOwnerPerson take precedence; legacy free-text '
            . 'is optional). Aligns column with InternalAudit.leadAuditor, Asset.owner, '
            . 'BusinessContinuityPlan.planOwner which are all nullable: true.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE business_process MODIFY process_owner VARCHAR(100) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // Backfill NULLs with empty string so the NOT NULL re-add does not fail.
        $this->addSql("UPDATE business_process SET process_owner = '' WHERE process_owner IS NULL");
        $this->addSql('ALTER TABLE business_process MODIFY process_owner VARCHAR(100) NOT NULL');
    }
}
