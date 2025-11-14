<?php

namespace App\Controller;

use App\Repository\AuditLogRepository;
use App\Service\HealthAutoFixService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/monitoring')]
class MonitoringController extends AbstractController
{
    #[Route('/health', name: 'monitoring_health', methods: ['GET'])]
    #[IsGranted('MONITORING_VIEW')]
    public function health(Connection $connection): Response
    {
        // Perform health checks
        $healthChecks = [];

        // 1. Database Check
        try {
            $dbStart = microtime(true);
            $connection->executeQuery('SELECT 1');
            $dbTime = round((microtime(true) - $dbStart) * 1000, 2);

            // Get platform class name (DBAL 4.x compatible)
            $platform = $connection->getDatabasePlatform();
            $platformClass = get_class($platform);
            $platformName = substr($platformClass, strrpos($platformClass, '\\') + 1);

            $healthChecks['database'] = [
                'status' => 'healthy',
                'response_time' => $dbTime . ' ms',
                'driver' => $platformName,
            ];
        } catch (\Exception $e) {
            $healthChecks['database'] = [
                'status' => 'error',
                'error' => $e->getMessage(),
            ];
        }

        // 2. Disk Space Check
        $projectDir = $this->getParameter('kernel.project_dir');
        $diskFree = disk_free_space($projectDir);
        $diskTotal = disk_total_space($projectDir);
        $diskUsed = $diskTotal - $diskFree;
        $diskPercentage = round(($diskUsed / $diskTotal) * 100, 2);

        $healthChecks['disk'] = [
            'status' => $diskPercentage > 90 ? 'warning' : 'healthy',
            'free' => $this->formatBytes($diskFree),
            'used' => $this->formatBytes($diskUsed),
            'total' => $this->formatBytes($diskTotal),
            'percentage' => $diskPercentage,
        ];

        // 3. PHP Version & Extensions
        $requiredExtensions = ['pdo', 'pdo_pgsql', 'intl', 'zip', 'gd', 'mbstring', 'xml'];
        $missingExtensions = [];
        $loadedExtensions = [];

        foreach ($requiredExtensions as $ext) {
            if (extension_loaded($ext)) {
                $loadedExtensions[] = $ext;
            } else {
                $missingExtensions[] = $ext;
            }
        }

        $healthChecks['php'] = [
            'status' => empty($missingExtensions) ? 'healthy' : 'error',
            'version' => PHP_VERSION,
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'loaded_extensions' => $loadedExtensions,
            'missing_extensions' => $missingExtensions,
        ];

        // 4. Symfony Version
        $healthChecks['symfony'] = [
            'status' => 'healthy',
            'version' => Kernel::VERSION,
            'environment' => $this->getParameter('kernel.environment'),
            'debug' => $this->getParameter('kernel.debug'),
        ];

        // 5. Cache Check
        $cacheDir = $this->getParameter('kernel.cache_dir');
        $cacheWritable = is_writable($cacheDir);

        $healthChecks['cache'] = [
            'status' => $cacheWritable ? 'healthy' : 'error',
            'directory' => $cacheDir,
            'writable' => $cacheWritable,
        ];

        // 6. Log Directory Check
        $logDir = $this->getParameter('kernel.logs_dir');
        $logWritable = is_writable($logDir);

        $healthChecks['logs'] = [
            'status' => $logWritable ? 'healthy' : 'error',
            'directory' => $logDir,
            'writable' => $logWritable,
        ];

        // 7. File Permissions Check
        $varDir = $projectDir . '/var';
        $publicDir = $projectDir . '/public';
        $uploadsDir = $publicDir . '/uploads';

        $permissionIssues = [];
        if (!is_writable($varDir)) {
            $permissionIssues[] = 'var/ not writable';
        }
        if (is_dir($uploadsDir) && !is_writable($uploadsDir)) {
            $permissionIssues[] = 'public/uploads/ not writable';
        }

        $healthChecks['permissions'] = [
            'status' => empty($permissionIssues) ? 'healthy' : 'error',
            'var_writable' => is_writable($varDir),
            'uploads_writable' => is_dir($uploadsDir) ? is_writable($uploadsDir) : null,
            'issues' => $permissionIssues,
        ];

        // 8. Composer Check
        $composerLock = $projectDir . '/composer.lock';
        $vendorDir = $projectDir . '/vendor';
        $composerUpToDate = false;
        $composerMessage = '';

        if (!file_exists($composerLock)) {
            $composerMessage = 'composer.lock not found';
        } elseif (!is_dir($vendorDir)) {
            $composerMessage = 'vendor/ directory not found - run composer install';
        } else {
            $composerUpToDate = true;
            $composerMessage = 'Dependencies installed';
        }

        $healthChecks['composer'] = [
            'status' => $composerUpToDate ? 'healthy' : 'error',
            'message' => $composerMessage,
            'lock_exists' => file_exists($composerLock),
            'vendor_exists' => is_dir($vendorDir),
        ];

        // 9. Environment Variables Check
        $envFile = $projectDir . '/.env';
        $envLocalFile = $projectDir . '/.env.local';

        $requiredEnvVars = ['APP_ENV', 'APP_SECRET', 'DATABASE_URL'];
        $missingEnvVars = [];

        foreach ($requiredEnvVars as $var) {
            if (empty($_ENV[$var]) && empty($_SERVER[$var])) {
                $missingEnvVars[] = $var;
            }
        }

        $healthChecks['environment'] = [
            'status' => empty($missingEnvVars) ? 'healthy' : 'error',
            'env_file_exists' => file_exists($envFile),
            'env_local_exists' => file_exists($envLocalFile),
            'missing_vars' => $missingEnvVars,
        ];

        // 10. PHP Configuration Check
        $opcacheEnabled = function_exists('opcache_get_status') && opcache_get_status();
        $memoryLimit = ini_get('memory_limit');
        $memoryLimitBytes = $this->convertToBytes($memoryLimit);
        $recommendedMemory = 256 * 1024 * 1024; // 256MB

        $configIssues = [];
        if (!$opcacheEnabled && $this->getParameter('kernel.environment') === 'prod') {
            $configIssues[] = 'OPcache not enabled in production';
        }
        if ($memoryLimitBytes < $recommendedMemory && $memoryLimitBytes !== -1) {
            $configIssues[] = 'Memory limit below recommended 256M';
        }

        $healthChecks['php_config'] = [
            'status' => empty($configIssues) ? 'healthy' : 'warning',
            'opcache_enabled' => $opcacheEnabled,
            'memory_limit' => $memoryLimit,
            'memory_sufficient' => $memoryLimitBytes >= $recommendedMemory || $memoryLimitBytes === -1,
            'issues' => $configIssues,
        ];

        // 11. Session Storage Check
        $sessionSavePath = session_save_path();
        if (empty($sessionSavePath)) {
            $sessionSavePath = sys_get_temp_dir();
        }
        $sessionWritable = is_writable($sessionSavePath);

        $healthChecks['sessions'] = [
            'status' => $sessionWritable ? 'healthy' : 'error',
            'save_path' => $sessionSavePath,
            'writable' => $sessionWritable,
            'handler' => ini_get('session.save_handler'),
        ];

        // 12. Uploads Directory Check
        if (is_dir($uploadsDir)) {
            $uploadsFree = disk_free_space($uploadsDir);
            $uploadsSize = $this->getDirectorySize($uploadsDir);

            $healthChecks['uploads'] = [
                'status' => 'healthy',
                'directory' => $uploadsDir,
                'size' => $this->formatBytes($uploadsSize),
                'free_space' => $this->formatBytes($uploadsFree),
                'writable' => is_writable($uploadsDir),
            ];
        }

        // Calculate overall status
        $overallStatus = 'healthy';
        foreach ($healthChecks as $check) {
            if ($check['status'] === 'error') {
                $overallStatus = 'error';
                break;
            } elseif ($check['status'] === 'warning' && $overallStatus !== 'error') {
                $overallStatus = 'warning';
            }
        }

        return $this->render('monitoring/health.html.twig', [
            'health_checks' => $healthChecks,
            'overall_status' => $overallStatus,
        ]);
    }

