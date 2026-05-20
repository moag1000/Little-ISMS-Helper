<?php

declare(strict_types=1);

namespace App\Service\Evidence;

use SplFileInfo;

/**
 * F4 Evidence-Versioning — SHA-256 streaming hash calculator.
 *
 * Calculates SHA-256 digests in a streaming fashion so that large
 * evidence files (PDF, XLSX, etc.) do not exhaust PHP memory.
 * Reads the file in 8 KiB chunks.
 */
class ContentHashCalculator
{
    private const int CHUNK_SIZE = 8192; // 8 KiB

    /**
     * Calculate SHA-256 digest of a file by path.
     *
     * @throws RuntimeException when the file cannot be opened or read.
     */
    public function calculateFromPath(string $filePath): string
    {
        if (!is_readable($filePath)) {
            throw new \App\Exception\Io\IoException(sprintf(
                'ContentHashCalculator: cannot read file "%s".',
                $filePath,
            ));
        }

        $ctx = hash_init('sha256');
        $fh = fopen($filePath, 'rb');
        if ($fh === false) {
            throw new \App\Exception\Io\IoException(sprintf(
                'ContentHashCalculator: failed to open "%s".',
                $filePath,
            ));
        }

        try {
            while (!feof($fh)) {
                $chunk = fread($fh, self::CHUNK_SIZE);
                if ($chunk === false) {
                    throw new \App\Exception\Io\IoException(sprintf(
                        'ContentHashCalculator: read error on "%s".',
                        $filePath,
                    ));
                }
                hash_update($ctx, $chunk);
            }
        } finally {
            fclose($fh);
        }

        return hash_final($ctx);
    }

    /**
     * Calculate SHA-256 digest of an SplFileInfo object.
     */
    public function calculateFromFile(SplFileInfo $file): string
    {
        return $this->calculateFromPath($file->getPathname());
    }

    /**
     * Calculate SHA-256 digest of an in-memory string (for small payloads / testing).
     */
    public function calculateFromString(string $content): string
    {
        return hash('sha256', $content);
    }
}
