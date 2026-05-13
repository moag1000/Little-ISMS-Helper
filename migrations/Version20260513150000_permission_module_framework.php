<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Enrich Permission entity with module + framework_reference fields.
 *
 *   - module            VARCHAR(64)  NULL — matches config/modules.yaml key
 *   - framework_reference VARCHAR(120) NULL — e.g. 'ISO 27001 Cl. 6.1.2', 'GDPR Art. 33 + 34'
 *
 * DDL migration → isTransactional() = false (MySQL implicit commit per ALTER TABLE).
 */
final class Version20260513150000_permission_module_framework extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Add module + framework_reference columns to permissions table for rich role-creation table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE permissions
            ADD COLUMN module VARCHAR(64) NULL AFTER category,
            ADD COLUMN framework_reference VARCHAR(120) NULL AFTER module
        ");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE permissions
            DROP COLUMN module,
            DROP COLUMN framework_reference
        ');
    }
}