    #[Route('/health/json', name: 'monitoring_health_json', methods: ['GET'])]
    #[IsGranted('MONITORING_VIEW')]
    public function healthJson(Connection $connection): JsonResponse
    {
        // Simple health check for monitoring tools
        try {
            $connection->executeQuery('SELECT 1');
            return $this->json([
                'status' => 'healthy',
                'timestamp' => time(),
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'status' => 'error',
                'error' => $e->getMessage(),
                'timestamp' => time(),
            ], 503);
        }
    }

    #[Route('/performance', name: 'monitoring_performance', methods: ['GET'])]
    #[IsGranted('MONITORING_VIEW')]
    public function performance(
        AuditLogRepository $auditLogRepository,
        Request $request
    ): Response {
        // Get performance metrics from audit log (basic implementation)
        // In production, you'd use a proper monitoring solution like New Relic, Datadog, etc.

        $recentLogs = $auditLogRepository->getRecentActivity(24);

        // Calculate statistics
        $totalRequests = count($recentLogs);
        $uniqueUsers = count(array_unique(array_map(fn($log) => $log->getUserName(), $recentLogs)));

        // Group by action
        $actionCounts = [];
        foreach ($recentLogs as $log) {
            $action = $log->getAction();
            if (!isset($actionCounts[$action])) {
                $actionCounts[$action] = 0;
            }
            $actionCounts[$action]++;
        }

        // Sort by count
        arsort($actionCounts);
        $topActions = array_slice($actionCounts, 0, 10, true);

        // Group by entity type
        $entityCounts = [];
        foreach ($recentLogs as $log) {
            $entityType = $log->getEntityType();
            if ($entityType && $entityType !== 'User') { // Skip user login events
                if (!isset($entityCounts[$entityType])) {
                    $entityCounts[$entityType] = 0;
                }
                $entityCounts[$entityType]++;
            }
        }

        // Sort by count
        arsort($entityCounts);
        $topEntities = array_slice($entityCounts, 0, 10, true);

        // Get current memory usage
        $memoryUsage = memory_get_usage(true);
        $memoryPeak = memory_get_peak_usage(true);
        $memoryLimit = ini_get('memory_limit');

        return $this->render('monitoring/performance.html.twig', [
            'total_requests' => $totalRequests,
            'unique_users' => $uniqueUsers,
            'top_actions' => $topActions,
            'top_entities' => $topEntities,
            'memory_usage' => $this->formatBytes($memoryUsage),
            'memory_peak' => $this->formatBytes($memoryPeak),
            'memory_limit' => $memoryLimit,
        ]);
    }

