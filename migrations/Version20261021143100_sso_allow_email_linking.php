<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * SSO hardening (#1059): per-provider option "link existing accounts by
 * e-mail" (identity_provider.allow_email_linking).
 *
 * New providers created through the entity/form default to false (property
 * default). The column default is true, so providers that exist when this
 * migration runs — and rows a schema reconcile or an older backup restore
 * writes — keep linking by e-mail; an admin can switch it off in the form.
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
        // Column default 1: existing providers (and rows written by a schema
        // reconcile or restore of an older backup) keep linking. New providers
        // created through the entity start with false (property default).
        $this->addSql('ALTER TABLE identity_provider ADD allow_email_linking TINYINT(1) DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE identity_provider DROP allow_email_linking');
    }
}
