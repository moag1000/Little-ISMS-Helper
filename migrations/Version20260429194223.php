<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add essentialForSmallBusiness boolean flag to the control table.
 * Used to mark the ~31 controls from the Generic Starter baseline
 * that are essential for small businesses (SME/KMU < 50 FTE).
 */
final class Version20260429194223 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add essential_for_small_business flag to control table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE control ADD essential_for_small_business TINYINT(1) DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE control DROP essential_for_small_business');
    }
}