    #[Route('/errors', name: 'monitoring_errors', methods: ['GET'])]
    #[IsGranted('MONITORING_VIEW')]
    public function errors(Request $request): Response
    {
        $logDir = $this->getParameter('kernel.logs_dir');
        $environment = $this->getParameter('kernel.environment');
        $logFile = $logDir . '/' . $environment . '.log';

        $errors = [];
        $errorStats = [];

        if (file_exists($logFile)) {
            $limit = (int) $request->query->get('limit', 100);
            $errors = $this->parseLogFile($logFile, $limit);

            // Calculate statistics
            $errorLevels = [];
            foreach ($errors as $error) {
                $level = $error['level'] ?? 'unknown';
                if (!isset($errorLevels[$level])) {
                    $errorLevels[$level] = 0;
                }
                $errorLevels[$level]++;
            }

            $errorStats = [
                'total' => count($errors),
                'by_level' => $errorLevels,
                'file_size' => $this->formatBytes(filesize($logFile)),
                'last_modified' => date('Y-m-d H:i:s', filemtime($logFile)),
            ];
        }

        return $this->render('monitoring/errors.html.twig', [
            'errors' => $errors,
            'error_stats' => $errorStats,
            'log_file' => $logFile,
            'has_log' => file_exists($logFile),
        ]);
    }

