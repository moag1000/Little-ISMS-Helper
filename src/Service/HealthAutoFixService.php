<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Service for automatic health check fixes
 */
class HealthAutoFixService
{
    private Filesystem $filesystem;
    private LoggerInterface $logger;

    public function __construct(
        private readonly string $projectDir,
        private readonly string $cacheDir,
        private readonly string $logsDir,
        LoggerInterface $logger
    ) {
        $this->filesystem = new Filesystem();
        $this->logger = $logger;
    }

    /**
     * Fix cache directory permissions
     */
    public function fixCachePermissions(): array
    {
        try {
            $this->logger->info('Attempting to fix cache directory permissions', [
                'directory' => $this->cacheDir,
            ]);

            // Clear cache first
            $this->clearCache();

            // Fix permissions
            if (!is_writable($this->cacheDir)) {
                @chmod($this->cacheDir, 0775);

                // Check if it worked
                if (is_writable($this->cacheDir)) {
                    $this->logger->info('Cache directory permissions fixed successfully');
                    return [
                        'success' => true,
                        'message' => 'Cache directory permissions fixed successfully',
                    ];
                }
            }

            return [
                'success' => false,
                'message' => 'Cache directory is not writable. Please check permissions manually.',
            ];
        } catch (\Exception $e) {
            $this->logger->error('Failed to fix cache permissions', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Fix log directory permissions
     */
    public function fixLogPermissions(): array
    {
        try {
            $this->logger->info('Attempting to fix log directory permissions', [
                'directory' => $this->logsDir,
            ]);

            if (!is_writable($this->logsDir)) {
                @chmod($this->logsDir, 0775);

                // Check if it worked
                if (is_writable($this->logsDir)) {
                    $this->logger->info('Log directory permissions fixed successfully');
                    return [
                        'success' => true,
                        'message' => 'Log directory permissions fixed successfully',
                    ];
                }
            }

            return [
                'success' => false,
                'message' => 'Log directory is not writable. Please check permissions manually.',
            ];
        } catch (\Exception $e) {
            $this->logger->error('Failed to fix log permissions', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Clear cache to free up disk space
     */
    public function clearCache(): array
    {
        try {
            $this->logger->info('Clearing application cache');

            // Get size before
            $sizeBefore = $this->getDirectorySize($this->cacheDir);

            // Remove cache files (but keep the directory structure)
            $cacheFiles = glob($this->cacheDir . '/*');
            foreach ($cacheFiles as $file) {
                if (is_file($file)) {
                    @unlink($file);
                } elseif (is_dir($file) && basename($file) !== '.' && basename($file) !== '..') {
                    $this->filesystem->remove($file);
                }
            }

            // Get size after
            $sizeAfter = $this->getDirectorySize($this->cacheDir);
            $freedSpace = $sizeBefore - $sizeAfter;

            $this->logger->info('Cache cleared successfully', [
                'freed_space' => $this->formatBytes($freedSpace),
            ]);

            return [
                'success' => true,
                'message' => 'Cache cleared successfully. Freed: ' . $this->formatBytes($freedSpace),
            ];
        } catch (\Exception $e) {
            $this->logger->error('Failed to clear cache', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Clean old log files to free up disk space
     */
    public function cleanOldLogs(int $daysToKeep = 30): array
    {
        try {
            $this->logger->info('Cleaning old log files', [
                'days_to_keep' => $daysToKeep,
            ]);

            $sizeBefore = $this->getDirectorySize($this->logsDir);
            $deletedCount = 0;
            $cutoffTime = time() - ($daysToKeep * 86400);

            $logFiles = glob($this->logsDir . '/*.log');
            foreach ($logFiles as $file) {
                if (is_file($file) && filemtime($file) < $cutoffTime) {
                    @unlink($file);
                    $deletedCount++;
                }
            }

            $sizeAfter = $this->getDirectorySize($this->logsDir);
            $freedSpace = $sizeBefore - $sizeAfter;

            $this->logger->info('Old logs cleaned successfully', [
                'deleted_files' => $deletedCount,
                'freed_space' => $this->formatBytes($freedSpace),
            ]);

            return [
                'success' => true,
                'message' => "Cleaned $deletedCount old log files. Freed: " . $this->formatBytes($freedSpace),
            ];
        } catch (\Exception $e) {
            $this->logger->error('Failed to clean old logs', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Rotate current log files (compress and archive)
     */
    public function rotateLogs(): array
    {
        try {
            $this->logger->info('Rotating log files');

            $rotatedCount = 0;
            $freedSpace = 0;

            $logFiles = glob($this->logsDir . '/*.log');
            foreach ($logFiles as $file) {
                if (is_file($file) && filesize($file) > 10 * 1024 * 1024) { // > 10MB
                    $sizeBefore = filesize($file);
                    $archiveName = $file . '.' . date('Y-m-d_His') . '.gz';

                    // Compress the file
                    if ($this->compressFile($file, $archiveName)) {
                        // Clear the original log file
                        file_put_contents($file, '');
                        $rotatedCount++;
                        $freedSpace += $sizeBefore;
                    }
                }
            }

            $this->logger->info('Logs rotated successfully', [
                'rotated_files' => $rotatedCount,
                'freed_space' => $this->formatBytes($freedSpace),
            ]);

            return [
                'success' => true,
                'message' => "Rotated $rotatedCount log files. Freed: " . $this->formatBytes($freedSpace),
            ];
        } catch (\Exception $e) {
            $this->logger->error('Failed to rotate logs', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Optimize disk space (clear cache + clean old logs)
     */
    public function optimizeDiskSpace(): array
    {
        try {
            $results = [];

            // Clear cache
            $cacheResult = $this->clearCache();
            $results[] = $cacheResult['message'];

            // Clean old logs
            $logsResult = $this->cleanOldLogs(30);
            $results[] = $logsResult['message'];

            // Rotate large logs
            $rotateResult = $this->rotateLogs();
            $results[] = $rotateResult['message'];

            return [
                'success' => true,
                'message' => 'Disk space optimized successfully',
                'details' => $results,
            ];
        } catch (\Exception $e) {
            $this->logger->error('Failed to optimize disk space', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Get directory size in bytes
     */
    private function getDirectorySize(string $directory): int
    {
        $size = 0;

        if (!is_dir($directory)) {
            return 0;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if ($file->isFile()) {
                $size += $file->getSize();
            }
        }

        return $size;
    }

    /**
     * Compress a file using gzip
     */
    private function compressFile(string $source, string $destination): bool
    {
        try {
            $sourceHandle = fopen($source, 'rb');
            $destHandle = gzopen($destination, 'wb9');

            if (!$sourceHandle || !$destHandle) {
                return false;
            }

            while (!feof($sourceHandle)) {
                gzwrite($destHandle, fread($sourceHandle, 8192));
            }

            fclose($sourceHandle);
            gzclose($destHandle);

            return file_exists($destination);
        } catch (\Exception $e) {
            $this->logger->error('Failed to compress file', [
                'source' => $source,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Format bytes to human-readable format
     */
    private function formatBytes(int|float $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= (1 << (10 * $pow));

        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}
