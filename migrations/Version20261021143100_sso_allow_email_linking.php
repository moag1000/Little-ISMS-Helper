<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * SSO hardening (#1059): per-provider option "link existing accounts by
 * e-mail" (identity_provider.allow_email_linking).
 *
 * New providers default to false (the column default). Providers that exist
 * when this migration runs linked by e-mail so far and keep doing so (set to
 * true) — an admin can switch it off in the provider form.
 */
final class Version20261021143100_sso_allow_email_linking extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'SSO: add identity_provider.allow_email_linking (existing providers keep linking by e-mail)';
    }

    public function isTransactional(): bool
    {
        // ALTER TABLE commits implicitly in MySQL (invalidates the SAVEPOINT).
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE identity_provider ADD allow_email_linking TINYINT(1) DEFAULT 0 NOT NULL');
        $this->addSql('UPDATE identity_provider SET allow_email_linking = 1');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE identity_provider DROP allow_email_linking');
    }
}
