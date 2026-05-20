<?php

declare(strict_types=1);

namespace App\Service\DataIntegrity;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Scans the uploads/ filesystem tree and detects files that are no longer
 * referenced by any Doctrine entity.
 *
 * Extracted from DataIntegrityService to isolate filesystem-scan concerns.
 * DataIntegrityService delegates findOrphanedUploads() to this helper.
 *
 * @see \App\Service\DataIntegrityService::findOrphanedUploads()
 */
final class UploadOrphanChecker
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ?string $projectDir = null,
    ) {
    }

    /**
     * Scans public/uploads/ and returns files not referenced by any entity.
     *
     * @return array{
     *     files: list<array{path: string, relative: string, size: int, mtime: int}>,
     *     scanned: int,
     *     referenced: int,
     *     uploads_dir: string|null,
     * }
     */
    public function findOrphanedUploads(): array
    {
        $empty = ['files' => [], 'scanned' => 0, 'referenced' => 0, 'uploads_dir' => null];
        if ($this->projectDir === null) {
            return $empty;
        }
        $uploadsDir = $this->projectDir . '/public/uploads';
        if (!is_dir($uploadsDir)) {
            return $empty;
        }

        // 1. Collect referenced file paths from entity columns.
        $referenced = $this->collectReferencedUploadPaths();

        // 2. Walk the uploads/ tree and flag every regular file that is
        //    NOT in the referenced-set. Skip .gitkeep and dot-files.
        $orphans = [];
        $scanned = 0;
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($uploadsDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY,
            );
        } catch (\Throwable) {
            return $empty;
        }

        $basePath = rtrim((string) realpath($uploadsDir), '/');
        foreach ($iterator as $fileInfo) {
            if (!$fileInfo instanceof \SplFileInfo || !$fileInfo->isFile()) {
                continue;
            }
            $name = $fileInfo->getFilename();
            if ($name === '.gitkeep' || str_starts_with($name, '.')) {
                continue;
            }
            $scanned++;

            $realPath = (string) $fileInfo->getRealPath();
            if ($realPath === '' || !str_starts_with($realPath, $basePath)) {
                continue;
            }
            // Build the relative path the way entities store it (with or without leading slash).
            $relative = '/uploads' . substr($realPath, strlen($basePath));
            $relativeNoSlash = ltrim($relative, '/');

            if (isset($referenced[$relative]) || isset($referenced[$relativeNoSlash])) {
                continue;
            }
            // tenants/ and users/ logos can be stored either with or without
            // the `uploads/` prefix — the BackupService comments document this.
            $logoStyle = preg_replace('#^/uploads/#', '', $relative);
            if (is_string($logoStyle) && isset($referenced[$logoStyle])) {
                continue;
            }

            $orphans[] = [
                'path' => $realPath,
                'relative' => $relative,
                'size' => (int) $fileInfo->getSize(),
                'mtime' => (int) $fileInfo->getMTime(),
            ];
        }

        // Cap the response list — typical orphan counts after a few backup
        // restores can reach thousands; the template only needs the top N.
        usort($orphans, static fn(array $a, array $b): int => $b['size'] <=> $a['size']);

        return [
            'files' => array_slice($orphans, 0, 500),
            'scanned' => $scanned,
            'referenced' => count($referenced),
            'uploads_dir' => $uploadsDir,
        ];
    }

    /**
     * Builds the set of file-paths currently referenced by Doctrine entities.
     * Returns an associative array keyed by path (both `/uploads/...` and
     * `uploads/...` styles are present where the entity stores either form).
     *
     * @return array<string, true>
     */
    public function collectReferencedUploadPaths(): array
    {
        $set = [];
        $columnMap = [
            // FQCN => property name
            \App\Entity\Document::class => 'filePath',
            \App\Entity\DocumentVersion::class => 'filePath',
            \App\Entity\Tenant::class => 'logoPath',
            \App\Entity\User::class => 'profilePicture',
        ];

        $factory = $this->entityManager->getMetadataFactory();
        foreach ($columnMap as $fqcn => $property) {
            try {
                $metadata = $factory->getMetadataFor($fqcn);
            } catch (\Throwable) {
                continue;
            }
            if (!$metadata->hasField($property)) {
                continue;
            }
            try {
                $rows = $this->entityManager->createQueryBuilder()
                    ->select('e.' . $property . ' AS path')
                    ->from($fqcn, 'e')
                    ->where('e.' . $property . ' IS NOT NULL')
                    ->getQuery()
                    ->getScalarResult();
            } catch (\Throwable) {
                continue;
            }
            foreach ($rows as $row) {
                $path = (string) ($row['path'] ?? '');
                if ($path === '') {
                    continue;
                }
                $set[$path] = true;
                // Also store the "other" form to be tolerant of mixed storage.
                if (str_starts_with($path, '/')) {
                    $set[ltrim($path, '/')] = true;
                } else {
                    $set['/' . $path] = true;
                }
            }
        }
        return $set;
    }
}
