<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add mapping_onboarding_state JSON column to the users table.
 *
 * Tracks per-user progress through the 4-step mapping-onboarding workflow
 * (Laden → Reviewen → Mappen → Wiederverwenden).
 *
 * DDL migration — isTransactional()=false required (CLAUDE.md Pitfall #6).
 */
final class Version20260620093000_user_mapping_onboarding_state extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add users.mapping_onboarding_state JSON column for mapping-onboarding workflow progress.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE `users` ADD mapping_onboarding_state JSON NOT NULL DEFAULT (JSON_ARRAY()) COMMENT '(DC2Type:json) Mapping-onboarding workflow progress (Laden->Reviewen->Mappen->Wiederverwenden)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `users` DROP mapping_onboarding_state');
    }
}
