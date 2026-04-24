<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;
use JsonException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use ZipArchive;

/**
 * Pre-flight cleaner for potentially malformed backup files.
 *
 * The service operates in two modes:
 *  - analyze()  — non-destructive: reads the file, validates structure, returns a RepairReport
 *  - repair()   — reads, cleans, writes a structurally valid cleaned backup to $outputPath
 *
 * What the tool fixes:
 *  - Metadata keys missing or of wrong type (sane defaults are filled in).
 *  - SHA-256 integrity drift (recomputed on cleaned data).
 *  - Entity data values that are not arrays (entire entry replaced with empty array).
 *  - Rows inside entity arrays that are not arrays (dropped).
 *  - Rows that are empty arrays (dropped — nothing to restore).
 *  - Rows whose `id` field is explicitly null (dropped when Doctrine metadata is available).
 *
 * What the tool does NOT attempt:
 *  - Decrypt encrypted fields.
 *  - Resolve referential-integrity violations.
 *  - Recover from fully-corrupted JSON.
 *  - Merge data from two backups.
 */
class BackupRepairService
{
    /** Backup format version this tool understands. */
    private const string SUPPORTED_FORMAT_VERSION = '2.0';

    /** Metadata keys that must be present for a well-formed backup. */
    private const array REQUIRED_METADATA_KEYS = ['version', 'created_at', 'entities'];

    /** Metadata keys that should be preserved verbatim when re-sealing. */
    private const array PRESERVED_METADATA_KEYS = [
        'scope_type',
        'tenant_scope',
        'schema_version',
        'encryption',
        'app_version',
        'php_version',
        'symfony_version',
        'doctrine_version',
        'files_included',
        'file_count',
        'skipped_global_entities',
    ];

