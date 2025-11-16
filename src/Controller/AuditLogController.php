<?php

namespace App\Controller;

use App\Repository\AuditLogRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/audit-log')]
#[IsGranted('ROLE_ADMIN')]
class AuditLogController extends AbstractController
{
    public function __construct(
        private AuditLogRepository $auditLogRepository
    ) {}

    #[Route('/', name: 'app_audit_log_index')]
    public function index(Request $request): Response
    {
        $page = max(1, $request->query->getInt('page', 1));
        $limit = 50;
        $offset = ($page - 1) * $limit;

        // Get filter parameters
        $filters = [
            'entityType' => $request->query->get('entityType'),
            'action' => $request->query->get('action'),
            'userName' => $request->query->get('userName'),
            'dateFrom' => $request->query->get('dateFrom') ? new \DateTime($request->query->get('dateFrom')) : null,
            'dateTo' => $request->query->get('dateTo') ? new \DateTime($request->query->get('dateTo')) : null,
            'limit' => $limit
        ];

        // Remove empty filters
        $filters = array_filter($filters, fn($value) => $value !== null && $value !== '');

        // Get logs based on filters
        if (!empty($filters) && count($filters) > 1) { // More than just 'limit'
            $auditLogs = $this->auditLogRepository->search($filters);
            $totalLogs = count($auditLogs); // Simplified for filtered results
        } else {
            $auditLogs = $this->auditLogRepository->findAllOrdered($limit, $offset);
            $totalLogs = $this->auditLogRepository->countAll();
        }

        $totalPages = ceil($totalLogs / $limit);

        // Get statistics
        $actionStats = $this->auditLogRepository->getActionStatistics();
        $entityTypeStats = $this->auditLogRepository->getEntityTypeStatistics();
        $recentActivity = $this->auditLogRepository->getRecentActivity(24);

        // Get unique values for filters
        $allLogs = $this->auditLogRepository->findAll();
        $entityTypes = array_unique(array_map(fn($log) => $log->getEntityType(), $allLogs));
        $actions = array_unique(array_map(fn($log) => $log->getAction(), $allLogs));

        sort($entityTypes);
        sort($actions);

        return $this->render('audit_log/index.html.twig', [
            'auditLogs' => $auditLogs,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'totalLogs' => $totalLogs,
            'actionStats' => $actionStats,
            'entityTypeStats' => $entityTypeStats,
            'recentActivity' => $recentActivity,
            'entityTypes' => $entityTypes,
            'actions' => $actions,
            'filters' => $request->query->all()
        ]);
    }

    #[Route('/entity/{entityType}/{entityId}', name: 'app_audit_log_entity')]
    public function entityHistory(string $entityType, int $entityId): Response
    {
        $auditLogs = $this->auditLogRepository->findByEntity($entityType, $entityId);

        return $this->render('audit_log/entity_history.html.twig', [
            'auditLogs' => $auditLogs,
            'entityType' => $entityType,
            'entityId' => $entityId
        ]);
    }

    #[Route('/user/{userName}', name: 'app_audit_log_user')]
    public function userActivity(string $userName): Response
    {
        $auditLogs = $this->auditLogRepository->findByUser($userName);

        return $this->render('audit_log/user_activity.html.twig', [
            'auditLogs' => $auditLogs,
            'userName' => $userName
        ]);
    }

    #[Route('/statistics', name: 'app_audit_log_statistics')]
    public function statistics(): Response
    {
        $actionStats = $this->auditLogRepository->getActionStatistics();
        $entityTypeStats = $this->auditLogRepository->getEntityTypeStatistics();
        $totalLogs = $this->auditLogRepository->countAll();

        // Get activity by day for the last 30 days
        $activityByDay = [];
        for ($i = 29; $i >= 0; $i--) {
            $date = new \DateTime("-{$i} days");
            $nextDate = clone $date;
            $nextDate->modify('+1 day');

            $logs = $this->auditLogRepository->findByDateRange($date, $nextDate);
            $activityByDay[$date->format('Y-m-d')] = count($logs);
        }

        return $this->render('audit_log/statistics.html.twig', [
            'actionStats' => $actionStats,
            'entityTypeStats' => $entityTypeStats,
            'totalLogs' => $totalLogs,
            'activityByDay' => $activityByDay
        ]);
    }

    #[Route('/{id}', name: 'app_audit_log_detail', requirements: ['id' => '\d+'])]
    public function detail(int $id): Response
    {
        $auditLog = $this->auditLogRepository->find($id);

        if (!$auditLog) {
            throw $this->createNotFoundException('Audit log not found');
        }

        return $this->render('audit_log/detail.html.twig', [
            'auditLog' => $auditLog
        ]);
    }
}
