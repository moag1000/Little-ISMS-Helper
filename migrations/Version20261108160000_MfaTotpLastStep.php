<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * S8 (NIS-2 Art. 21(2)(j), ISO 27001 A.8.5): a TOTP code counts once. The token remembers the
 * last accepted time step; a code of that step or an earlier one is refused.
 */
final class Version20261108160000_MfaTotpLastStep extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'MFA: last accepted TOTP time step per token (no code reuse)';
    }

    public function isTransactional(): bool
    {
        // ALTER TABLE commits implicitly in MySQL.
        return false;
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('mfa_tokens') && $schema->getTable('mfa_tokens')->hasColumn('last_totp_step')) {
            return; // re-run after a partial upgrade
        }
        $this->addSql('ALTER TABLE mfa_tokens ADD last_totp_step INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mfa_tokens DROP last_totp_step');
    }
}
