<?php

declare(strict_types=1);

namespace App\Service\DataIntegrity;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

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
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir = '',
    ) {
    }

    /**
     * Scans public/uploads/ and the evidence areas below var/uploads/ and returns files
     * not referenced by any entity.
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
        if ($this->projectDir === '') {
            return $empty;
        }
        $uploadsDir = $this->projectDir . '/public/uploads';
        // Evidence (documents, training materials) lives below var/uploads/<area> since S7;
        // those areas are scanned too (not the import scratch folders next to them).
        // Stored values are '/uploads/<area>/<file>' in both trees. [dir to walk, base].
        $roots = [[$uploadsDir, $uploadsDir]];
        foreach (\App\Service\Upload\ProtectedUploads::AREAS as $area) {
            $roots[] = [$this->projectDir . '/var/uploads/' . $area, $this->projectDir . '/var/uploads'];
        }
        $roots = array_values(array_filter($roots, static fn (array $r): bool => is_dir($r[0])));
        if ($roots === []) {
            return $empty;
        }

        // 1. Collect referenced file paths from entity columns.
        $referenced = $this->collectReferencedUploadPaths();

        // 2. Walk the uploads trees and flag every regular file that is
        //    NOT in the referenced-set. Skip .gitkeep and dot-files.
        $orphans = [];
        $scanned = 0;
        foreach ($roots as [$root, $base]) {
            try {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::LEAVES_ONLY,
                );
            } catch (\Throwable) {
                continue;
            }

            $basePath = rtrim((string) realpath($base), '/');
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
                if ($realPath === '' || !str_starts_with($realPath, $basePath . '/')) {
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
        // Training materials are a JSON list on the training (filename per entry); the
        // stored form is '/uploads/training-materials/<file>' (ProtectedUploads). Without
        // them every material was reported — and quarantined — as an orphan.
        try {
            $rows = $this->entityManager->createQueryBuilder()
                ->select('t.materialFiles AS files')
                ->from(\App\Entity\Training::class, 't')
                ->where('t.materialFiles IS NOT NULL')
                ->getQuery()
                ->getArrayResult();
        } catch (\Throwable) {
            $rows = [];
        }

        return $set + self::trainingMaterialPaths($rows);
    }

    /**
     * Stored forms of the training materials listed in the given rows ({files: json list}).
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, true>
     */
    public static function trainingMaterialPaths(array $rows): array
    {
        $set = [];
        foreach ($rows as $row) {
            $files = $row['files'] ?? null;
            if (is_string($files)) {
                $files = json_decode($files, true);
            }
            foreach (is_array($files) ? $files : [] as $file) {
                $name = is_array($file) ? basename((string) ($file['filename'] ?? '')) : '';
                if ($name !== '') {
                    $set['/uploads/' . \App\Service\Upload\ProtectedUploads::TRAINING_MATERIALS . '/' . $name] = true;
                    $set['uploads/' . \App\Service\Upload\ProtectedUploads::TRAINING_MATERIALS . '/' . $name] = true;
                }
            }
        }

        return $set;
    }
}
