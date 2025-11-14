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

            if (!is_dir($this->logsDir) || !is_readable($this->logsDir)) {
                return [
                    'success' => false,
                    'message' => 'Logs directory is not accessible',
                ];
            }

            $sizeBefore = $this->getDirectorySize($this->logsDir);
            $deletedCount = 0;
            $cutoffTime = time() - ($daysToKeep * 86400);

            $logFiles = glob($this->logsDir . '/*.log');
            if ($logFiles === false) {
                return [
                    'success' => false,
                    'message' => 'Failed to read logs directory',
                ];
            }

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

            if (!is_dir($this->logsDir) || !is_readable($this->logsDir)) {
                return [
                    'success' => false,
                    'message' => 'Logs directory is not accessible',
                ];
            }

            $rotatedCount = 0;
            $freedSpace = 0;

            $logFiles = glob($this->logsDir . '/*.log');
            if ($logFiles === false) {
                return [
                    'success' => false,
                    'message' => 'Failed to read logs directory',
                ];
            }

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
     * Fix file permissions for var/ directory
     */
    public function fixVarPermissions(): array
    {
        try {
            $varDir = $this->projectDir . '/var';

            $this->logger->info('Attempting to fix var/ directory permissions', [
                'directory' => $varDir,
            ]);

            if (!is_writable($varDir)) {
                @chmod($varDir, 0775);

                // Recursively fix permissions for subdirectories
                $this->fixDirectoryPermissionsRecursive($varDir);

                if (is_writable($varDir)) {
                    $this->logger->info('var/ directory permissions fixed successfully');
                    return [
                        'success' => true,
                        'message' => 'var/ directory permissions fixed successfully',
                    ];
                }
            }

            return [
                'success' => false,
                'message' => 'var/ directory is not writable. Please check permissions manually.',
            ];
        } catch (\Exception $e) {
            $this->logger->error('Failed to fix var/ permissions', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Fix file permissions for uploads directory
     */
    public function fixUploadsPermissions(): array
    {
        try {
            $uploadsDir = $this->projectDir . '/public/uploads';

            $this->logger->info('Attempting to fix uploads/ directory permissions', [
                'directory' => $uploadsDir,
            ]);

            if (!is_dir($uploadsDir)) {
                // Create uploads directory if it doesn't exist
                $this->filesystem->mkdir($uploadsDir, 0775);

                $this->logger->info('uploads/ directory created successfully');
                return [
                    'success' => true,
                    'message' => 'uploads/ directory created successfully',
                ];
            }

            if (!is_writable($uploadsDir)) {
                @chmod($uploadsDir, 0775);

                if (is_writable($uploadsDir)) {
                    $this->logger->info('uploads/ directory permissions fixed successfully');
                    return [
                        'success' => true,
                        'message' => 'uploads/ directory permissions fixed successfully',
                    ];
                }
            }

            return [
                'success' => false,
                'message' => 'uploads/ directory is not writable. Please check permissions manually.',
            ];
        } catch (\Exception $e) {
            $this->logger->error('Failed to fix uploads/ permissions', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Fix session directory permissions
     */
    public function fixSessionPermissions(): array
    {
        try {
            $sessionSavePath = session_save_path();
            if (empty($sessionSavePath)) {
                $sessionSavePath = sys_get_temp_dir();
            }

            $this->logger->info('Attempting to fix session directory permissions', [
                'directory' => $sessionSavePath,
            ]);

            if (!is_writable($sessionSavePath)) {
                @chmod($sessionSavePath, 0775);

                if (is_writable($sessionSavePath)) {
                    $this->logger->info('Session directory permissions fixed successfully');
                    return [
                        'success' => true,
                        'message' => 'Session directory permissions fixed successfully',
                    ];
                }
            }

            return [
                'success' => false,
                'message' => 'Session directory is not writable. Please check permissions manually.',
            ];
        } catch (\Exception $e) {
            $this->logger->error('Failed to fix session permissions', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Clear uploads directory (old files)
     */
    public function clearOldUploads(int $daysToKeep = 90): array
    {
        try {
            $uploadsDir = $this->projectDir . '/public/uploads';

            if (!is_dir($uploadsDir)) {
                return [
                    'success' => false,
                    'message' => 'Uploads directory does not exist',
                ];
            }

            if (!is_readable($uploadsDir)) {
                return [
                    'success' => false,
                    'message' => 'Uploads directory is not readable',
                ];
            }

            $this->logger->info('Cleaning old uploads', [
                'days_to_keep' => $daysToKeep,
            ]);

            $sizeBefore = $this->getDirectorySize($uploadsDir);
            $deletedCount = 0;
            $cutoffTime = time() - ($daysToKeep * 86400);

            $files = glob($uploadsDir . '/*');
            if ($files === false) {
                return [
                    'success' => false,
                    'message' => 'Failed to read uploads directory',
                ];
            }

            foreach ($files as $file) {
                if (is_file($file) && filemtime($file) < $cutoffTime) {
                    @unlink($file);
                    $deletedCount++;
                }
            }

            $sizeAfter = $this->getDirectorySize($uploadsDir);
            $freedSpace = $sizeBefore - $sizeAfter;

            $this->logger->info('Old uploads cleaned successfully', [
                'deleted_files' => $deletedCount,
                'freed_space' => $this->formatBytes($freedSpace),
            ]);

            return [
                'success' => true,
                'message' => "Cleaned $deletedCount old uploads. Freed: " . $this->formatBytes($freedSpace),
            ];
        } catch (\Exception $e) {
            $this->logger->error('Failed to clean old uploads', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Run composer install (with retry)
     */
    public function runComposerInstall(): array
    {
        try {
            $this->logger->info('Running composer install');

            $composerBin = $this->findComposerBinary();
            if (!$composerBin) {
                return [
                    'success' => false,
                    'message' => 'Composer binary not found. Please install composer manually.',
                ];
            }

            // Validate composer binary path to prevent command injection
            if (!is_executable($composerBin)) {
                return [
                    'success' => false,
                    'message' => 'Composer binary is not executable.',
                ];
            }

            $output = [];
            $returnCode = 0;

            // Use escapeshellarg for security
            $projectDir = escapeshellarg($this->projectDir);
            $composerBin = escapeshellarg($composerBin);

            exec("cd $projectDir && $composerBin install --no-interaction --optimize-autoloader 2>&1", $output, $returnCode);

            if ($returnCode === 0) {
                $this->logger->info('Composer install completed successfully');
                return [
                    'success' => true,
                    'message' => 'Composer dependencies installed successfully',
                ];
            }

            return [
                'success' => false,
                'message' => 'Composer install failed. Check logs for details.',
            ];
        } catch (\Exception $e) {
            $this->logger->error('Failed to run composer install', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Fix directory permissions recursively
     */
    private function fixDirectoryPermissionsRecursive(string $directory): void
    {
        try {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($items as $item) {
                if ($item->isDir()) {
                    @chmod($item->getPathname(), 0775);
                } else {
                    @chmod($item->getPathname(), 0664);
                }
            }
        } catch (\Exception $e) {
            $this->logger->warning('Failed to fix some permissions recursively', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Find composer binary
     */
    private function findComposerBinary(): ?string
    {
        // Check common locations
        $locations = [
            '/usr/local/bin/composer',
            '/usr/bin/composer',
            $this->projectDir . '/composer.phar',
        ];

        foreach ($locations as $location) {
            if (file_exists($location) && is_executable($location)) {
                return $location;
            }
        }

        // Try to find in PATH
        $which = shell_exec('which composer 2>/dev/null');
        if ($which) {
            return trim($which);
        }

        return null;
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
