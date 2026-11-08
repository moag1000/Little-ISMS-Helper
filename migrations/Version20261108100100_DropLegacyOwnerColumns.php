<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Drops the 14 legacy free-text owner columns (step 2 of 2). The previous
 * migration turned every filled text into a user or person reference, so the
 * columns hold nothing the records do not already name.
 */
final class Version20261108100100_DropLegacyOwnerColumns extends AbstractMigration
{
    /** table => [legacy text column, type for down()] */
    private const array COLUMNS = [
        'asset' => ['owner', 'VARCHAR(100)'],
        'control' => ['responsible_person', 'VARCHAR(100)'],
        'business_process' => ['process_owner', 'VARCHAR(100)'],
        'business_continuity_plan' => ['plan_owner', 'VARCHAR(100)'],
        'bc_exercise' => ['facilitator', 'VARCHAR(100)'],
        'incident' => ['reported_by', 'VARCHAR(100)'],
        'internal_audit' => ['lead_auditor', 'VARCHAR(100)'],
        'training' => ['trainer', 'VARCHAR(100)'],
        'ismsobjective' => ['responsible_person', 'VARCHAR(100)'],
        'supplier' => ['contact_person', 'VARCHAR(100)'],
        'interested_party' => ['contact_person', 'VARCHAR(100)'],
        'location' => ['responsible_person', 'VARCHAR(255)'],
        'vulnerabilities' => ['responsible_person', 'VARCHAR(100)'],
        'patches' => ['responsible_person', 'VARCHAR(100)'],
    ];

    public function getDescription(): string
    {
        return 'Drop the legacy free-text owner columns (owners are user or person references)';
    }

    public function isTransactional(): bool
    {
        // ALTER TABLE commits implicitly in MySQL.
        return false;
    }

    public function up(Schema $schema): void
    {
        foreach (self::COLUMNS as $table => [$column]) {
            $this->addSql(sprintf('ALTER TABLE %s DROP COLUMN %s', $table, $column));
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::COLUMNS as $table => [$column, $type]) {
            $this->addSql(sprintf('ALTER TABLE %s ADD %s %s DEFAULT NULL', $table, $column, $type));
        }
    }
}
