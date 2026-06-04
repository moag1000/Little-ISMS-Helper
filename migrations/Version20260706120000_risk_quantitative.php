<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * F46 Quantitative Risk — add SLE/ARO columns to `risk` table.
 *
 * ALE model: ALE = single_loss_expectancy × annual_rate_of_occurrence.
 * Both columns nullable so existing risk rows are unaffected.
 * Module-gate: risk_quant (config/modules.yaml).
 */
final class Version20260706120000_risk_quantitative extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'F46: Add single_loss_expectancy (INT) and annual_rate_of_occurrence (DECIMAL 8,4) to risk table';
    }

    public function isTransactional(): bool
    {
        // DDL (ALTER TABLE) commits implicitly under MySQL — keep outside the
        // per-migration SAVEPOINT so a multi-migration run does not break.
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE risk
                ADD COLUMN single_loss_expectancy INT DEFAULT NULL COMMENT 'F46: Estimated monetary damage per incident occurrence (EUR)',
                ADD COLUMN annual_rate_of_occurrence DECIMAL(8, 4) DEFAULT NULL COMMENT 'F46: Expected number of times this incident occurs per year'
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE risk
                DROP COLUMN single_loss_expectancy,
                DROP COLUMN annual_rate_of_occurrence
        SQL);
    }
}
