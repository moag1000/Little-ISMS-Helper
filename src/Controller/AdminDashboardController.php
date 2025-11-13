<?php

namespace App\Controller;

use App\Repository\AuditLogRepository;
use App\Repository\UserRepository;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin')]
class AdminDashboardController extends AbstractController
{
    // Constants for configuration
    private const RECENT_ACTIVITY_LIMIT = 10;
    private const DATABASE_SIZE_WARNING_MB = 1024; // 1 GB
    private const ACTIVE_SESSION_WINDOW_HOURS = 24;

    // Whitelist of allowed table names for statistics
    private const ALLOWED_TABLES = [
        'assets',
        'risks',
        'controls',
        'incidents',
        'audits',
        'compliance_requirements',
        'trainings',
    ];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserRepository $userRepository,
        private AuditLogRepository $auditLogRepository,
        private LoggerInterface $logger
    ) {
    }

    #[Route('', name: 'admin_dashboard', methods: ['GET'])]
    #[IsGranted('ADMIN_VIEW')]
    public function index(): Response
    {
        // System Health Stats
        $stats = $this->getSystemHealthStats();

        // Recent Activity (using configured limit)
        $recentActivity = $this->auditLogRepository->findBy(
            [],
            ['createdAt' => 'DESC'],
            self::RECENT_ACTIVITY_LIMIT
        );

        // System Alerts (inactive users, pending reviews, etc.)
        $alerts = $this->getSystemAlerts();

        return $this->render('admin/dashboard.html.twig', [
            'stats' => $stats,
            'recentActivity' => $recentActivity,
            'alerts' => $alerts,
        ]);
    }

    private function getSystemHealthStats(): array
    {
        // User Statistics
        $totalUsers = $this->userRepository->count([]);
        $activeUsers = $this->userRepository->count(['isActive' => true]);
        $inactiveUsers = $totalUsers - $activeUsers;

        // Module Statistics (using allowed tables only)
        $moduleStats = [];
        foreach (self::ALLOWED_TABLES as $tableName) {
            $moduleStats[$tableName] = $this->getTableCount($tableName);
        }

        // Session Statistics (active sessions in configured time window)
        // Using audit log as proxy for activity
        $windowStart = new \DateTimeImmutable('-' . self::ACTIVE_SESSION_WINDOW_HOURS . ' hours');
        $activeSessions = $this->auditLogRepository->createQueryBuilder('a')
            ->select('COUNT(DISTINCT a.userName)')
            ->where('a.createdAt >= :windowStart')
            ->setParameter('windowStart', $windowStart)
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'users' => [
                'total' => $totalUsers,
                'active' => $activeUsers,
                'inactive' => $inactiveUsers,
            ],
            'modules' => $moduleStats,
            'sessions' => [
                'active_24h' => $activeSessions,
            ],
            'database' => [
                'size_mb' => $this->getDatabaseSize(),
            ],
        ];
    }

    private function getTableCount(string $tableName): int
    {
        // Security: Validate table name against whitelist to prevent SQL injection
        if (!in_array($tableName, self::ALLOWED_TABLES, true)) {
            $this->logger->warning('Attempted to query non-whitelisted table', [
                'table' => $tableName,
                'allowed_tables' => self::ALLOWED_TABLES,
            ]);
            return 0;
        }

        try {
            $conn = $this->entityManager->getConnection();
            // Safe to use direct interpolation since $tableName is validated against whitelist
            $result = $conn->executeQuery("SELECT COUNT(*) as count FROM {$tableName}")->fetchAssociative();
            return (int) ($result['count'] ?? 0);
        } catch (\Exception $e) {
            $this->logger->error('Failed to get table count', [
                'table' => $tableName,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return 0;
        }
    }

    private function getDatabaseSize(): float
    {
        try {
            $conn = $this->entityManager->getConnection();
            $dbName = $conn->getDatabase();
            $platform = $conn->getDatabasePlatform();

            // SQLite - Check using instanceof (Doctrine DBAL 4.x compatible)
            if ($platform instanceof SqlitePlatform) {
                $dbPath = $conn->getParams()['path'] ?? null;
                if ($dbPath && file_exists($dbPath)) {
                    return round(filesize($dbPath) / 1024 / 1024, 2);
                }
            }

            // MySQL/MariaDB - Check using instanceof (Doctrine DBAL 4.x compatible)
            if ($platform instanceof MySQLPlatform || $platform instanceof MariaDBPlatform) {
                $result = $conn->executeQuery(
                    "SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) as size_mb
                     FROM information_schema.TABLES
                     WHERE table_schema = :dbname",
                    ['dbname' => $dbName]
                )->fetchAssociative();
                return (float) ($result['size_mb'] ?? 0);
            }

            // PostgreSQL - Check using instanceof (Doctrine DBAL 4.x compatible)
            if ($platform instanceof PostgreSQLPlatform) {
                $result = $conn->executeQuery(
                    "SELECT pg_size_pretty(pg_database_size(:dbname)) as size",
                    ['dbname' => $dbName]
                )->fetchAssociative();
                // Parse size string (e.g., "8192 kB" or "12 MB")
                $sizeStr = $result['size'] ?? '0 MB';
                if (preg_match('/(\d+\.?\d*)\s*(MB|GB|kB)/', $sizeStr, $matches)) {
                    $value = (float) $matches[1];
                    $unit = $matches[2];
                    if ($unit === 'GB') {
                        return $value * 1024;
                    } elseif ($unit === 'kB') {
                        return $value / 1024;
                    }
                    return $value;
                }
            }

            $this->logger->debug('Unsupported database platform for size calculation', [
                'platform' => get_class($platform),
            ]);
            return 0.0;
        } catch (\Exception $e) {
            $this->logger->error('Failed to calculate database size', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return 0.0;
        }
    }

    private function getSystemAlerts(): array
    {
        $alerts = [];

        // Check for inactive users
        $inactiveCount = $this->userRepository->count(['isActive' => false]);
        if ($inactiveCount > 0) {
            $alerts[] = [
                'type' => 'warning',
                'icon' => 'bi-person-x',
                'message' => "admin.alert.inactive_users",
                'count' => $inactiveCount,
                'action' => $this->generateUrl('user_management_index'),
            ];
        }

        // Check for unverified users
        $unverifiedCount = $this->userRepository->count(['isVerified' => false]);
        if ($unverifiedCount > 0) {
            $alerts[] = [
                'type' => 'info',
                'icon' => 'bi-person-check',
                'message' => "admin.alert.unverified_users",
                'count' => $unverifiedCount,
                'action' => $this->generateUrl('user_management_index'),
            ];
        }

        // Database size warning (using configured threshold)
        $dbSize = $this->getDatabaseSize();
        if ($dbSize > self::DATABASE_SIZE_WARNING_MB) {
            $alerts[] = [
                'type' => 'warning',
                'icon' => 'bi-database-exclamation',
                'message' => "admin.alert.large_database",
                'count' => round($dbSize / 1024, 2),
                'action' => null,
            ];
        }

        return $alerts;
    }
}
