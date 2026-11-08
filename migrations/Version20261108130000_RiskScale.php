<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Fix-Paket P8b (ISO 27001 6.1.2 a/b): the risk scale belongs to the
 * organisation — band edges (critical/high/medium) and what probability and
 * impact levels 1–5 mean. Existing organisations keep 20/12/6.
 */
final class Version20261108130000_RiskScale extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Risk criteria: band edges and level meanings per organisation';
    }

    public function isTransactional(): bool
    {
        // ALTER TABLE commits implicitly in MySQL.
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE risk_approval_config'
            . ' ADD band_critical_min INT DEFAULT 20 NOT NULL,'
            . ' ADD band_high_min INT DEFAULT 12 NOT NULL,'
            . ' ADD band_medium_min INT DEFAULT 6 NOT NULL,'
            . ' ADD probability_criteria JSON DEFAULT NULL,'
            . ' ADD impact_criteria JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE risk_approval_config DROP band_critical_min, DROP band_high_min, DROP band_medium_min, DROP probability_criteria, DROP impact_criteria');
    }
}
