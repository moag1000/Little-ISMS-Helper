<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Tenant;
use DateTimeImmutable;
use Exception;
use App\Repository\AuditLogRepository;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceMappingRepository;
use App\Repository\ControlRepository;
use App\Repository\UserRepository;
use App\Repository\WorkflowInstanceRepository;
use App\Service\ComplianceFrameworkLoaderService;
use App\Service\ModuleConfigurationService;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
class AdminDashboardController extends AbstractController
{
    // Constants for configuration
    private const int RECENT_ACTIVITY_LIMIT = 10;
    private const int DATABASE_SIZE_WARNING_MB = 1024; // 1 GB
    private const int ACTIVE_SESSION_WINDOW_HOURS = 24;

    // Whitelist of allowed table names for statistics
    // Maps logical module names to actual database table names
    private const array ALLOWED_TABLES = [
        'assets' => 'asset',
        'risks' => 'risk',
        'controls' => 'control',
        'incidents' => 'incident',
        'audits' => 'internal_audit',
        'compliance_requirements' => 'compliance_requirement',
        'trainings' => 'training',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $userRepository,
        private readonly AuditLogRepository $auditLogRepository,
        private readonly ControlRepository $controlRepository,
        private readonly ModuleConfigurationService $moduleConfiguration,
        private readonly LoggerInterface $logger,
        private readonly ?ComplianceFrameworkRepository $frameworkRepository = null,
        private readonly ?ComplianceMappingRepository $mappingRepository = null,
        private readonly ?ComplianceFrameworkLoaderService $frameworkLoader = null,
        private readonly ?WorkflowInstanceRepository $workflowInstanceRepository = null,
    ) {
    }

    #[Route('/admin', name: 'admin_dashboard', methods: ['GET'])]
    public function index(): Response
    {
        // Get current user's tenant
        $currentUser = $this->getUser();
        $currentTenant = $currentUser?->getTenant();

        // System Health Stats
        $stats = $this->getSystemHealthStats($currentTenant);

        // Recent Activity (using configured limit)
        $recentActivity = $this->auditLogRepository->findBy(
            [],
            ['createdAt' => 'DESC'],
            self::RECENT_ACTIVITY_LIMIT
        );

        // System Alerts (inactive users, pending reviews, etc.)
        $alerts = $this->getSystemAlerts($currentTenant);

        return $this->render('admin/dashboard.html.twig', [
            'stats' => $stats,
            'recentActivity' => $recentActivity,
            'alerts' => $alerts,
            'currentTenant' => $currentTenant,
        ]);
    }