    #[Route('/audit-log', name: 'monitoring_audit_log', methods: ['GET'])]
    #[IsGranted('AUDIT_VIEW')]
    public function auditLog(
        AuditLogRepository $auditLogRepository,
        Request $request
    ): Response {
        // Quick filters
        $filter = $request->query->get('filter', 'all');
        $limit = (int) $request->query->get('limit', 100);

        switch ($filter) {
            case 'today':
                $logs = $auditLogRepository->getRecentActivity(24);
                break;
            case 'week':
                $logs = $auditLogRepository->getRecentActivity(168); // 7 days
                break;
            case 'critical':
                $logs = $auditLogRepository->search([
                    'action' => ['delete', 'destroy', 'remove'],
                    'limit' => $limit,
                ]);
                break;
            default:
                $logs = $auditLogRepository->findAllOrdered($limit);
        }

        $statistics = [
            'total' => $auditLogRepository->countAll(),
            'action_stats' => $auditLogRepository->getActionStatistics(),
            'entity_stats' => $auditLogRepository->getEntityTypeStatistics(),
        ];

        return $this->render('monitoring/audit_log.html.twig', [
            'logs' => $logs,
            'statistics' => $statistics,
            'current_filter' => $filter,
        ]);
    }

    /**
     * Parse log file and extract errors
     */
    private function parseLogFile(string $logFile, int $limit = 100): array
    {
        $errors = [];
        $handle = fopen($logFile, 'r');

        if (!$handle) {
            return [];
        }

        // Read file from the end (to get most recent errors)
        fseek($handle, 0, SEEK_END);
        $pos = ftell($handle);
        $lines = [];

        // Read backwards
        while ($pos > 0 && count($lines) < $limit * 10) { // Read more lines to parse correctly
            $char = '';
            while ($char !== "\n" && $pos > 0) {
                fseek($handle, --$pos);
                $char = fgetc($handle);
            }
            if ($char === "\n") {
                fseek($handle, $pos + 1);
            }
            $line = fgets($handle);
            if ($line !== false && trim($line) !== '') {
                array_unshift($lines, trim($line));
            }
            if ($char !== "\n") {
                break;
            }
        }

        fclose($handle);

        // Parse lines
        foreach ($lines as $line) {
            // Simple regex to match Symfony log format
            // Format: [2024-01-01 12:00:00] app.LEVEL: message {"context":"data"}
            if (preg_match('/\[(.*?)\]\s+(\w+)\.(\w+):\s+(.*)/', $line, $matches)) {
                $errors[] = [
                    'timestamp' => $matches[1],
                    'channel' => $matches[2],
                    'level' => strtoupper($matches[3]),
                    'message' => $matches[4],
                    'raw' => $line,
                ];

                if (count($errors) >= $limit) {
                    break;
                }
            }
        }

        return $errors;
    }

    #[Route('/health/fix/cache', name: 'monitoring_health_fix_cache', methods: ['POST'])]
    #[IsGranted('MONITORING_MANAGE')]
    public function fixCache(HealthAutoFixService $autoFixService): JsonResponse
    {
        $result = $autoFixService->fixCachePermissions();
        return $this->json($result);
    }

    #[Route('/health/fix/logs', name: 'monitoring_health_fix_logs', methods: ['POST'])]
    #[IsGranted('MONITORING_MANAGE')]
    public function fixLogs(HealthAutoFixService $autoFixService): JsonResponse
    {
        $result = $autoFixService->fixLogPermissions();
        return $this->json($result);
    }

