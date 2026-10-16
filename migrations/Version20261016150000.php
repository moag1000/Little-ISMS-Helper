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
 *
 * Accounts without an organisation: the tenant filter used to show such an
 * account every organisation's data; it now shows none unless the account is
 * a SUPER_ADMIN. On a single-organisation installation the accounts created
 * without one (setup-wizard admin, global SSO provider, CLI) are assigned to
 * the sole organisation so they keep working. With several organisations an
 * operator assigns them (Tenant management, Data repair). Not reverted by down().
 */
final class Version20261016150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add mapping_gap_item.tenant_id; assign accounts without an organisation to the sole organisation';
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
        $this->addSql(
            'UPDATE users u'
            . ' JOIN (SELECT MIN(id) AS id FROM tenant HAVING COUNT(*) = 1) sole'
            . ' SET u.tenant_id = sole.id'
            . " WHERE u.tenant_id IS NULL AND u.roles NOT LIKE '%ROLE_SUPER_ADMIN%'"
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
