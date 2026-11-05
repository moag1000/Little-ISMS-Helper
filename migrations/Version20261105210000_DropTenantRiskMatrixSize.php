<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Drops tenant.risk_matrix_size (#1072). The setting was offered in the
 * tenant compliance settings but never read: risk scoring is a fixed 5×5
 * matrix (App\Risk\RiskMatrixThresholds). Removing it ends a choice that
 * had no effect.
 */
final class Version20261105210000_DropTenantRiskMatrixSize extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop ineffective tenant.risk_matrix_size (risk matrix is fixed 5×5)';
    }

    public function isTransactional(): bool
    {
        // ALTER TABLE commits implicitly in MySQL (invalidates the SAVEPOINT).
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tenant DROP COLUMN risk_matrix_size');
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE tenant ADD risk_matrix_size SMALLINT DEFAULT 5 COMMENT 'Risk matrix size 3, 4, 5'");
    }
}
