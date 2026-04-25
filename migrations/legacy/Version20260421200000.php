<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Sprint 13 / S13-3: Guided Tour Completion-Tracking.
 *
 * Fügt users.completed_tours JSON hinzu. Default '[]'.
 */
final class Version20260421200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'S13-3: users.completed_tours JSON (Guided Tour completion tracking)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE users
            ADD COLUMN completed_tours JSON NOT NULL DEFAULT (JSON_ARRAY())
            COMMENT '(DC2Type:json) Guided-Tour-IDs already completed by this user'
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP COLUMN completed_tours');
    }
}
