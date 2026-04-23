<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Phase 8L.F3 — E-Mail-Branding per Tenant.
 *
 * Fünf neue nullable Felder auf `tenant`. null = Fallback-Kaskade greift
 * (Tenant → Ancestors → SystemSettings → Hardcoded-Default).
 */
final class Version20260423120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 8L.F3: tenant email branding fields';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("
            ALTER TABLE tenant
              ADD email_from_name VARCHAR(100) DEFAULT NULL,
              ADD email_from_address VARCHAR(180) DEFAULT NULL,
              ADD email_logo_url VARCHAR(500) DEFAULT NULL,
              ADD email_footer_text LONGTEXT DEFAULT NULL,
              ADD email_support_address VARCHAR(180) DEFAULT NULL
        ");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("
            ALTER TABLE tenant
              DROP email_from_name,
              DROP email_from_address,
              DROP email_logo_url,
              DROP email_footer_text,
              DROP email_support_address
        ");
    }
}
