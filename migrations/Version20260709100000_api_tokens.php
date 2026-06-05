<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * F6 — read-only API access tokens (Bearer).
 */
final class Version20260709100000_api_tokens extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'F6: create api_tokens table (read-only Bearer API tokens)';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE api_tokens (
            id INT AUTO_INCREMENT NOT NULL,
            user_id INT NOT NULL,
            tenant_id INT DEFAULT NULL,
            token_hash VARCHAR(64) NOT NULL,
            label VARCHAR(100) NOT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            expires_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            last_used_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            revoked TINYINT(1) DEFAULT 0 NOT NULL,
            UNIQUE INDEX UNIQ_api_token_hash (token_hash),
            INDEX IDX_api_token_user (user_id),
            INDEX IDX_api_token_tenant (tenant_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE api_tokens ADD CONSTRAINT FK_api_token_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE api_tokens ADD CONSTRAINT FK_api_token_tenant FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE api_tokens DROP FOREIGN KEY FK_api_token_user');
        $this->addSql('ALTER TABLE api_tokens DROP FOREIGN KEY FK_api_token_tenant');
        $this->addSql('DROP TABLE api_tokens');
    }
}
