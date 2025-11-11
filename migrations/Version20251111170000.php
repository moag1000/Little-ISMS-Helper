<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Convert DateTime fields in ComplianceMapping from MUTABLE to IMMUTABLE types
 *
 * This is a PHP-level only change - no database schema changes are required.
 * DATETIME_MUTABLE and DATETIME_IMMUTABLE both map to DATETIME in the database.
 * DATE_MUTABLE and DATE_IMMUTABLE both map to DATE in the database.
 */
final class Version20251111170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Convert ComplianceMapping DateTime fields from MUTABLE to IMMUTABLE types (PHP-level only, no DB changes needed)';
    }

    public function up(Schema $schema): void
    {
        // No database schema changes required
        // The mapping change from DATETIME_MUTABLE to DATETIME_IMMUTABLE is PHP-level only
        // Changed fields:
        // - verificationDate: DATE_MUTABLE -> DATE_IMMUTABLE
        // - createdAt: DATETIME_MUTABLE -> DATETIME_IMMUTABLE
        // - updatedAt: DATETIME_MUTABLE -> DATETIME_IMMUTABLE
    }

    public function down(Schema $schema): void
    {
        // No database schema changes required
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
