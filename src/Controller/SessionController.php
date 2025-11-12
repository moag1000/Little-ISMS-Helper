<?php

namespace App\Controller;

use App\Repository\AuditLogRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/admin/sessions')]
#[IsGranted('ROLE_ADMIN')]
class SessionController extends AbstractController
{
    #[Route('', name: 'session_index', methods: ['GET'])]
    public function index(
        AuditLogRepository $auditLogRepository,
        Request $request
    ): Response {
        // Get recent login activities (last 24 hours)
        $hoursFilter = (int) $request->query->get('hours', 24);
        $recentActivity = $auditLogRepository->getRecentActivity($hoursFilter);

        // Filter for login/authentication events
        $loginEvents = array_filter($recentActivity, function ($log) {
            return in_array($log->getAction(), ['login', 'login_success', 'authentication', 'session_start']);
        });

        // Group by user to get unique sessions
        $activeSessions = [];
        $userSessions = [];

        foreach ($loginEvents as $event) {
            $userName = $event->getUserName();

            // Keep only the most recent login per user
            if (!isset($userSessions[$userName]) ||
                $event->getCreatedAt() > $userSessions[$userName]['last_activity']) {

                $userSessions[$userName] = [
                    'user_name' => $userName,
                    'ip_address' => $event->getIpAddress(),
                    'user_agent' => $event->getUserAgent(),
                    'last_activity' => $event->getCreatedAt(),
                    'session_start' => $event->getCreatedAt(),
                    'audit_log_id' => $event->getId(),
                ];
            }
        }

        $activeSessions = array_values($userSessions);

        // Calculate statistics
        $totalSessions = count($activeSessions);
        $todaySessions = count(array_filter($activeSessions, function ($session) {
            return $session['last_activity']->format('Y-m-d') === (new \DateTime())->format('Y-m-d');
        }));

        return $this->render('session/index.html.twig', [
            'sessions' => $activeSessions,
            'statistics' => [
                'total' => $totalSessions,
                'today' => $todaySessions,
                'hours_filter' => $hoursFilter,
            ],
        ]);
    }

    #[Route('/{userName}/show', name: 'session_show', methods: ['GET'])]
    public function show(
        string $userName,
        AuditLogRepository $auditLogRepository
    ): Response {
        // Get all activities for this user (last 7 days)
        $userActivities = $auditLogRepository->findByUser($userName, 500);

        // Filter activities from last 7 days
        $sevenDaysAgo = new \DateTime('-7 days');
        $recentActivities = array_filter($userActivities, function ($log) use ($sevenDaysAgo) {
            return $log->getCreatedAt() >= $sevenDaysAgo;
        });

        // Group by date for timeline
        $activityTimeline = [];
        foreach ($recentActivities as $activity) {
            $date = $activity->getCreatedAt()->format('Y-m-d');
            if (!isset($activityTimeline[$date])) {
                $activityTimeline[$date] = [];
            }
            $activityTimeline[$date][] = $activity;
        }

        // Get unique IP addresses
        $ipAddresses = array_unique(array_map(function ($log) {
            return $log->getIpAddress();
        }, $recentActivities));

        // Get unique user agents
        $userAgents = array_unique(array_filter(array_map(function ($log) {
            return $log->getUserAgent();
        }, $recentActivities)));

        return $this->render('session/show.html.twig', [
            'user_name' => $userName,
            'activities' => $recentActivities,
            'activity_timeline' => $activityTimeline,
            'ip_addresses' => array_values($ipAddresses),
            'user_agents' => array_values($userAgents),
            'total_activities' => count($recentActivities),
        ]);
    }

    #[Route('/terminate/{userName}', name: 'session_terminate', methods: ['POST'])]
    public function terminate(
        string $userName,
        Request $request,
        EntityManagerInterface $entityManager,
        TranslatorInterface $translator
    ): Response {
        // Verify CSRF token
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('terminate_session_' . $userName, $token)) {
            $this->addFlash('error', $translator->trans('session.error.invalid_token'));
            return $this->redirectToRoute('session_index');
        }

        // In a real implementation, you would:
        // 1. Find all sessions for this user in the session storage
        // 2. Invalidate/delete them
        // 3. Force the user to re-authenticate

        // For now, we log the termination action
        // Note: This requires actual session storage implementation

        // TODO: Implement actual session termination using Symfony's session handlers
        // For example, if using database session storage:
        // $sessionRepository->deleteByUsername($userName);

        $this->addFlash('warning', $translator->trans('session.info.termination_not_implemented', [
            'username' => $userName
        ]));

        return $this->redirectToRoute('session_index');
    }

    #[Route('/statistics', name: 'session_statistics', methods: ['GET'])]
    public function statistics(
        AuditLogRepository $auditLogRepository
    ): JsonResponse {
        // Get login statistics for the last 30 days
        $thirtyDaysAgo = new \DateTime('-30 days');
        $now = new \DateTime();

        $loginEvents = $auditLogRepository->findByDateRange($thirtyDaysAgo, $now);

        // Filter for login events
        $logins = array_filter($loginEvents, function ($log) {
            return in_array($log->getAction(), ['login', 'login_success', 'authentication']);
        });

        // Group by date
        $loginsByDate = [];
        foreach ($logins as $login) {
            $date = $login->getCreatedAt()->format('Y-m-d');
            if (!isset($loginsByDate[$date])) {
                $loginsByDate[$date] = 0;
            }
            $loginsByDate[$date]++;
        }

        // Get unique users
        $uniqueUsers = array_unique(array_map(function ($log) {
            return $log->getUserName();
        }, $logins));

        return $this->json([
            'total_logins' => count($logins),
            'unique_users' => count($uniqueUsers),
            'logins_by_date' => $loginsByDate,
            'period' => [
                'from' => $thirtyDaysAgo->format('Y-m-d'),
                'to' => $now->format('Y-m-d'),
            ],
        ]);
    }
}
