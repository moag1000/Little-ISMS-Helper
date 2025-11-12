<?php

namespace App\Controller;

use App\Entity\AuditLog;
use App\Entity\User;
use App\Repository\AuditLogRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin')]
#[IsGranted('ROLE_ADMIN')]
class AdminDashboardController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserRepository $userRepository,
        private AuditLogRepository $auditLogRepository
    ) {
    }

    #[Route('', name: 'admin_dashboard', methods: ['GET'])]
    public function index(): Response
    {
        // System Health Stats
        $stats = $this->getSystemHealthStats();

        // Recent Activity (last 10 audit log entries)
        $recentActivity = $this->auditLogRepository->findBy(
            [],
            ['createdAt' => 'DESC'],
            10
        );

        // System Alerts (example: inactive users, pending reviews, etc.)
        $alerts = $this->getSystemAlerts();

        return $this->render('admin/dashboard.html.twig', [
            'stats' => $stats,
            'recentActivity' => $recentActivity,
            'alerts' => $alerts,
        ]);
    }

    private function getSystemHealthStats(): array
    {
        $conn = $this->entityManager->getConnection();

        // User Statistics
        $totalUsers = $this->userRepository->count([]);
        $activeUsers = $this->userRepository->count(['isActive' => true]);
        $inactiveUsers = $totalUsers - $activeUsers;

        // Module Statistics (check if tables exist and have data)
        $moduleStats = [
            'assets' => $this->getTableCount('assets'),
            'risks' => $this->getTableCount('risks'),
            'controls' => $this->getTableCount('controls'),
            'incidents' => $this->getTableCount('incidents'),
            'audits' => $this->getTableCount('audits'),
            'compliance_requirements' => $this->getTableCount('compliance_requirements'),
            'trainings' => $this->getTableCount('trainings'),
        ];

        // Session Statistics (active sessions in last 24 hours)
        // For now, we'll use audit log as proxy for activity
        $activeSessions = $this->auditLogRepository->createQueryBuilder('a')
            ->select('COUNT(DISTINCT a.userId)')
            ->where('a.createdAt >= :yesterday')
            ->setParameter('yesterday', new \DateTimeImmutable('-24 hours'))
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
        try {
            $conn = $this->entityManager->getConnection();
            $result = $conn->executeQuery("SELECT COUNT(*) as count FROM {$tableName}")->fetchAssociative();
            return (int) ($result['count'] ?? 0);
        } catch (\Exception $e) {
            return 0;
        }
    }

    private function getDatabaseSize(): float
    {
        try {
            $conn = $this->entityManager->getConnection();
            $dbName = $conn->getDatabase();

            // SQLite
            if (str_contains($conn->getDriver()->getName(), 'sqlite')) {
                $dbPath = $conn->getParams()['path'] ?? null;
                if ($dbPath && file_exists($dbPath)) {
                    return round(filesize($dbPath) / 1024 / 1024, 2);
                }
            }

            // MySQL/MariaDB
            if (str_contains($conn->getDriver()->getName(), 'mysql')) {
                $result = $conn->executeQuery(
                    "SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) as size_mb
                     FROM information_schema.TABLES
                     WHERE table_schema = :dbname",
                    ['dbname' => $dbName]
                )->fetchAssociative();
                return (float) ($result['size_mb'] ?? 0);
            }

            // PostgreSQL
            if (str_contains($conn->getDriver()->getName(), 'pgsql')) {
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

            return 0.0;
        } catch (\Exception $e) {
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

        // Database size warning (> 1GB)
        $dbSize = $this->getDatabaseSize();
        if ($dbSize > 1024) {
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
