<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Audit-V3 LB-7 — Risk acceptance expiry date.
 *
 * Adds `risk.acceptance_expiry_date` (DATE, NULL) to close the ISO 27001
 * Cl. 8.3 audit-finding "risk acceptance without expiry / re-evaluation
 * date". Existing accepted risks remain NULL — the next risk-review
 * cycle is responsible for filling in a finite horizon. Auditors then
 * see "expired since X days" via `Risk::isAcceptanceExpired()`.
 *
 * Plain ALTER TABLE; isTransactional() = false per CLAUDE.md pitfall #6
 * so MySQL's implicit DDL commit doesn't invalidate the migration
 * SAVEPOINT in multi-migration runs.
 */
final class Version20260510120000_risk_acceptance_expiry extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Audit-V3 LB-7: add risk.acceptance_expiry_date (DATE NULL) for ISO 27001 Cl. 8.3 '
            . 'compliance — every accepted risk must carry a finite re-evaluation horizon.';
    }

    public function up(Schema $schema): void
    {
        if (!$this->columnExists('risk', 'acceptance_expiry_date')) {
            $this->addSql(<<<'SQL'
                ALTER TABLE risk
                    ADD COLUMN acceptance_expiry_date DATE DEFAULT NULL
            SQL);
        }
    }

    public function down(Schema $schema): void
    {
        if ($this->columnExists('risk', 'acceptance_expiry_date')) {
            $this->addSql('ALTER TABLE risk DROP COLUMN acceptance_expiry_date');
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        $row = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.columns
                WHERE table_schema = DATABASE()
                  AND table_name = :t
                  AND column_name = :c',
            ['t' => $table, 'c' => $column],
        );
        return ((int) $row) > 0;
    }
}
