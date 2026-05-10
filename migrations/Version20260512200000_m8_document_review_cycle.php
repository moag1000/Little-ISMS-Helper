<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * V3 W2-LB-8 — Document review-cycle auto-set on approval.
 *
 * Adds:
 *  - `next_review_date` (DATE, nullable) — auto-set on status='approved'.
 *  - `review_interval_months` (INT, default 12) — cadence for review.
 *
 * Plain ALTER (CLAUDE.md pitfall #6). isTransactional=false.
 */
final class Version20260512200000_m8_document_review_cycle extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'V3 W2-LB-8: Document review-cycle (next_review_date + review_interval_months).';
    }

    public function up(Schema $schema): void
    {
        if (!$this->columnExists('document', 'next_review_date')) {
            $this->addSql('ALTER TABLE document ADD next_review_date DATE DEFAULT NULL');
        }
        if (!$this->columnExists('document', 'review_interval_months')) {
            $this->addSql('ALTER TABLE document ADD review_interval_months INT DEFAULT 12 NOT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        if ($this->columnExists('document', 'review_interval_months')) {
            $this->addSql('ALTER TABLE document DROP COLUMN review_interval_months');
        }
        if ($this->columnExists('document', 'next_review_date')) {
            $this->addSql('ALTER TABLE document DROP COLUMN next_review_date');
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        $count = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c',
            ['t' => $table, 'c' => $column],
        );
        return (int) $count > 0;
    }
}
