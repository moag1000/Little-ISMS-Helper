<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Phase 9.P1.7 — NIS2 registration fields on tenant.
 *
 * NIS2 (Germany: BSIG §28) regulates single legal entities (Rechtsperson),
 * not corporate groups. In a mixed holding, each subsidiary carries its
 * own classification. This migration adds the fields needed for the
 * Group-CISO registration matrix:
 *   - nis2_classification: essential / important / not_regulated / unknown
 *   - nis2_sector:         BSI sector label (e.g. "Digital Infrastructure")
 *   - nace_code:           economic classification, e.g. 62.03
 *   - legal_name:          formal Rechtsperson name (may differ from display name)
 *   - legal_form:          GmbH, AG, ...
 *   - nis2_contact_point:  person or function registered with BSI
 *   - nis2_registered_at:  date of BSI registration
 *
 * Uses `ALTER TABLE ... ADD COLUMN IF NOT EXISTS` — idempotent re-run.
 */
final class Version20260420110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'NIS2 registration fields on tenant (P1.7)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tenant ADD COLUMN IF NOT EXISTS nis2_classification VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE tenant ADD COLUMN IF NOT EXISTS nis2_sector VARCHAR(150) DEFAULT NULL');
        $this->addSql('ALTER TABLE tenant ADD COLUMN IF NOT EXISTS nace_code VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE tenant ADD COLUMN IF NOT EXISTS legal_name VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE tenant ADD COLUMN IF NOT EXISTS legal_form VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE tenant ADD COLUMN IF NOT EXISTS nis2_contact_point VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE tenant ADD COLUMN IF NOT EXISTS nis2_registered_at DATE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tenant DROP COLUMN IF EXISTS nis2_classification');
        $this->addSql('ALTER TABLE tenant DROP COLUMN IF EXISTS nis2_sector');
        $this->addSql('ALTER TABLE tenant DROP COLUMN IF EXISTS nace_code');
        $this->addSql('ALTER TABLE tenant DROP COLUMN IF EXISTS legal_name');
        $this->addSql('ALTER TABLE tenant DROP COLUMN IF EXISTS legal_form');
        $this->addSql('ALTER TABLE tenant DROP COLUMN IF EXISTS nis2_contact_point');
        $this->addSql('ALTER TABLE tenant DROP COLUMN IF EXISTS nis2_registered_at');
    }
}
