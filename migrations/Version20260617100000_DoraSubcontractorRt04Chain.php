<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Bucket-6 close (DORA RoI) — RT_04 subcontractor-chain entity.
 *
 * Closes the last deferred ESA RoI taxonomy element (RT_04) by introducing a
 * dedicated `dora_subcontractor` table for 4th- and 5th-party providers that
 * primary ICT suppliers rely on (DORA Art. 28(2)). The XBRL exporter walks
 * this tree recursively to emit RT_04 detail rows.
 *
 * Columns:
 *  - id                          BIGINT AUTO_INCREMENT PRIMARY KEY
 *  - tenant_id                   FK → tenant (ON DELETE CASCADE)
 *  - parent_supplier_id          FK → supplier (ON DELETE CASCADE) — required
 *  - parent_subcontractor_id     FK → dora_subcontractor (ON DELETE SET NULL) — chain link
 *  - name                        VARCHAR(255) NOT NULL
 *  - lei_code                    VARCHAR(20)  NULL  (ISO 17442)
 *  - country                     CHAR(2)      NULL  (ISO 3166-1 alpha-2)
 *  - service_description         TEXT         NULL
 *  - tier                        INT          NOT NULL  (2-5)
 *  - criticality                 VARCHAR(20)  NOT NULL DEFAULT 'standard'
 *  - substitutability            VARCHAR(20)  NOT NULL DEFAULT 'medium'
 *  - created_at                  DATETIME     NOT NULL
 *  - updated_at                  DATETIME     NULL
 *
 * `isTransactional()=false` per CLAUDE.md pitfall #6 — MySQL `CREATE TABLE`
 * commits implicitly and would invalidate the Doctrine SAVEPOINT in a
 * multi-migration run.
 */
final class Version20260617100000_DoraSubcontractorRt04Chain extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Bucket-6 close: add dora_subcontractor table (RT_04 subcontractor-chain) for DORA Art. 28(2) sub-outsourcing reporting.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE dora_subcontractor ('
            . ' id INT AUTO_INCREMENT NOT NULL,'
            . ' tenant_id INT DEFAULT NULL,'
            . ' parent_supplier_id INT NOT NULL,'
            . ' parent_subcontractor_id INT DEFAULT NULL,'
            . ' name VARCHAR(255) NOT NULL,'
            . ' lei_code VARCHAR(20) DEFAULT NULL,'
            . ' country VARCHAR(2) DEFAULT NULL,'
            . ' service_description LONGTEXT DEFAULT NULL,'
            . ' tier INT NOT NULL DEFAULT 2,'
            . " criticality VARCHAR(20) NOT NULL DEFAULT 'standard',"
            . " substitutability VARCHAR(20) NOT NULL DEFAULT 'medium',"
            . ' created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\','
            . ' updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\','
            . ' INDEX idx_dora_subcontractor_tenant (tenant_id),'
            . ' INDEX idx_dora_subcontractor_parent_supplier (parent_supplier_id),'
            . ' INDEX idx_dora_subcontractor_parent_sub (parent_subcontractor_id),'
            . ' INDEX idx_dora_subcontractor_criticality (criticality),'
            . ' PRIMARY KEY (id)'
            . ') DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB'
        );

        $this->addSql(
            'ALTER TABLE dora_subcontractor '
            . 'ADD CONSTRAINT fk_dora_subcontractor_tenant '
            . 'FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE'
        );

        $this->addSql(
            'ALTER TABLE dora_subcontractor '
            . 'ADD CONSTRAINT fk_dora_subcontractor_parent_supplier '
            . 'FOREIGN KEY (parent_supplier_id) REFERENCES supplier (id) ON DELETE CASCADE'
        );

        $this->addSql(
            'ALTER TABLE dora_subcontractor '
            . 'ADD CONSTRAINT fk_dora_subcontractor_parent_subcontractor '
            . 'FOREIGN KEY (parent_subcontractor_id) REFERENCES dora_subcontractor (id) ON DELETE SET NULL'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE dora_subcontractor DROP FOREIGN KEY fk_dora_subcontractor_parent_subcontractor');
        $this->addSql('ALTER TABLE dora_subcontractor DROP FOREIGN KEY fk_dora_subcontractor_parent_supplier');
        $this->addSql('ALTER TABLE dora_subcontractor DROP FOREIGN KEY fk_dora_subcontractor_tenant');
        $this->addSql('DROP TABLE dora_subcontractor');
    }
}
