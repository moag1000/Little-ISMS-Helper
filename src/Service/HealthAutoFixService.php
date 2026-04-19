<?php

namespace App\Service;

use Exception;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;
use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * Service for automatic health check fixes.
 *
 * Every public fix-method routes its result through the AuditLogger
 * (Consultant-Review A3 / ISB-MINOR-3). Log retention, cache wipes and
 * composer-install are A.5.28 / A.8.19-relevant events and must survive
 * monolog rotation — hence the dedicated entity_type "HealthAutoFix"
 * and an explicit action namespace "admin.health_fix.*".
 */
class HealthAutoFixService
{
    private readonly Filesystem $filesystem;

    public function __construct(
        private readonly string $projectDir,
        private readonly string $cacheDir,
        private readonly string $logsDir,
        private readonly LoggerInterface $logger,
        private readonly ?AuditLogger $auditLogger = null,
    ) {
        $this->filesystem = new Filesystem();
    }

    /**
     * Audit sink for every fix-method. Entity id is always null (no concrete
     * row); result is written as-is so the auditor sees exactly the return
     * payload the UI received. Separate from monolog-based $this->logger,
     * because monolog files rotate away.
     *
     * Failures are still logged (newValues.success === false). Intentionally
     * swallows any logging exception — a broken audit pipeline must not
     * cascade into a failed admin action.
     */
    private function audit(string $action, array $result): void
    {
        if (!$this->auditLogger instanceof AuditLogger) {
            return;
        }
        try {
            $this->auditLogger->logCustom(
                'admin.health_fix.' . $action,
                'HealthAutoFix',
                null,
                null,
                $result,
                sprintf(
                    'HealthAutoFix %s: %s',
                    $action,
                    ($result['success'] ?? false) ? 'success' : 'failure',
                ),
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to write audit entry for health-fix action', [
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Fix cache directory permissions
     */
    public function fixCachePermissions(): array
    {
        $result = $this->doFixCachePermissions();
        $this->audit('fix_cache_permissions', $result);
        return $result;
    }

    private function doFixCachePermissions(): array
    {
        try {
            $this->logger->info('Attempting to fix cache directory permissions', [
                'directory' => $this->cacheDir,
            ]);

            // Ensure cache directory exists
            if (!is_dir($this->cacheDir)) {
                $this->logger->info('Cache directory does not exist, creating it', [
                    'directory' => $this->cacheDir,
                ]);
                try {
                    $this->filesystem->mkdir($this->cacheDir, 0775);
                } catch (Exception $e) {
                    $this->logger->error('Failed to create cache directory', [
                        'error' => $e->getMessage(),
                    ]);
                    return [
                        'success' => false,
                        'message' => 'Failed to create cache directory: ' . $e->getMessage(),
                    ];
                }
            }

            // Check if already writable
            if (is_writable($this->cacheDir)) {
                $this->logger->info('Cache directory is already writable');
                return [
                    'success' => true,
                    'message' => 'Cache directory is already writable',
                ];
            }

            // Try to fix directory permissions
            @chmod($this->cacheDir, 0775);

            // Fix permissions recursively for all subdirectories and files
            $this->fixDirectoryPermissionsRecursive($this->cacheDir);

            // Check if it worked
            if (is_writable($this->cacheDir)) {
                $this->logger->info('Cache directory permissions fixed successfully');
                return [
                    'success' => true,
                    'message' => 'Cache directory permissions fixed successfully',
                ];
            }

            // If still not writable, log detailed information
            $logContext = [
                'directory' => $this->cacheDir,
                'exists' => is_dir($this->cacheDir),
                'readable' => is_readable($this->cacheDir),
                'writable' => is_writable($this->cacheDir),
                'permissions' => is_dir($this->cacheDir) ? substr(sprintf('%o', fileperms($this->cacheDir)), -4) : 'N/A',
            ];

            // Add POSIX info if available (not available on Windows)
            if (function_exists('posix_getpwuid') && function_exists('posix_getgrgid') && function_exists('posix_geteuid')) {
                if (is_dir($this->cacheDir)) {
                    $ownerInfo = @posix_getpwuid(@fileowner($this->cacheDir));
                    $groupInfo = @posix_getgrgid(@filegroup($this->cacheDir));
                    $logContext['owner'] = $ownerInfo['name'] ?? 'unknown';
                    $logContext['group'] = $groupInfo['name'] ?? 'unknown';
                }
                $currentUserInfo = @posix_getpwuid(@posix_geteuid());
                $logContext['current_user'] = $currentUserInfo['name'] ?? 'unknown';
            }

            $this->logger->warning('Cache directory is still not writable after permission fix attempt', $logContext);

            return [
                'success' => false,
                'message' => 'Cache directory permissions could not be automatically fixed. The web server user may not have sufficient privileges.',
            ];
        } catch (Exception $e) {
            $this->logger->error('Failed to fix cache permissions', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
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
        $result = $this->doFixLogPermissions();
        $this->audit('fix_log_permissions', $result);
        return $result;
    }

    private function doFixLogPermissions(): array
    {
        try {
            $this->logger->info('Attempting to fix log directory permissions', [
                'directory' => $this->logsDir,
            ]);

            // Ensure log directory exists
            if (!is_dir($this->logsDir)) {
                $this->logger->info('Log directory does not exist, creating it', [
                    'directory' => $this->logsDir,
                ]);
                try {
                    $this->filesystem->mkdir($this->logsDir, 0775);
                } catch (Exception $e) {
                    $this->logger->error('Failed to create log directory', [
                        'error' => $e->getMessage(),
                    ]);
                    return [
                        'success' => false,
                        'message' => 'Failed to create log directory: ' . $e->getMessage(),
                    ];
                }
            }

            // Check if already writable
            if (is_writable($this->logsDir)) {
                $this->logger->info('Log directory is already writable');
                return [
                    'success' => true,
                    'message' => 'Log directory is already writable',
                ];
            }

            // Try to fix directory permissions
            @chmod($this->logsDir, 0775);

            // Fix permissions recursively for all subdirectories and files
            $this->fixDirectoryPermissionsRecursive($this->logsDir);

            // Check if it worked
            if (is_writable($this->logsDir)) {
                $this->logger->info('Log directory permissions fixed successfully');
                return [
                    'success' => true,
                    'message' => 'Log directory permissions fixed successfully',
                ];
            }

            // If still not writable, log detailed information
            $this->logger->warning('Log directory is still not writable after permission fix attempt', [
                'directory' => $this->logsDir,
                'exists' => is_dir($this->logsDir),
                'readable' => is_readable($this->logsDir),
                'writable' => is_writable($this->logsDir),
                'permissions' => is_dir($this->logsDir) ? substr(sprintf('%o', fileperms($this->logsDir)), -4) : 'N/A',
            ]);

            return [
                'success' => false,
                'message' => 'Log directory permissions could not be automatically fixed. The web server user may not have sufficient privileges.',
            ];
        } catch (Exception $e) {
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
        $result = $this->doClearCache();
        $this->audit('clear_cache', $result);
        return $result;
    }

    private function doClearCache(): array
    {
        try {
            $this->logger->info('Clearing application cache');

            // Get size before
            $sizeBefore = $this->getDirectorySize($this->cacheDir);

            // Remove cache files (but keep the directory structure)
            $cacheFiles = glob($this->cacheDir . '/*');
            foreach ($cacheFiles as $cacheFile) {
                if (is_file($cacheFile)) {
                    @unlink($cacheFile);
                } elseif (is_dir($cacheFile) && basename($cacheFile) !== '.' && basename($cacheFile) !== '..') {
                    $this->filesystem->remove($cacheFile);
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
        } catch (Exception $e) {
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
     * Clean old log files to free up disk space.
     *
     * ISB MINOR-3 / A.5.28: log deletion is an evidence-handling event and
     * must itself be in the audit trail. The audit entry captures deletedCount
     * and freed_space, so an auditor can reconcile disappearing rotated logs.
     */
    public function cleanOldLogs(int $daysToKeep = 30): array
    {
        $result = $this->doCleanOldLogs($daysToKeep);
        // Include the days_to_keep input in the audit payload — the call could
        // have been triggered with a non-default retention by the operator.
        $result['days_to_keep'] = $daysToKeep;
        $this->audit('clean_old_logs', $result);
        return $result;
    }

    private function doCleanOldLogs(int $daysToKeep = 30): array
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

            foreach ($logFiles as $logFile) {
                if (is_file($logFile) && filemtime($logFile) < $cutoffTime) {
                    @unlink($logFile);
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
        } catch (Exception $e) {
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
        $result = $this->doRotateLogs();
        $this->audit('rotate_logs', $result);
        return $result;
    }

    private function doRotateLogs(): array
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

            foreach ($logFiles as $logFile) {
                if (is_file($logFile) && filesize($logFile) > 10 * 1024 * 1024) { // > 10MB
                    $sizeBefore = filesize($logFile);
                    $archiveName = $logFile . '.' . date('Y-m-d_His') . '.gz';

                    // Compress the file
                    if ($this->compressFile($logFile, $archiveName)) {
                        // Clear the original log file
                        file_put_contents($logFile, '');
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
        } catch (Exception $e) {
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

            // Clear cache (child call already audits via clear_cache)
            $cacheResult = $this->clearCache();
            $results[] = $cacheResult['message'];

            // Clean old logs (child call audits via clean_old_logs)
            $logsResult = $this->cleanOldLogs(30);
            $results[] = $logsResult['message'];

            // Rotate large logs (child call audits via rotate_logs)
            $rotateResult = $this->rotateLogs();
            $results[] = $rotateResult['message'];

            $result = [
                'success' => true,
                'message' => 'Disk space optimized successfully',
                'details' => $results,
            ];
            $this->audit('optimize_disk_space', $result);
            return $result;
        } catch (Exception $e) {
            $this->logger->error('Failed to optimize disk space', [
                'error' => $e->getMessage(),
            ]);

            $result = [
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
            ];
            $this->audit('optimize_disk_space', $result);
            return $result;
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

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)
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
        } catch (Exception $e) {
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
        $result = $this->doFixVarPermissions();
        $this->audit('fix_var_permissions', $result);
        return $result;
    }

    private function doFixVarPermissions(): array
    {
        try {
            $varDir = $this->projectDir . '/var';

            $this->logger->info('Attempting to fix var/ directory permissions', [
                'directory' => $varDir,
            ]);

            // Ensure var directory exists
            if (!is_dir($varDir)) {
                $this->logger->info('var/ directory does not exist, creating it', [
                    'directory' => $varDir,
                ]);
                try {
                    $this->filesystem->mkdir($varDir, 0775);
                } catch (Exception $e) {
                    $this->logger->error('Failed to create var/ directory', [
                        'error' => $e->getMessage(),
                    ]);
                    return [
                        'success' => false,
                        'message' => 'Failed to create var/ directory: ' . $e->getMessage(),
                    ];
                }
            }

            // Check if already writable
            if (is_writable($varDir)) {
                // Still fix permissions recursively to ensure subdirectories are also writable
                $this->fixDirectoryPermissionsRecursive($varDir);

                $this->logger->info('var/ directory permissions fixed successfully');
                return [
                    'success' => true,
                    'message' => 'var/ directory permissions fixed successfully',
                ];
            }

            // Try to fix directory permissions
            @chmod($varDir, 0775);

            // Recursively fix permissions for subdirectories
            $this->fixDirectoryPermissionsRecursive($varDir);

            // Check if it worked
            if (is_writable($varDir)) {
                $this->logger->info('var/ directory permissions fixed successfully');
                return [
                    'success' => true,
                    'message' => 'var/ directory permissions fixed successfully',
                ];
            }

            // If still not writable, log detailed information
            $this->logger->warning('var/ directory is still not writable after permission fix attempt', [
                'directory' => $varDir,
                'exists' => is_dir($varDir),
                'readable' => is_readable($varDir),
                'writable' => is_writable($varDir),
                'permissions' => is_dir($varDir) ? substr(sprintf('%o', fileperms($varDir)), -4) : 'N/A',
            ]);

            return [
                'success' => false,
                'message' => 'var/ directory permissions could not be automatically fixed. The web server user may not have sufficient privileges.',
            ];
        } catch (Exception $e) {
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
        $result = $this->doFixUploadsPermissions();
        $this->audit('fix_uploads_permissions', $result);
        return $result;
    }

    private function doFixUploadsPermissions(): array
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
        } catch (Exception $e) {
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
        $result = $this->doFixSessionPermissions();
        $this->audit('fix_session_permissions', $result);
        return $result;
    }

    private function doFixSessionPermissions(): array
    {
        try {
            $sessionSavePath = session_save_path();
            if (in_array($sessionSavePath, ['', '0', false], true)) {
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
        } catch (Exception $e) {
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
        $result = $this->doClearOldUploads($daysToKeep);
        $result['days_to_keep'] = $daysToKeep;
        $this->audit('clear_old_uploads', $result);
        return $result;
    }

    private function doClearOldUploads(int $daysToKeep = 90): array
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
        } catch (Exception $e) {
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
     * Run composer install (with retry).
     *
     * Consultant-Review MINOR-4 / ISO 27001 A.8.19 (Installation of software):
     * stdout + stderr are captured and persisted into the audit log so the
     * question "which packages were installed on DATE by USER" is
     * answerable without monolog-file access. The output is truncated by
     * AuditLogger::sanitizeValues() if it exceeds 1000 chars — the tail
     * is usually the relevant "Nothing to install/update" or failure line.
     */
    public function runComposerInstall(): array
    {
        $result = $this->doRunComposerInstall();
        $this->audit('run_composer_install', $result);
        return $result;
    }

    private function doRunComposerInstall(): array
    {
        try {
            $this->logger->info('Running composer install');

            $composerBin = $this->findComposerBinary();
            if (!$composerBin) {
                return [
                    'success' => false,
                    'message' => 'Composer binary not found. Please install composer manually.',
                    'output' => '',
                    'stderr' => '',
                    'exit_code' => null,
                ];
            }

            // Validate composer binary path to prevent command injection
            if (!is_executable($composerBin)) {
                return [
                    'success' => false,
                    'message' => 'Composer binary is not executable.',
                    'output' => '',
                    'stderr' => '',
                    'exit_code' => null,
                ];
            }

            // Use Symfony Process so stdout/stderr are captured separately and
            // we don't rely on shell-quoting correctness (MINOR-4).
            $process = new Process(
                [$composerBin, 'install', '--no-interaction', '--optimize-autoloader'],
                $this->projectDir,
            );
            $process->setTimeout(600);
            $process->run();

            $stdout = $process->getOutput();
            $stderr = $process->getErrorOutput();
            $exitCode = $process->getExitCode();

            if ($exitCode === 0) {
                $this->logger->info('Composer install completed successfully', [
                    'output_length' => strlen($stdout),
                ]);
                return [
                    'success' => true,
                    'message' => 'Composer dependencies installed successfully',
                    'output' => $stdout,
                    'stderr' => $stderr,
                    'exit_code' => $exitCode,
                ];
            }

            $this->logger->warning('Composer install failed', [
                'exit_code' => $exitCode,
                'stderr' => $stderr,
            ]);
            return [
                'success' => false,
                'message' => 'Composer install failed. Check logs for details.',
                'output' => $stdout,
                'stderr' => $stderr,
                'exit_code' => $exitCode,
            ];
        } catch (Exception $e) {
            $this->logger->error('Failed to run composer install', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
                'output' => '',
                'stderr' => $e->getMessage(),
                'exit_code' => null,
            ];
        }
    }

    /**
     * Fix directory permissions recursively
     */
    private function fixDirectoryPermissionsRecursive(string $directory): void
    {
        try {
            $items = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($items as $item) {
                if ($item->isDir()) {
                    @chmod($item->getPathname(), 0775);
                } else {
                    @chmod($item->getPathname(), 0664);
                }
            }
        } catch (Exception $e) {
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
