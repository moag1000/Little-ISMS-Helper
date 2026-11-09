<?php

declare(strict_types=1);

namespace App\Service\Backup;

use Composer\InstalledVersions;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Version stamps written into a backup's metadata (application, schema, Doctrine).
 */
final class BackupVersionInfo
{
    /** Application version from composer.json. */
    public static function application(string $projectDir): string
    {
        $composerFile = $projectDir . '/composer.json';
        if (file_exists($composerFile)) {
            $composer = json_decode((string) file_get_contents($composerFile), true);

            return is_array($composer) ? (string) ($composer['version'] ?? 'unknown') : 'unknown';
        }

        return 'unknown';
    }

    /**
     * The latest applied Doctrine migration version, read via DBAL (no ORM overhead).
     */
    public static function schema(EntityManagerInterface $entityManager): string
    {
        try {
            $row = $entityManager->getConnection()->executeQuery(
                'SELECT version FROM doctrine_migration_versions ORDER BY executed_at DESC LIMIT 1'
            )->fetchAssociative();
            if ($row !== false && isset($row['version'])) {
                // Strip the namespace prefix for readability: keep only the timestamp part
                return preg_replace('/^.*\\\\/', '', (string) $row['version']) ?? (string) $row['version'];
            }
        } catch (\Exception) {
            // Table may not exist in test environments or on fresh installs
        }

        return 'unknown';
    }

    public static function doctrine(): string
    {
        try {
            if (class_exists(InstalledVersions::class)) {
                $packages = InstalledVersions::getAllRawData()[0]['versions'] ?? [];

                return $packages['doctrine/orm']['version'] ?? 'unknown';
            }
        } catch (\Exception) {
            // Fallback if Composer runtime API is not available
        }

        return 'unknown';
    }
}
