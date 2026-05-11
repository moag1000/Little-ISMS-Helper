<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Service\Import\Dto\ParsedSpreadsheet;
use ErrorException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Csv as CsvReader;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Psr\Log\LoggerInterface;

/**
 * Parses XLSX and CSV files into a structured ParsedSpreadsheet DTO.
 *
 * Supported formats:
 *   - XLSX / XLS  (via phpoffice/phpspreadsheet)
 *   - CSV         (auto-detects delimiter and BOM encoding)
 *
 * Hard limits:
 *   - MAX_ROWS_HARD_LIMIT: rejects files that exceed this count (configurable later)
 *   - Merged cells are skipped gracefully with a warning logged
 */
final class SpreadsheetParser
{
    public const MAX_ROWS_HARD_LIMIT = 5000;

    /** Delimiters tried during CSV auto-detection (in priority order). */
    private const CSV_DELIMITERS = [',', ';', "\t", '|'];

    /** Number of sample rows used for delimiter detection. */
    private const CSV_SAMPLE_LINES = 5;

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Parse an XLSX or CSV file and return a typed DTO.
     *
     * @param string      $filePath  Absolute path to the spreadsheet file
     * @param string|null $sheetName Sheet name to parse; uses first sheet when null
     *
     * @throws ErrorException when the file cannot be read, the format is unsupported,
     *                        or the row count exceeds MAX_ROWS_HARD_LIMIT
     */
    public function parse(string $filePath, ?string $sheetName = null): ParsedSpreadsheet
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new ErrorException(
                sprintf('SpreadsheetParser: file not found or not readable: %s', $filePath),
            );
        }

        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        return match (true) {
            in_array($extension, ['xlsx', 'xls', 'ods'], true) => $this->parseSpreadsheet($filePath, $sheetName),
            $extension === 'csv' => $this->parseCsv($filePath),
            default => throw new ErrorException(
                sprintf('SpreadsheetParser: unsupported file extension "%s".', $extension),
            ),
        };
    }

    // -------------------------------------------------------------------------
    // XLSX / XLS / ODS
    // -------------------------------------------------------------------------

    private function parseSpreadsheet(string $filePath, ?string $sheetName): ParsedSpreadsheet
    {
        $reader = IOFactory::createReaderForFile($filePath);
        $reader->setReadDataOnly(true);

        /** @var Spreadsheet $spreadsheet */
        $spreadsheet = $reader->load($filePath);

        $worksheet = $sheetName !== null
            ? $spreadsheet->getSheetByName($sheetName)
            : $spreadsheet->getActiveSheet();

        if ($worksheet === null) {
            throw new ErrorException(
                sprintf('SpreadsheetParser: sheet "%s" not found in file.', $sheetName ?? '(active)'),
            );
        }

        return $this->extractFromWorksheet($worksheet);
    }

    private function extractFromWorksheet(Worksheet $worksheet): ParsedSpreadsheet
    {
        $warnings = [];
        $highestRow = $worksheet->getHighestDataRow();
        $highestCol = $worksheet->getHighestDataColumn();

        // Find first non-empty row (header row)
        $headerRowIndex = null;
        for ($row = 1; $row <= $highestRow; $row++) {
            $rowData = $worksheet->rangeToArray('A' . $row . ':' . $highestCol . $row, null, true, false, false);
            $nonEmpty = array_filter($rowData[0], static fn (mixed $v): bool => $v !== null && $v !== '');
            if ($nonEmpty !== []) {
                $headerRowIndex = $row;
                break;
            }
        }

        if ($headerRowIndex === null) {
            return new ParsedSpreadsheet(
                headers: [],
                rows: [],
                warnings: ['Sheet appears to be empty — no non-empty rows found.'],
                sheetName: $worksheet->getTitle(),
                totalRowCount: 0,
            );
        }

        // Extract headers
        $headerRange = $worksheet->rangeToArray(
            'A' . $headerRowIndex . ':' . $highestCol . $headerRowIndex,
            null,
            true,
            false,
            false,
        );
        $headers = array_map(
            static fn (mixed $v): string => (string) ($v ?? ''),
            $headerRange[0],
        );

        // Check row count before loading data
        $dataRowCount = $highestRow - $headerRowIndex;

        if ($dataRowCount > self::MAX_ROWS_HARD_LIMIT) {
            throw new ErrorException(
                sprintf(
                    'SpreadsheetParser: file contains %d data rows, exceeding the hard limit of %d. '
                    . 'Please split the file into smaller batches.',
                    $dataRowCount,
                    self::MAX_ROWS_HARD_LIMIT,
                ),
            );
        }

        // Detect merged cells and collect their coordinates for warning
        $mergedCells = $worksheet->getMergeCells();
        if ($mergedCells !== []) {
            foreach (array_keys($mergedCells) as $mergeRange) {
                $warnings[] = sprintf('Merged cell range "%s" detected — only top-left cell value is used.', $mergeRange);
                $this->logger->warning('SpreadsheetParser: merged cells skipped', ['range' => $mergeRange]);
            }
        }

        // Extract data rows
        $rows = [];
        for ($row = $headerRowIndex + 1; $row <= $highestRow; $row++) {
            $rowRange = $worksheet->rangeToArray(
                'A' . $row . ':' . $highestCol . $row,
                null,
                true,
                false,
                false,
            );
            $rawRow = $rowRange[0];

            // Skip entirely empty rows
            $nonEmpty = array_filter($rawRow, static fn (mixed $v): bool => $v !== null && $v !== '');
            if ($nonEmpty === []) {
                continue;
            }

            $assocRow = [];
            foreach ($headers as $colIndex => $header) {
                $assocRow[$header] = $rawRow[$colIndex] ?? null;
            }
            $rows[] = $assocRow;
        }

        return new ParsedSpreadsheet(
            headers: $headers,
            rows: $rows,
            warnings: $warnings,
            sheetName: $worksheet->getTitle(),
            totalRowCount: count($rows),
        );
    }

    // -------------------------------------------------------------------------
    // CSV
    // -------------------------------------------------------------------------

    private function parseCsv(string $filePath): ParsedSpreadsheet
    {
        $warnings = [];

        // BOM detection and encoding
        $rawContent = file_get_contents($filePath);
        if ($rawContent === false) {
            throw new ErrorException(
                sprintf('SpreadsheetParser: could not read CSV file: %s', $filePath),
            );
        }

        [$content, $encodingNote] = $this->stripBomAndDetectEncoding($rawContent);
        if ($encodingNote !== null) {
            $warnings[] = $encodingNote;
        }

        // Write normalised content to a temp file for the phpspreadsheet CSV reader
        $tmpFile = tempnam(sys_get_temp_dir(), 'spreadsheet_parser_');
        if ($tmpFile === false) {
            throw new ErrorException('SpreadsheetParser: could not create temporary file for CSV parsing.');
        }

        try {
            file_put_contents($tmpFile, $content);

            $delimiter = $this->detectDelimiter($content);
            $this->logger->debug('SpreadsheetParser: detected CSV delimiter', ['delimiter' => $delimiter]);

            $reader = new CsvReader();
            $reader->setDelimiter($delimiter);
            $reader->setReadDataOnly(true);

            $spreadsheet = $reader->load($tmpFile);
            $worksheet = $spreadsheet->getActiveSheet();

            $parsed = $this->extractFromWorksheet($worksheet);

            // Merge any encoding warnings before returning
            $allWarnings = array_merge($warnings, $parsed->warnings);

            return new ParsedSpreadsheet(
                headers: $parsed->headers,
                rows: $parsed->rows,
                warnings: $allWarnings,
                sheetName: 'CSV',
                totalRowCount: $parsed->totalRowCount,
            );
        } finally {
            @unlink($tmpFile);
        }
    }

    /**
     * Strip UTF-8/UTF-16 BOM bytes and return the clean content plus an optional warning.
     *
     * @return array{string, string|null}
     */
    private function stripBomAndDetectEncoding(string $raw): array
    {
        // UTF-8 BOM: EF BB BF
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            return [substr($raw, 3), 'UTF-8 BOM detected and stripped.'];
        }

        // UTF-16 LE BOM: FF FE
        if (str_starts_with($raw, "\xFF\xFE")) {
            $converted = mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16LE');
            return [$converted, 'UTF-16 LE BOM detected — converted to UTF-8.'];
        }

        // UTF-16 BE BOM: FE FF
        if (str_starts_with($raw, "\xFE\xFF")) {
            $converted = mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16BE');
            return [$converted, 'UTF-16 BE BOM detected — converted to UTF-8.'];
        }

        return [$raw, null];
    }

    /**
     * Auto-detect the CSV delimiter by counting occurrences across sample lines.
     */
    private function detectDelimiter(string $content): string
    {
        $lines = array_filter(explode("\n", $content));
        $sampleLines = array_slice(array_values($lines), 0, self::CSV_SAMPLE_LINES);

        if ($sampleLines === []) {
            return ',';
        }

        $scores = [];
        foreach (self::CSV_DELIMITERS as $delimiter) {
            $counts = [];
            foreach ($sampleLines as $line) {
                $counts[] = substr_count($line, $delimiter);
            }
            // Score: consistent column count with most columns wins
            $max = max($counts);
            $min = min($counts);
            $scores[$delimiter] = ($max > 0 && $max === $min) ? $max : ($max > 0 ? $max / 2 : 0);
        }

        arsort($scores);
        $best = array_key_first($scores);

        return $best !== null ? $best : ',';
    }
}
