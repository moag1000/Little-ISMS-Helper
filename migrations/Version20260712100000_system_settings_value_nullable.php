<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Make system_settings.value nullable so encrypted settings (ciphertext in
 * encrypted_value, value = NULL) can actually persist. The column was NOT NULL,
 * so the encrypted-setting code path always failed on flush.
 */
final class Version20260712100000_system_settings_value_nullable extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make system_settings.value nullable (enables encrypted settings)';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE system_settings CHANGE value value JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE system_settings CHANGE value value JSON NOT NULL');
    }
}
