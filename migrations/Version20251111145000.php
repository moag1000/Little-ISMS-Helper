<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration: Convert DateTime fields from MUTABLE to IMMUTABLE types
 *
 * This migration updates the Doctrine type annotations for date/datetime fields
 * in ComplianceFramework and ComplianceRequirement entities.
 *
 * IMPORTANT NOTE:
 * This is a PHP-level change only. The database schema does not need modification
 * because both DATETIME_MUTABLE and DATETIME_IMMUTABLE map to the same MySQL DATETIME type,
 * and DATE_MUTABLE and DATE_IMMUTABLE both map to MySQL DATE type.
 *
 * The change affects:
 * - ComplianceFramework: createdAt, updatedAt (DATETIME_MUTABLE → DATETIME_IMMUTABLE)
 * - ComplianceRequirement: targetDate, lastAssessmentDate (DATE_MUTABLE → DATE_IMMUTABLE)
 * - ComplianceRequirement: createdAt, updatedAt (DATETIME_MUTABLE → DATETIME_IMMUTABLE)
 *
 * This fixes the error: "Could not convert PHP value of type DateTimeImmutable to type DateTimeType"
 */
final class Version20251111145000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Convert DateTime fields from MUTABLE to IMMUTABLE types (PHP-level only, no DB changes needed)';
    }

    public function up(Schema $schema): void
    {
        // No database schema changes required
        // The mapping change from DATETIME_MUTABLE to DATETIME_IMMUTABLE is PHP-level only
        // Both types use the same MySQL DATETIME column type

        // This migration is kept for documentation purposes and to track the change
    }

    public function down(Schema $schema): void
    {
        // No database schema changes required
        // The revert would be done at the PHP entity level, not in the database
    }

    public function isTransactional(): bool
    {
        // Since there are no actual SQL changes, this can be non-transactional
        return false;
    }
}
