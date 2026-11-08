<?php

declare(strict_types=1);

namespace App\Service\Import;

use ErrorException;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Reader\Ods;
use PhpOffice\PhpSpreadsheet\Reader\Xls;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * The one way an uploaded workbook is opened.
 *
 * `IOFactory::createReaderForFile()` picks any reader the content looks like (HTML, XML,
 * SLK, Gnumeric, …) and `load()` inflates the whole file before any row limit applies,
 * so a small zip bomb fills memory and disk. Here:
 *  - the reader type follows the extension and is limited to the caller's list,
 *    and the reader must recognise the content as that type;
 *  - zip-based formats (xlsx, ods) are checked before loading: entry count, total
 *    uncompressed size, compression ratio;
 *  - a read filter keeps only the first rows and columns the caller can use, so the
 *    rest of a huge sheet never becomes cells in memory.
 *
 * Gate: tests/Security/SpreadsheetUploadReaderTest (no IOFactory::createReaderForFile / load in src/).
 */
final class SafeSpreadsheetReader
{
    public const int MAX_FILE_BYTES = 25 * 1024 * 1024;
    public const int MAX_UNCOMPRESSED_BYTES = 200 * 1024 * 1024;
    public const int MAX_ZIP_ENTRIES = 2000;
    public const int MAX_COMPRESSION_RATIO = 100;

    private const array READERS = [
        'xlsx' => Xlsx::class,
        'xls' => Xls::class,
        'ods' => Ods::class,
    ];

    /**
     * @param list<string> $allowedTypes extensions the caller accepts (subset of xlsx, xls, ods)
     * @param int          $maxRows      rows read per sheet; further rows are skipped
     * @param int          $maxColumns   columns read per sheet; further columns are skipped
     *
     * @throws ErrorException when the type is not allowed, the content does not match it,
     *                        or the archive is too large
     */
    public function load(
        string $filePath,
        array $allowedTypes,
        int $maxRows,
        int $maxColumns = 200,
        ?string $type = null,
    ): Spreadsheet {
        $type = strtolower($type ?? pathinfo($filePath, PATHINFO_EXTENSION));
        if (!in_array($type, $allowedTypes, true) || !isset(self::READERS[$type])) {
            throw new ErrorException(sprintf('Unsupported spreadsheet type "%s".', $type));
        }
        $size = @filesize($filePath);
        if ($size === false || $size > self::MAX_FILE_BYTES) {
            throw new ErrorException('Spreadsheet file is too large.');
        }
        if ($type !== 'xls') {
            self::assertArchiveWithinLimits($filePath);
        }

        $class = self::READERS[$type];
        $reader = new $class();
        if (!$reader->canRead($filePath)) {
            throw new ErrorException(sprintf('File is not a valid %s workbook.', $type));
        }
        $reader->setReadDataOnly(true);
        $reader->setReadFilter(self::filter($maxRows, $maxColumns));

        return $reader->load($filePath);
    }

    /**
     * @throws ErrorException when the zip container exceeds the limits
     */
    public static function assertArchiveWithinLimits(string $filePath): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($filePath, \ZipArchive::RDONLY) !== true) {
            throw new ErrorException('Spreadsheet is not a readable zip container.');
        }
        try {
            if ($zip->numFiles > self::MAX_ZIP_ENTRIES) {
                throw new ErrorException('Spreadsheet archive has too many entries.');
            }
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if ($stat === false) {
                    throw new ErrorException('Spreadsheet archive is damaged.');
                }
                $total += (int) $stat['size'];
                $compressed = max(1, (int) $stat['comp_size']);
                if ($total > self::MAX_UNCOMPRESSED_BYTES
                    || ((int) $stat['size'] > 1024 * 1024 && (int) $stat['size'] / $compressed > self::MAX_COMPRESSION_RATIO)
                ) {
                    throw new ErrorException('Spreadsheet archive unpacks to more than the import allows.');
                }
            }
        } finally {
            $zip->close();
        }
    }

    private static function filter(int $maxRows, int $maxColumns): IReadFilter
    {
        return new class ($maxRows, $maxColumns) implements IReadFilter {
            public function __construct(private readonly int $maxRows, private readonly int $maxColumns)
            {
            }

            public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
            {
                return $row <= $this->maxRows
                    && \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($columnAddress) <= $this->maxColumns;
            }
        };
    }
}
