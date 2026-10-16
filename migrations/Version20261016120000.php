<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Tenant ownership for mapping gap items.
 *
 * Gap items had no tenant, so gaps generated for a mapping that involves an
 * organisation's uploaded requirement (including keywords lifted from that
 * requirement's text) were listed and editable for every organisation. The
 * owner is derived from the mapping: upload tenant of the source or target
 * requirement; NULL keeps gaps of global catalogue mappings shared.
 */
final class Version20261016120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add mapping_gap_item.tenant_id (owner derived from the mapping, NULL = shared)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mapping_gap_item ADD tenant_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE mapping_gap_item ADD CONSTRAINT FK_26B6EE629033212A FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX idx_mapping_gap_item_tenant ON mapping_gap_item (tenant_id)');
        $this->addSql(
            'UPDATE mapping_gap_item g'
            . ' JOIN compliance_mapping m ON m.id = g.mapping_id'
            . ' JOIN compliance_requirement sr ON sr.id = m.source_requirement_id'
            . ' JOIN compliance_requirement tr ON tr.id = m.target_requirement_id'
            . ' SET g.tenant_id = COALESCE(sr.upload_tenant_id, tr.upload_tenant_id)'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mapping_gap_item DROP FOREIGN KEY FK_26B6EE629033212A');
        $this->addSql('DROP INDEX idx_mapping_gap_item_tenant ON mapping_gap_item');
        $this->addSql('ALTER TABLE mapping_gap_item DROP tenant_id');
    }

    /** DDL commits implicitly on MySQL/MariaDB and would invalidate the savepoint. */
    public function isTransactional(): bool
    {
        return false;
    }
}
