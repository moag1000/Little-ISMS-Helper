<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Junior-ISB-Audit TODO_2026-05-22 §17 — Typed M2M for BusinessProcess
 * upstream/downstream dependencies.
 *
 * Replaces the free-text textareas `dependencies_upstream` /
 * `dependencies_downstream` with proper Many-to-Many join tables that have
 * referential integrity (ON DELETE CASCADE), enable graph visualization and
 * filtered lookups. The legacy text columns stay during the transition;
 * templates / forms read the M2M collections first.
 *
 * Both join tables are self-referential on `business_process(id)`.
 */
final class Version20260613100000_BPDependenciesM2M extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'S14 §17 — Typed M2M tables for BusinessProcess upstream/downstream dependencies.';
    }

    public function isTransactional(): bool
    {
        // DDL — MySQL ALTER/CREATE TABLE commits implicitly, see CLAUDE.md
        // Common Pitfall #6.
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE business_process_dependencies_upstream (
            process_id INT NOT NULL,
            upstream_process_id INT NOT NULL,
            INDEX IDX_BPDU_PROCESS (process_id),
            INDEX IDX_BPDU_UPSTREAM (upstream_process_id),
            PRIMARY KEY (process_id, upstream_process_id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE business_process_dependencies_upstream
            ADD CONSTRAINT FK_BPDU_PROCESS FOREIGN KEY (process_id)
            REFERENCES business_process (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE business_process_dependencies_upstream
            ADD CONSTRAINT FK_BPDU_UPSTREAM FOREIGN KEY (upstream_process_id)
            REFERENCES business_process (id) ON DELETE CASCADE');

        $this->addSql('CREATE TABLE business_process_dependencies_downstream (
            process_id INT NOT NULL,
            downstream_process_id INT NOT NULL,
            INDEX IDX_BPDD_PROCESS (process_id),
            INDEX IDX_BPDD_DOWNSTREAM (downstream_process_id),
            PRIMARY KEY (process_id, downstream_process_id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE business_process_dependencies_downstream
            ADD CONSTRAINT FK_BPDD_PROCESS FOREIGN KEY (process_id)
            REFERENCES business_process (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE business_process_dependencies_downstream
            ADD CONSTRAINT FK_BPDD_DOWNSTREAM FOREIGN KEY (downstream_process_id)
            REFERENCES business_process (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS business_process_dependencies_upstream');
        $this->addSql('DROP TABLE IF EXISTS business_process_dependencies_downstream');
    }
}