    public function __construct(
        private readonly ?EntityManagerInterface $entityManager = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    // ------------------------------------------------------------------
    // Public API
    // ------------------------------------------------------------------

    /**
     * Non-destructive analysis of a backup file.
     *
     * Reads the file, validates structure, and returns a report without
     * writing any output.
     *
     * @param string $filePath Absolute or relative path to the backup file.
     */
    public function analyze(string $filePath): RepairReport
    {
        return $this->process($filePath, outputPath: null);
    }

    /**
     * Repair a backup file: clean it and write the result to $outputPath.
     *
     * @param string $filePath   Source backup file (ZIP or raw JSON).
     * @param string $outputPath Destination path for the cleaned backup.
     *                           Must NOT be the same as $filePath.
     */
    public function repair(string $filePath, string $outputPath): RepairReport
    {
        if ($filePath === $outputPath) {
            throw new RuntimeException('Output path must differ from source path to avoid data loss.');
        }

        return $this->process($filePath, outputPath: $outputPath);
    }

    // ------------------------------------------------------------------
    // Core processing logic
    // ------------------------------------------------------------------

    /**
     * Shared implementation for analyze() and repair().
     *
     * When $outputPath is null the method only validates and returns a report.
     * When $outputPath is set the method additionally writes the cleaned backup.
     */
    private function process(string $filePath, ?string $outputPath): RepairReport
    {
        $metadataIssues = [];
        $isZip          = false;
        $zipEntries     = [];   // non-backup.json entries to preserve in repair mode

        // ----------------------------------------------------------------
        // Step 1: Read the file
        // ----------------------------------------------------------------
        if (!file_exists($filePath)) {
            return $this->unrecoverableReport(
                ["File not found: {$filePath}"],
            );
        }

        $isZip = $this->isZipFile($filePath);

        [$rawJson, $zipEntries] = $this->readContent($filePath, $isZip, $metadataIssues);
        if ($rawJson === null) {
            // readContent already pushed the error into $metadataIssues
            return $this->unrecoverableReport($metadataIssues);
        }

        // ----------------------------------------------------------------
        // Step 2: Decode JSON defensively
        // ----------------------------------------------------------------
        try {
            $backup = json_decode($rawJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $metadataIssues[] = 'JSON parse error: ' . $e->getMessage();
            return $this->unrecoverableReport($metadataIssues);
        }

        if (!is_array($backup)) {
            $metadataIssues[] = 'Root element is not a JSON object — unrecoverable.';
            return $this->unrecoverableReport($metadataIssues);
        }

        // ----------------------------------------------------------------
        // Step 3: Validate top-level metadata
        // ----------------------------------------------------------------
        $metadata = $backup['metadata'] ?? [];
        if (!is_array($metadata)) {
            $metadataIssues[] = 'metadata key is not an object — using empty metadata.';
            $metadata = [];
        }

        foreach (self::REQUIRED_METADATA_KEYS as $key) {
            if (!array_key_exists($key, $metadata)) {
                $metadataIssues[] = "metadata.{$key} is missing.";
            }
        }

        // Version check (informational — we do not abort on version mismatch)
        $storedVersion = (string) ($metadata['version'] ?? '');
        if ($storedVersion !== '' && $storedVersion !== self::SUPPORTED_FORMAT_VERSION) {
            $metadataIssues[] = sprintf(
                'version mismatch: backup is %s, tool supports %s.',
                $storedVersion,
                self::SUPPORTED_FORMAT_VERSION,
            );
        }

        // SHA-256 integrity check (informational — we recompute on repair)
        $storedSha256 = (string) ($metadata['sha256'] ?? '');
        if ($storedSha256 !== '') {
            $dataSection  = $backup['data'] ?? [];
            $actualSha256 = hash('sha256', (string) json_encode($dataSection));
            if (!hash_equals($storedSha256, $actualSha256)) {
                $metadataIssues[] = sprintf(
                    'sha256 mismatch: stored=%s actual=%s (integrity drift detected).',
                    $storedSha256,
                    $actualSha256,
                );
            }
        }

        // ----------------------------------------------------------------
        // Step 4: Validate the data section
        // ----------------------------------------------------------------
        $dataSection = $backup['data'] ?? null;
        if (!is_array($dataSection)) {
            $metadataIssues[] = 'data key is missing or not an object — entire backup is unrecoverable.';
            return $this->unrecoverableReport($metadataIssues);
        }

        [$cleanData, $perEntity] = $this->validateAndCleanData($dataSection);

        // ----------------------------------------------------------------
        // Step 5: Build the report
        // ----------------------------------------------------------------
        $totalRows     = 0;
        $recoveredRows = 0;
        $lostRows      = 0;

        foreach ($perEntity as $stats) {
            $totalRows     += $stats['total'];
            $recoveredRows += $stats['recovered'];
            $lostRows      += $stats['lost'];
        }

        $recomputedSha256 = null;

        // ----------------------------------------------------------------
        // Step 6: Write cleaned backup (repair mode only)
        // ----------------------------------------------------------------
        if ($outputPath !== null) {
            $recomputedSha256 = hash('sha256', (string) json_encode($cleanData));

            // Build repaired metadata
            $repairedMetadata = $this->buildRepairedMetadata(
                $metadata,
                $recomputedSha256,
                $metadataIssues,
                $lostRows,
                $perEntity,
            );

            $cleanBackup = [
                'metadata'   => $repairedMetadata,
                'data'       => $cleanData,
                'statistics' => $this->buildStatistics($cleanData),
            ];

            $this->writeOutput($cleanBackup, $outputPath, $isZip, $zipEntries);
        }

        return new RepairReport(
            totalEntities:    count($perEntity),
            totalRows:        $totalRows,
            recoveredRows:    $recoveredRows,
            lostRows:         $lostRows,
            perEntity:        $perEntity,
            metadataIssues:   $metadataIssues,
            recomputedSha256: $recomputedSha256,
            isRecoverable:    $recoveredRows > 0,
        );
    }

    // ------------------------------------------------------------------
    // Data validation helpers
    // ------------------------------------------------------------------

    /**
     * Validate and clean the data section of a backup.
     *
     * Returns [$cleanData, $perEntityStats].
     *
     * @param array<string, mixed> $dataSection Raw backup['data']
     * @return array{0: array<string, list<array<string, mixed>>>, 1: array<string, array{total: int, recovered: int, lost: int, issues: list<string>}>}
     */
    private function validateAndCleanData(array $dataSection): array
    {
        $cleanData = [];
        $perEntity = [];

        foreach ($dataSection as $entityName => $rows) {
            $shortName = (string) $entityName;
            $issues    = [];
            $total     = 0;
            $recovered = 0;
            $lost      = 0;
            $cleanRows = [];

            if (!is_array($rows)) {
                $issues[] = "Entity data is not an array (got " . gettype($rows) . ") — all rows dropped.";
                $perEntity[$shortName] = [
                    'total'     => 0,
                    'recovered' => 0,
                    'lost'      => 0,
                    'issues'    => $issues,
                ];
                $cleanData[$shortName] = [];
                continue;
            }

            // Load Doctrine id-field names when EntityManager is available
            $idFields = $this->resolveIdFields($shortName);

            foreach ($rows as $index => $row) {
                $total++;

                // Row must be a non-empty array
                if (!is_array($row) || $row === []) {
                    $lost++;
                    $issues[] = sprintf(
                        'Row #%d is not a non-empty array (got %s) — dropped.',
                        $index,
                        is_array($row) ? 'empty array' : gettype($row),
                    );
                    continue;
                }

                // Drop rows with explicit null id fields
                $nullIdDropped = false;
                foreach ($idFields as $idField) {
                    if (array_key_exists($idField, $row) && $row[$idField] === null) {
                        $lost++;
                        $issues[] = sprintf(
                            'Row #%d has %s=null — dropped (Doctrine id field cannot be null).',
                            $index,
                            $idField,
                        );
                        $nullIdDropped = true;
                        break;
                    }
                }

                if ($nullIdDropped) {
                    continue;
                }

                // Row passes all checks
                $recovered++;
                $cleanRows[] = $row;
            }

            if ($issues !== []) {
                $this->logger->info('BackupRepairService: entity issues found', [
                    'entity' => $shortName,
                    'lost'   => $lost,
                    'issues' => $issues,
                ]);
            }

            $perEntity[$shortName] = [
                'total'     => $total,
                'recovered' => $recovered,
                'lost'      => $lost,
                'issues'    => $issues,
            ];
            $cleanData[$shortName] = $cleanRows;
        }

        return [$cleanData, $perEntity];
    }

    /**
     * Attempt to resolve Doctrine identifier field names for a short entity name.
     *
     * Returns an empty array when the EntityManager is unavailable or the class
     * is not registered (so the id-null check is simply skipped).
     *
     * @return list<string>
     */
    private function resolveIdFields(string $shortName): array
    {
        if ($this->entityManager === null) {
            return [];
        }

        $className = 'App\\Entity\\' . $shortName;
        if (!class_exists($className)) {
            return [];
        }

        try {
            return $this->entityManager
                ->getClassMetadata($className)
                ->getIdentifierFieldNames();
        } catch (\Throwable) {
            // Entity not registered with Doctrine — skip id check
            return [];
        }
    }

    // ------------------------------------------------------------------
    // Metadata helpers
    // ------------------------------------------------------------------

    /**
     * Build a clean metadata block for the repaired backup.
     *
     * Preserves well-known keys from the original, overrides sha256 and
     * version, and appends repair-specific annotations.
     *
     * @param array<string, mixed>                                                                $original
     * @param list<string>                                                                        $metadataIssues
     * @param array<string, array{total: int, recovered: int, lost: int, issues: list<string>}>  $perEntity
     * @return array<string, mixed>
     */
    private function buildRepairedMetadata(
        array $original,
        string $sha256,
        array $metadataIssues,
        int $lostRows,
        array $perEntity,
    ): array {
        $repaired = [
            'version'    => self::SUPPORTED_FORMAT_VERSION,
            'created_at' => $original['created_at'] ?? 'unknown',
            'entities'   => $original['entities'] ?? null,
        ];

        // Carry forward all preserved optional keys
        foreach (self::PRESERVED_METADATA_KEYS as $key) {
            if (array_key_exists($key, $original)) {
                $repaired[$key] = $original[$key];
            }
        }

        // Seal with fresh hash
        $repaired['sha256'] = $sha256;

        // Repair annotations
        $repaired['repaired_at'] = (new \DateTimeImmutable())->format('c');

        $entityIssueExcerpt = [];
        foreach ($perEntity as $name => $stats) {
            if ($stats['issues'] !== []) {
                foreach (array_slice($stats['issues'], 0, 5) as $msg) {
                    $entityIssueExcerpt[] = "{$name}: {$msg}";
                    if (count($entityIssueExcerpt) >= 5) {
                        break 2;
                    }
                }
            }
        }

        $repaired['repair_report_summary'] = [
            'lost_rows'            => $lostRows,
            'metadata_issues'      => $metadataIssues,
            'first_entity_issues'  => $entityIssueExcerpt,
        ];

        return $repaired;
    }

    /**
     * Build a statistics map (entity name → row count) from clean data.
     *
     * @param array<string, list<array<string, mixed>>> $cleanData
     * @return array<string, int>
     */
    private function buildStatistics(array $cleanData): array
    {
        $statistics = [];
        foreach ($cleanData as $entityName => $rows) {
            $statistics[$entityName] = count($rows);
        }
        return $statistics;
    }

    // ------------------------------------------------------------------
    // I/O helpers
    // ------------------------------------------------------------------

    /**
     * Read the raw JSON content from a backup file (ZIP or plain).
     *
     * When reading from a ZIP, also collects all non-backup.json entries
     * (embedded files) so they can be re-packaged during repair.
     *
     * On failure pushes an error message into $metadataIssues and returns
     * [null, []].
     *
     * @param  list<string>                    $metadataIssues  In/out
     * @return array{0: string|null, 1: array<string, string>} [jsonContent, zipEntries]
     */
    private function readContent(string $filePath, bool $isZip, array &$metadataIssues): array
    {
        if ($isZip) {
            return $this->readFromZip($filePath, $metadataIssues);
        }

        // Plain JSON (possibly gzip-compressed)
        if (str_ends_with(strtolower($filePath), '.gz')) {
            if (!extension_loaded('zlib')) {
                $metadataIssues[] = 'Cannot decompress .gz backup: ext-zlib extension not available.';
                return [null, []];
            }
            $content = @gzdecode((string) file_get_contents($filePath));
            if ($content === false) {
                $metadataIssues[] = 'Failed to gzip-decompress the file.';
                return [null, []];
            }
            return [$content, []];
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            $metadataIssues[] = 'Failed to read file contents.';
            return [null, []];
        }

        return [$content, []];
    }

    /**
     * Extract backup.json from a ZIP and collect auxiliary file entries.
     *
     * @param  list<string>        $metadataIssues  In/out
     * @return array{0: string|null, 1: array<string, string>} [jsonContent, zipEntryContents]
     */
    private function readFromZip(string $filePath, array &$metadataIssues): array
    {
        if (!class_exists(ZipArchive::class)) {
            $metadataIssues[] = 'ZipArchive extension is not available — cannot read ZIP backup.';
            return [null, []];
        }

        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) {
            $metadataIssues[] = "Failed to open ZIP archive: {$filePath}";
            return [null, []];
        }

        $jsonContent = $zip->getFromName('backup.json');
        if ($jsonContent === false) {
            $zip->close();
            $metadataIssues[] = 'ZIP archive does not contain backup.json — unrecoverable.';
            return [null, []];
        }

        // Collect auxiliary file entries (everything under files/) as raw bytes
        $zipEntries = [];
        for ($i = 0; $i < $zip->count(); $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === 'backup.json' || $name === false) {
                continue;
            }
            $data = $zip->getFromIndex($i);
            if ($data !== false) {
                $zipEntries[(string) $name] = $data;
            }
        }

        $zip->close();

        return [$jsonContent, $zipEntries];
    }

