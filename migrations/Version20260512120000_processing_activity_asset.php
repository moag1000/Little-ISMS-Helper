<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Audit V3 W2-Bug3 — create `processing_activity_asset` join table.
 *
 * Closes the missing M:N relation between {@see \App\Entity\ProcessingActivity}
 * (owning side) and {@see \App\Entity\Asset} (inverse side) that the
 * DPIA-Auto-Suggest listener (W2-H5) needs in order to flag confidential /
 * restricted asset classifications. Previously the listener only had a
 * `method_exists()` defensive that always evaluated false, so the
 * Asset-classification-driven DPIA trigger never fired.
 *
 * Both FKs cascade on delete: removing either side automatically prunes
 * the join row.
 *
 * Idempotent: information_schema check before CREATE TABLE so partial
 * re-runs never fail. Plain `CREATE TABLE IF NOT EXISTS` only — no
 * PREPARE/EXECUTE (CLAUDE.md pitfall #6).
 *
 * `isTransactional() = false`: every CREATE TABLE implicitly commits in
 * MySQL; running >1 DDL migration in a single `migrate` call without
 * this override fails on the SAVEPOINT.
 */
final class Version20260512120000_processing_activity_asset extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Audit V3 W2-Bug3: create processing_activity_asset M:N join table '
            . '(closes Asset-classification-driven DPIA-trigger gap, W2-H5).';
    }

    public function up(Schema $schema): void
    {
        if (!$this->tableExists('processing_activity_asset')) {
            $this->addSql(<<<'SQL'
                CREATE TABLE processing_activity_asset (
                    processing_activity_id INT NOT NULL,
                    asset_id INT NOT NULL,
                    PRIMARY KEY (processing_activity_id, asset_id),
                    KEY idx_paa_processing_activity (processing_activity_id),
                    KEY idx_paa_asset (asset_id),
                    CONSTRAINT fk_paa_processing_activity
                        FOREIGN KEY (processing_activity_id)
                        REFERENCES processing_activity (id)
                        ON DELETE CASCADE,
                    CONSTRAINT fk_paa_asset
                        FOREIGN KEY (asset_id)
                        REFERENCES asset (id)
                        ON DELETE CASCADE
                ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
        }
    }

    public function down(Schema $schema): void
    {
        if ($this->tableExists('processing_activity_asset')) {
            $this->addSql('DROP TABLE processing_activity_asset');
        }
    }

    private function tableExists(string $table): bool
    {
        $row = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.tables
                WHERE table_schema = DATABASE()
                  AND table_name = :t',
            ['t' => $table],
        );
        return ((int) $row) > 0;
    }
}
