<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260428185415 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add metadata JSON column to workflows table for SLA enforcement config';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE workflows ADD metadata JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE workflows DROP metadata');
    }
}
