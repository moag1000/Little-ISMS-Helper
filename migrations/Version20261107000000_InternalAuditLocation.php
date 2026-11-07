<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Internal audit: optional site (location) for audits with scope type
 * "location", so the location map can show when each site was last audited.
 * Existing location audits are linked when scope_details.location names a
 * location of the same organisation.
 */
final class Version20261107000000_InternalAuditLocation extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Internal audit: add location_id and link existing location-scoped audits by name';
    }

    public function isTransactional(): bool
    {
        // ALTER TABLE commits implicitly in MySQL.
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE internal_audit ADD location_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE internal_audit ADD CONSTRAINT FK_9E4264D064D218E FOREIGN KEY (location_id) REFERENCES location (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_9E4264D064D218E ON internal_audit (location_id)');
        $this->addSql(<<<'SQL'
            UPDATE internal_audit ia
            JOIN location l
              ON l.tenant_id = ia.tenant_id
             AND l.name = JSON_UNQUOTE(JSON_EXTRACT(ia.scope_details, '$.location'))
            SET ia.location_id = l.id
            WHERE ia.scope_type = 'location' AND ia.location_id IS NULL AND ia.scope_details IS NOT NULL
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE internal_audit DROP FOREIGN KEY FK_9E4264D064D218E');
        $this->addSql('DROP INDEX IDX_9E4264D064D218E ON internal_audit');
        $this->addSql('ALTER TABLE internal_audit DROP location_id');
    }
}
