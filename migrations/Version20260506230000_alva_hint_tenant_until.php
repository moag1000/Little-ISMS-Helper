<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Sprint A.5 hardening for AlvaHintDismissal:
 * - tenant_id column + FK so the same hint key + entity_id stays
 *   per-tenant instead of bleeding across tenants
 * - dismissed_until column for snooze-instead-of-forever semantics.
 */
final class Version20260506230000_alva_hint_tenant_until extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Add tenant scope and dismissed_until snooze to alva_hint_dismissal.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE alva_hint_dismissal
                ADD tenant_id INT DEFAULT NULL,
                ADD dismissed_until DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'
        SQL);
        $this->addSql('CREATE INDEX idx_alva_hint_dismissal_tenant ON alva_hint_dismissal (tenant_id)');
        $this->addSql(<<<'SQL'
            ALTER TABLE alva_hint_dismissal
                ADD CONSTRAINT FK_alva_hint_dismissal_tenant
                FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE
        SQL);
        $this->addSql('ALTER TABLE alva_hint_dismissal DROP INDEX uq_alva_hint_dismissal');
        $this->addSql(<<<'SQL'
            ALTER TABLE alva_hint_dismissal
                ADD CONSTRAINT uq_alva_hint_dismissal
                UNIQUE (user_id, tenant_id, hint_key, entity_type, entity_id)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE alva_hint_dismissal DROP INDEX uq_alva_hint_dismissal');
        $this->addSql(<<<'SQL'
            ALTER TABLE alva_hint_dismissal
                ADD CONSTRAINT uq_alva_hint_dismissal
                UNIQUE (user_id, hint_key, entity_type, entity_id)
        SQL);
        $this->addSql('ALTER TABLE alva_hint_dismissal DROP FOREIGN KEY FK_alva_hint_dismissal_tenant');
        $this->addSql('DROP INDEX idx_alva_hint_dismissal_tenant ON alva_hint_dismissal');
        $this->addSql(<<<'SQL'
            ALTER TABLE alva_hint_dismissal
                DROP tenant_id,
                DROP dismissed_until
        SQL);
    }
}