    private function getSystemHealthStats(?Tenant $currentTenant): array
    {
        // User Statistics
        $totalUsers = $this->userRepository->count([]);
        $activeUsers = $this->userRepository->count(['isActive' => true]);
        $inactiveUsers = $totalUsers - $activeUsers;

        // Module Statistics with Corporate Hierarchy
        $moduleStats = [];
        if ($currentTenant instanceof Tenant) {
            // Get corporate statistics for tenant-aware modules
            foreach (self::ALLOWED_TABLES as $moduleKey => $tableName) {
                $moduleStats[$moduleKey] = $this->getCorporateTableStats($tableName, $currentTenant);
            }
        } else {
            // Fallback to global statistics if no tenant
            foreach (self::ALLOWED_TABLES as $moduleKey => $tableName) {
                $count = $this->getTableCount($tableName);
                $moduleStats[$moduleKey] = [
                    'own' => $count,
                    'inherited' => 0,
                    'subsidiaries' => 0,
                    'total' => $count,
                ];
            }
        }

        // Session Statistics (active sessions in configured time window)
        // Using audit log as proxy for activity
        $windowStart = new DateTimeImmutable('-' . self::ACTIVE_SESSION_WINDOW_HOURS . ' hours');
        $activeSessions = $this->auditLogRepository->createQueryBuilder('a')
            ->select('COUNT(DISTINCT a.userName)')
            ->where('a.createdAt >= :windowStart')
            ->setParameter('windowStart', $windowStart)
            ->getQuery()
            ->getSingleScalarResult();

        // Sprint 11: Compliance-Health Metriken (Framework-Ladezustand, Mapping-Counts,
        // Unreviewed-Seeds) — ersetzen die Ops-lastigen Total-Records-/DB-Size-Cards.
        $complianceStats = $this->getComplianceHealthStats();
        $workflowStats = $this->getWorkflowStats();

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
            'compliance' => $complianceStats,
            'workflows' => $workflowStats,
        ];
    }

    /**
     * @return array{
     *     frameworks_catalog: int, frameworks_loaded: int, frameworks_missing: int,
     *     mappings_total: int, unreviewed_seed_mappings: int
     * }
     */
    private function getComplianceHealthStats(): array
    {
        $catalog = 0;
        if ($this->frameworkLoader !== null) {
            try {
                $catalog = count($this->frameworkLoader->getAvailableFrameworks());
            } catch (Exception $e) {
                $this->logger->debug('Framework catalog not available', ['error' => $e->getMessage()]);
            }
        }

        $loaded = 0;
        if ($this->frameworkRepository !== null) {
            try {
                $loaded = (int) $this->frameworkRepository->createQueryBuilder('f')
                    ->select('COUNT(f.id)')
                    ->where('f.active = :active')
                    ->setParameter('active', true)
                    ->getQuery()
                    ->getSingleScalarResult();
            } catch (Exception $e) {
                $this->logger->debug('Framework count failed', ['error' => $e->getMessage()]);
            }
        }

        $mappingsTotal = 0;
        $unreviewedSeeds = 0;
        if ($this->mappingRepository !== null) {
            try {
                $mappingsTotal = (int) $this->mappingRepository->createQueryBuilder('m')
                    ->select('COUNT(m.id)')
                    ->getQuery()
                    ->getSingleScalarResult();
                $unreviewedSeeds = (int) $this->mappingRepository->createQueryBuilder('m')
                    ->select('COUNT(m.id)')
                    ->where('m.reviewStatus = :status')
                    ->andWhere('(m.verifiedBy LIKE :seed OR m.verifiedBy LIKE :consultant OR m.verifiedBy LIKE :csv OR m.verifiedBy = :wizard OR m.verifiedBy = :migrate)')
                    ->setParameter('status', 'unreviewed')
                    ->setParameter('seed', 'app:seed-%')
                    ->setParameter('consultant', 'consultant_template_import%')
                    ->setParameter('csv', 'csv_import_ui%')
                    ->setParameter('wizard', 'mapping_wizard')
                    ->setParameter('migrate', 'app:migrate-framework-version')
                    ->getQuery()
                    ->getSingleScalarResult();
            } catch (Exception $e) {
                $this->logger->debug('Mapping count failed', ['error' => $e->getMessage()]);
            }
        }

        return [
            'frameworks_catalog' => $catalog,
            'frameworks_loaded' => $loaded,
            'frameworks_missing' => max(0, $catalog - $loaded),
            'mappings_total' => $mappingsTotal,
            'unreviewed_seed_mappings' => $unreviewedSeeds,
        ];
    }

    /** @return array{open: int} */
    private function getWorkflowStats(): array
    {
        if ($this->workflowInstanceRepository === null) {
            return ['open' => 0];
        }
        try {
            $open = (int) $this->workflowInstanceRepository->createQueryBuilder('wi')
                ->select('COUNT(wi.id)')
                ->where('wi.status IN (:states)')
                ->setParameter('states', ['running', 'pending', 'in_progress'])
                ->getQuery()
                ->getSingleScalarResult();
            return ['open' => $open];
        } catch (Exception $e) {
            $this->logger->debug('Workflow count failed', ['error' => $e->getMessage()]);
            return ['open' => 0];
        }
    }

    private function getCorporateTableStats(string $tableName, Tenant $currentTenant): array
    {
        // Security: Validate table name against whitelist (values in the array)
        if (!in_array($tableName, self::ALLOWED_TABLES, true)) {
            $this->logger->warning('Attempted to query non-whitelisted table', [
                'table' => $tableName,
                'allowed_tables' => array_values(self::ALLOWED_TABLES),
            ]);
            return ['own' => 0, 'inherited' => 0, 'subsidiaries' => 0, 'total' => 0];
        }

        try {
            // Get own records
            $own = $this->getTenantTableCount($tableName, $currentTenant->getId());

            // Get inherited records from parent
            $inherited = 0;
            if ($currentTenant->getParent() instanceof Tenant) {
                $inherited = $this->getTenantTableCount($tableName, $currentTenant->getParent()->getId());
            }

            // Get subsidiaries records
            $subsidiaries = 0;
            foreach ($currentTenant->getSubsidiaries() as $subsidiary) {
                $subsidiaries += $this->getTenantTableCount($tableName, $subsidiary->getId());
            }

            return [
                'own' => $own,
                'inherited' => $inherited,
                'subsidiaries' => $subsidiaries,
                'total' => $own + $inherited + $subsidiaries,
            ];
        } catch (Exception $e) {
            $this->logger->error('Failed to get corporate table stats', [
                'table' => $tableName,
                'tenant' => $currentTenant->getId(),
                'error' => $e->getMessage(),
            ]);
            return ['own' => 0, 'inherited' => 0, 'subsidiaries' => 0, 'total' => 0];
        }
    }

    private function getTenantTableCount(string $tableName, int $tenantId): int
    {
        try {
            $conn = $this->entityManager->getConnection();
            // Safe to use since $tableName is validated in getCorporateTableStats
            $result = $conn->executeQuery(
                "SELECT COUNT(*) as count FROM {$tableName} WHERE tenant_id = :tenantId",
                ['tenantId' => $tenantId]
            )->fetchAssociative();
            return (int) ($result['count'] ?? 0);
        } catch (Exception) {
            // Table might not have tenant_id column, return 0
            return 0;
        }
    }

    private function getTableCount(string $tableName): int
    {
        // Security: Validate table name against whitelist (values in the array) to prevent SQL injection
        if (!in_array($tableName, self::ALLOWED_TABLES, true)) {
            $this->logger->warning('Attempted to query non-whitelisted table', [
                'table' => $tableName,
                'allowed_tables' => array_values(self::ALLOWED_TABLES),
            ]);
            return 0;
        }

        try {
            $conn = $this->entityManager->getConnection();
            // Safe to use direct interpolation since $tableName is validated against whitelist
            $result = $conn->executeQuery("SELECT COUNT(*) as count FROM {$tableName}")->fetchAssociative();
            return (int) ($result['count'] ?? 0);
        } catch (Exception $e) {
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
            if ($platform instanceof SQLitePlatform) {
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
                    }
                    if ($unit === 'kB') {
                        return $value / 1024;
                    }
                    return $value;
                }
            }

            $this->logger->debug('Unsupported database platform for size calculation', [
                'platform' => $platform::class,
            ]);
            return 0.0;
        } catch (Exception $e) {
            $this->logger->error('Failed to calculate database size', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return 0.0;
        }
    }

    private function getSystemAlerts(?Tenant $currentTenant = null): array
    {
        $alerts = [];

        // ISO 27001 module active but tenant has no Annex-A controls →
        // the SoA UI would be empty, which is the "tool doesn't work"
        // first impression. Offer a one-click fix.
        if ($currentTenant instanceof Tenant
            && $this->moduleConfiguration->isModuleActive('controls')
            && $this->controlRepository->count(['tenant' => $currentTenant]) === 0
        ) {
            $alerts[] = [
                'type' => 'danger',
                'icon' => 'bi-exclamation-triangle-fill',
                'message' => 'admin.alert.annex_a_missing',
                'count' => 93,
                'action' => $this->generateUrl('app_soa_index'),
                'fix_route' => 'app_soa_bootstrap_annex_a',
                'fix_csrf_name' => 'bootstrap_annex_a',
                'fix_label' => 'admin.alert.annex_a_missing_fix',
            ];
        }

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
