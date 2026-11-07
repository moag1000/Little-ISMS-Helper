<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\Supplier\SubcontractorChainRows;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * P5 "eine Liste statt zwei" for suppliers:
 *  - the AVV (GDPR Art. 28) is one flag: gdpr_av_contract_signed/_date are folded into
 *    has_dpa / dpa_signed_date (a signed AVV in either place counts), then dropped.
 *  - the JSON column subcontractor_chain becomes dora_subcontractor rows (the list the
 *    DORA RoI XBRL export already reads); names the supplier already has are kept once.
 */
final class Version20261108000000_SupplierOneDpaFlagAndSubcontractorRows extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Supplier: one AVV flag; subcontractor chain JSON → dora_subcontractor rows';
    }

    public function isTransactional(): bool
    {
        // ALTER TABLE commits implicitly in MySQL (invalidates the SAVEPOINT).
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->connection->executeStatement(
            'UPDATE supplier SET has_dpa = 1 WHERE gdpr_av_contract_signed = 1'
        );
        $this->connection->executeStatement(
            'UPDATE supplier SET dpa_signed_date = gdpr_av_contract_date
             WHERE dpa_signed_date IS NULL AND gdpr_av_contract_date IS NOT NULL'
        );

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, tenant_id, subcontractor_chain FROM supplier WHERE subcontractor_chain IS NOT NULL'
        );
        foreach ($rows as $row) {
            $chain = json_decode((string) $row['subcontractor_chain'], true);
            if (is_array($chain) && $chain !== []) {
                SubcontractorChainRows::insert(
                    $this->connection,
                    (int) $row['id'],
                    $row['tenant_id'] !== null ? (int) $row['tenant_id'] : null,
                    $chain,
                    $now,
                );
            }
        }

        $this->addSql('ALTER TABLE supplier DROP COLUMN gdpr_av_contract_signed, DROP COLUMN gdpr_av_contract_date, DROP COLUMN subcontractor_chain');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE supplier ADD gdpr_av_contract_signed TINYINT(1) DEFAULT 0 NOT NULL, ADD gdpr_av_contract_date DATE DEFAULT NULL, ADD subcontractor_chain JSON DEFAULT NULL');
        $this->addSql('UPDATE supplier SET gdpr_av_contract_signed = has_dpa, gdpr_av_contract_date = dpa_signed_date');
    }
}