    /**
     * Serialise and write the cleaned backup to $outputPath.
     *
     * Writes a ZIP when the original source was a ZIP (preserving embedded
     * auxiliary files); otherwise writes plain JSON.
     *
     * @param array<string, mixed>  $cleanBackup
     * @param array<string, string> $zipEntries   Entry name → raw bytes (from original ZIP)
     */
    private function writeOutput(
        array $cleanBackup,
        string $outputPath,
        bool $isZip,
        array $zipEntries,
    ): void {
        $dir = dirname($outputPath);
        if ($dir !== '' && !is_dir($dir) && !mkdir($dir, 0755, true)) {
            throw new RuntimeException("Cannot create output directory: {$dir}");
        }

        $json = json_encode($cleanBackup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Failed to JSON-encode cleaned backup: ' . json_last_error_msg());
        }

        if ($isZip) {
            $this->writeZipOutput($outputPath, $json, $zipEntries);
        } else {
            if (file_put_contents($outputPath, $json) === false) {
                throw new RuntimeException("Failed to write cleaned backup to: {$outputPath}");
            }
        }

        $this->logger->info('BackupRepairService: wrote cleaned backup', [
            'output' => $outputPath,
            'format' => $isZip ? 'zip' : 'json',
        ]);
    }

    /**
     * Write cleaned backup.json + preserved auxiliary entries into a new ZIP.
     *
     * @param array<string, string> $zipEntries  name → raw bytes
     */
    private function writeZipOutput(string $outputPath, string $json, array $zipEntries): void
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('ZipArchive extension is not available — cannot write ZIP output.');
        }

        $zip = new ZipArchive();
        if ($zip->open($outputPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Could not create output ZIP archive: {$outputPath}");
        }

        $zip->addFromString('backup.json', $json);

        foreach ($zipEntries as $entryName => $data) {
            // Safety: skip any entry that tries to escape expected paths
            if (str_contains($entryName, '..') || str_starts_with($entryName, '/')) {
                $this->logger->warning('BackupRepairService: skipping unsafe ZIP entry', [
                    'entry' => $entryName,
                ]);
                continue;
            }
            $zip->addFromString($entryName, $data);
        }

        $zip->close();
    }

    // ------------------------------------------------------------------
    // Magic-byte detection (identical to BackupService)
    // ------------------------------------------------------------------

    private function isZipFile(string $filepath): bool
    {
        $handle = @fopen($filepath, 'rb');
        if ($handle === false) {
            return false;
        }
        $header = fread($handle, 4);
        fclose($handle);
        return $header !== false && $header === "PK\x03\x04";
    }

    // ------------------------------------------------------------------
    // Factory helpers
    // ------------------------------------------------------------------

    /**
     * Produce an unrecoverable RepairReport with only metadata issues set.
     *
     * @param list<string> $metadataIssues
     */
    private function unrecoverableReport(array $metadataIssues): RepairReport
    {
        return new RepairReport(
            totalEntities:    0,
            totalRows:        0,
            recoveredRows:    0,
            lostRows:         0,
            perEntity:        [],
            metadataIssues:   $metadataIssues,
            recomputedSha256: null,
            isRecoverable:    false,
        );
    }
}
