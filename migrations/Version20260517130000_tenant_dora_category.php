<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * F-DORA-TENANT — Tenant-level DORA entity category flag.
 *
 * Adds dora_entity_category column to the tenant table so the platform can
 * determine whether a given organisation is subject to DORA at all.
 *
 * Values:
 *   none                    — not subject to DORA (default)
 *   financial_entity        — Bank / Versicherer / Investment-Firm (DORA Art. 2(2))
 *   critical_ict_third_party — designated CTPP by ESAs (DORA Art. 31)
 *
 * Timestamp after Version20260517120000_dora_relevant_flags (parallel entity-level flags).
 */
final class Version20260517130000_tenant_dora_category extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Add dora_entity_category column to tenant table (F-DORA-TENANT)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "ALTER TABLE tenant ADD COLUMN dora_entity_category VARCHAR(40) NOT NULL DEFAULT 'none' AFTER nis2_registered_at"
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tenant DROP COLUMN dora_entity_category');
    }
}