    #[Route('/health/fix/clear-cache', name: 'monitoring_health_clear_cache', methods: ['POST'])]
    #[IsGranted('MONITORING_MANAGE')]
    public function clearCache(HealthAutoFixService $autoFixService): JsonResponse
    {
        $result = $autoFixService->clearCache();
        return $this->json($result);
    }

    #[Route('/health/fix/clean-logs', name: 'monitoring_health_clean_logs', methods: ['POST'])]
    #[IsGranted('MONITORING_MANAGE')]
    public function cleanLogs(HealthAutoFixService $autoFixService, Request $request): JsonResponse
    {
        $days = (int) $request->request->get('days', 30);
        $result = $autoFixService->cleanOldLogs($days);
        return $this->json($result);
    }

    #[Route('/health/fix/rotate-logs', name: 'monitoring_health_rotate_logs', methods: ['POST'])]
    #[IsGranted('MONITORING_MANAGE')]
    public function rotateLogs(HealthAutoFixService $autoFixService): JsonResponse
    {
        $result = $autoFixService->rotateLogs();
        return $this->json($result);
    }

    #[Route('/health/fix/optimize-disk', name: 'monitoring_health_optimize_disk', methods: ['POST'])]
    #[IsGranted('MONITORING_MANAGE')]
    public function optimizeDisk(HealthAutoFixService $autoFixService): JsonResponse
    {
        $result = $autoFixService->optimizeDiskSpace();
        return $this->json($result);
    }

    #[Route('/health/fix/var-permissions', name: 'monitoring_health_fix_var', methods: ['POST'])]
    #[IsGranted('MONITORING_MANAGE')]
    public function fixVarPermissions(HealthAutoFixService $autoFixService): JsonResponse
    {
        $result = $autoFixService->fixVarPermissions();
        return $this->json($result);
    }

    #[Route('/health/fix/uploads-permissions', name: 'monitoring_health_fix_uploads', methods: ['POST'])]
    #[IsGranted('MONITORING_MANAGE')]
    public function fixUploadsPermissions(HealthAutoFixService $autoFixService): JsonResponse
    {
        $result = $autoFixService->fixUploadsPermissions();
        return $this->json($result);
    }

    #[Route('/health/fix/session-permissions', name: 'monitoring_health_fix_sessions', methods: ['POST'])]
    #[IsGranted('MONITORING_MANAGE')]
    public function fixSessionPermissions(HealthAutoFixService $autoFixService): JsonResponse
    {
        $result = $autoFixService->fixSessionPermissions();
        return $this->json($result);
    }

    #[Route('/health/fix/clean-uploads', name: 'monitoring_health_clean_uploads', methods: ['POST'])]
    #[IsGranted('MONITORING_MANAGE')]
    public function cleanUploads(HealthAutoFixService $autoFixService, Request $request): JsonResponse
    {
        $days = (int) $request->request->get('days', 90);
        $result = $autoFixService->clearOldUploads($days);
        return $this->json($result);
    }

    #[Route('/health/fix/composer-install', name: 'monitoring_health_composer_install', methods: ['POST'])]
    #[IsGranted('MONITORING_MANAGE')]
    public function composerInstall(HealthAutoFixService $autoFixService): JsonResponse
    {
        $result = $autoFixService->runComposerInstall();
        return $this->json($result);
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

    /**
     * Convert PHP memory notation to bytes
     */
    private function convertToBytes(string $value): int
    {
        $value = trim($value);
        $unit = strtolower($value[strlen($value) - 1]);
        $value = (int) $value;

        switch ($unit) {
            case 'g':
                $value *= 1024 * 1024 * 1024;
                break;
            case 'm':
                $value *= 1024 * 1024;
                break;
            case 'k':
                $value *= 1024;
                break;
        }

        return $value;
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

        try {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($files as $file) {
                if ($file->isFile()) {
                    $size += $file->getSize();
                }
            }
        } catch (\Exception $e) {
            // Directory not accessible
            return 0;
        }

        return $size;
    }
}
