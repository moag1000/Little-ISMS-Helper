<?php

declare(strict_types=1);

namespace App\Service\Upload;

use App\Exception\InvalidArgument\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Where uploaded evidence lives: below var/uploads/, outside the web root.
 *
 * Documents (and their versions) and training materials used to sit in public/uploads/,
 * which the web server hands out to anybody who knows or guesses a URL — no login, no
 * organisation check, no audit entry. Now the files are only reachable through the
 * download actions, which ask the voter and are audit-logged (DownloadAuditSubscriber).
 *
 * The stored value on the record does not change ('/uploads/documents/<file>'), so
 * backups, clones and API clients keep working. resolve() maps it to the file on disk and
 * still finds a file that has not been moved yet (public/uploads/…); the migration
 * Version20261108120000 moves them, moveLegacyFiles() does the same on demand.
 *
 * Tenant logos and avatars stay in public/uploads/: they are shown on login and in mails.
 */
final class ProtectedUploads
{
    public const string DOCUMENTS = 'documents';
    public const string TRAINING_MATERIALS = 'training-materials';

    /** Areas below var/uploads/ — and the old folders below public/uploads/ they replace. */
    public const array AREAS = [self::DOCUMENTS, self::TRAINING_MATERIALS];

    /** Old public folder => protected area ('document_versions' was written by ZIP restores). */
    private const array LEGACY_FOLDERS = [
        'documents' => self::DOCUMENTS,
        'document_versions' => self::DOCUMENTS,
        'training-materials' => self::TRAINING_MATERIALS,
    ];

    /** Folders below var/ an absolute stored path may point into (bulk import keeps its source there). */
    private const array ABSOLUTE_ROOTS = ['uploads', 'import-uploads'];

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    /** Absolute directory of an area, created on first use. */
    public function directory(string $area): string
    {
        if (!in_array($area, self::AREAS, true)) {
            // @intentional-assertion: programmer error — areas are the class constants
            throw new InvalidArgumentException(sprintf('Unknown upload area "%s".', $area), 'area');
        }
        $dir = $this->projectDir . '/var/uploads/' . $area;
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }

        return $dir;
    }

    /** The value a record stores for a file of an area: '/uploads/<area>/<file>'. */
    public function storedPath(string $area, string $filename): string
    {
        return '/uploads/' . $area . '/' . basename($filename);
    }

    /**
     * The readable file behind a stored value, or null.
     *
     * Accepts '/uploads/<area>/<file>' (with or without leading slash) and absolute paths
     * below var/uploads/ or var/import-uploads/ (bulk import evidence). Anything else —
     * 'virtual:…', '../', absolute paths elsewhere (var/setup_complete.lock, logs, backups,
     * .env.local) — resolves to null (S19: the value may come from a restored backup).
     */
    public function resolve(?string $storedPath): ?string
    {
        $storedPath = trim((string) $storedPath);
        if ($storedPath === '' || str_contains($storedPath, "\0")) {
            return null;
        }

        if (preg_match('#^/?uploads/([a-z_-]+)/([^/\\\\]+)$#', $storedPath, $m) === 1
            && isset(self::LEGACY_FOLDERS[$m[1]]) && $m[2] !== '.' && $m[2] !== '..'
        ) {
            $candidates = [
                $this->projectDir . '/var/uploads/' . self::LEGACY_FOLDERS[$m[1]] . '/' . $m[2],
                $this->projectDir . '/public/uploads/' . $m[1] . '/' . $m[2],
            ];
            foreach ($candidates as $candidate) {
                if (is_file($candidate) && is_readable($candidate)) {
                    return $candidate;
                }
            }

            return null;
        }

        if (str_starts_with($storedPath, '/')) {
            $real = realpath($storedPath);
            foreach (self::ABSOLUTE_ROOTS as $root) {
                $rootDir = realpath($this->projectDir . '/var/' . $root);
                if ($real !== false && $rootDir !== false && str_starts_with($real, $rootDir . '/') && is_file($real)) {
                    return $real;
                }
            }
        }

        return null;
    }

    /**
     * Files still lying in the old public folders.
     *
     * @return list<string> absolute paths
     */
    public function legacyFiles(): array
    {
        $files = [];
        foreach (array_keys(self::LEGACY_FOLDERS) as $folder) {
            $dir = $this->projectDir . '/public/uploads/' . $folder;
            foreach (is_dir($dir) ? (scandir($dir) ?: []) : [] as $name) {
                if ($name !== '.gitkeep' && !str_starts_with($name, '.') && is_file($dir . '/' . $name)) {
                    $files[] = $dir . '/' . $name;
                }
            }
        }

        return $files;
    }

    /**
     * Moves the files of the old public folders below var/uploads/. A file whose name
     * already exists there stays where it is (counted as kept) — nothing is overwritten.
     *
     * Refused (blocked: true) when public/uploads/ is its own mount but var/ is not —
     * a container that persists only the uploads volume would lose the moved files on
     * the next recreate. The web server rules keep the old folders closed meanwhile.
     *
     * @return array{moved: int, kept: int, failed: int, blocked: bool}
     */
    public function moveLegacyFiles(): array
    {
        $result = ['moved' => 0, 'kept' => 0, 'failed' => 0, 'blocked' => false];
        if ($this->wouldLeavePersistentStorage()) {
            $result['failed'] = count($this->legacyFiles());
            $result['blocked'] = true;

            return $result;
        }
        foreach ($this->legacyFiles() as $path) {
            $folder = basename(dirname($path));
            $target = $this->directory(self::LEGACY_FOLDERS[$folder]) . '/' . basename($path);
            if (file_exists($target)) {
                $result['kept']++;
                continue;
            }
            $moved = @rename($path, $target) || (@copy($path, $target) && @unlink($path));
            $result[$moved ? 'moved' : 'failed']++;
        }

        return $result;
    }

    private function wouldLeavePersistentStorage(): bool
    {
        $device = static function (string $path): ?int {
            $stat = is_dir($path) ? @stat($path) : false;

            return $stat === false ? null : (int) $stat['dev'];
        };
        $uploads = $device($this->projectDir . '/public/uploads');
        $public = $device($this->projectDir . '/public');
        $var = $device($this->projectDir . '/var');
        $root = $device($this->projectDir);

        return $uploads !== null && $uploads !== $public && $var !== null && $var === $root;
    }
}
