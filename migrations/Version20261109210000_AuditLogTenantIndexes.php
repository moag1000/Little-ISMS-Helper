<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * S31: composite indexes for the audit log. The list (`tenant ORDER BY created_at`), the filter dropdowns and the
 * statistics (`GROUP BY action / entity_type`) of a large organisation used only single-column indexes. Additive,
 * re-runnable (each index is created only when missing).
 */
final class Version20261109210000_AuditLogTenantIndexes extends AbstractMigration
{
    private const array INDEXES = [
        'idx_audit_tenant_created' => ['tenant_id', 'created_at'],
        'idx_audit_tenant_action' => ['tenant_id', 'action'],
        'idx_audit_tenant_entity_type' => ['tenant_id', 'entity_type'],
    ];

    public function getDescription(): string
    {
        return 'Audit log: composite indexes (tenant_id, created_at / action / entity_type)';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable('audit_log');
        foreach (self::INDEXES as $name => $columns) {
            if (!$table->hasIndex($name)) {
                $this->addSql(sprintf('CREATE INDEX %s ON audit_log (%s)', $name, implode(', ', $columns)));
            }
        }
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('audit_log');
        foreach (array_keys(self::INDEXES) as $name) {
            if ($table->hasIndex($name)) {
                $this->addSql(sprintf('DROP INDEX %s ON audit_log', $name));
            }
        }
    }
}
