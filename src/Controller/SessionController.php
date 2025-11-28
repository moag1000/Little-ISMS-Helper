<?php

namespace App\Controller;

use DateTime;
use App\Entity\AuditLog;
use App\Repository\AuditLogRepository;
use App\Repository\UserRepository;
use App\Service\SessionManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

class SessionController extends AbstractController
{
    #[Route('/admin/sessions', name: 'session_index', methods: ['GET'])]
    #[IsGranted('SESSION_VIEW')]
    public function index(
        SessionManager $sessionManager,
        Request $request
    ): Response {
        // Get filter parameters
        $userEmail = $request->query->get('email');
        $limit = $request->query->get('limit', 100);

        // Get active sessions from SessionManager
        $activeSessions = $sessionManager->getAllActiveSessions($userEmail, $limit);

        // Get statistics
        $statistics = $sessionManager->getStatistics();
        $statistics['max_concurrent'] = $sessionManager->getMaxConcurrentSessions();

        return $this->render('session/index.html.twig', [
            'sessions' => $activeSessions,
            'statistics' => $statistics,
            'search_email' => $userEmail,
        ]);
    }
    #[Route('/admin/sessions/statistics', name: 'session_statistics', methods: ['GET'])]
    public function statistics(
        AuditLogRepository $auditLogRepository
    ): JsonResponse {
        // Get login statistics for the last 30 days
        $thirtyDaysAgo = new DateTime('-30 days');
        $now = new DateTime();

        $loginEvents = $auditLogRepository->findByDateRange($thirtyDaysAgo, $now);

        // Filter for login events
        $logins = array_filter($loginEvents, fn($log): bool => in_array($log->getAction(), ['login', 'login_success', 'authentication']));

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
        $uniqueUsers = array_unique(array_map(fn(AuditLog $log) => $log->getUserName(), $logins));

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
    #[Route('/admin/sessions/{userName}/show', name: 'session_show', methods: ['GET'])]
    #[IsGranted('SESSION_VIEW')]
    public function show(
        string $userName,
        AuditLogRepository $auditLogRepository
    ): Response {
        // Get all activities for this user (last 7 days)
        $userActivities = $auditLogRepository->findByUser($userName, 500);

        // Filter activities from last 7 days
        $sevenDaysAgo = new DateTime('-7 days');
        $recentActivities = array_filter($userActivities, fn(AuditLog $log): bool => $log->getCreatedAt() >= $sevenDaysAgo);

        // Group by date for timeline
        $activityTimeline = [];
        foreach ($recentActivities as $recentActivity) {
            $date = $recentActivity->getCreatedAt()->format('Y-m-d');
            if (!isset($activityTimeline[$date])) {
                $activityTimeline[$date] = [];
            }
            $activityTimeline[$date][] = $recentActivity;
        }

        // Get unique IP addresses
        $ipAddresses = array_unique(array_map(fn(AuditLog $log) => $log->getIpAddress(), $recentActivities));

        // Get unique user agents
        $userAgents = array_unique(array_filter(array_map(fn(AuditLog $log) => $log->getUserAgent(), $recentActivities)));

        return $this->render('session/show.html.twig', [
            'user_name' => $userName,
            'activities' => $recentActivities,
            'activity_timeline' => $activityTimeline,
            'ip_addresses' => array_values($ipAddresses),
            'user_agents' => array_values($userAgents),
            'total_activities' => count($recentActivities),
        ]);
    }
    #[Route('/admin/sessions/terminate/{userName}', name: 'session_terminate', methods: ['POST'])]
    #[IsGranted('SESSION_TERMINATE')]
    public function terminate(
        string $userName,
        Request $request,
        SessionManager $sessionManager,
        UserRepository $userRepository,
        TranslatorInterface $translator
    ): Response {
        // Verify CSRF token
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('terminate_session_' . $userName, $token)) {
            $this->addFlash('danger', $translator->trans('session.error.invalid_token'));
            return $this->redirectToRoute('session_index');
        }

        // Find user by email/username
        $user = $userRepository->findOneBy(['email' => $userName]);

        if (!$user) {
            $this->addFlash('danger', 'User not found');
            return $this->redirectToRoute('session_index');
        }

        // Terminate all active sessions for this user
        $adminEmail = $this->getUser()?->getUserIdentifier();
        $count = $sessionManager->terminateUserSessions($user, $adminEmail);

        if ($count > 0) {
            $this->addFlash('success', sprintf(
                'Successfully terminated %d active session(s) for user %s',
                $count,
                $userName
            ));
        } else {
            $this->addFlash('info', sprintf(
                'No active sessions found for user %s',
                $userName
            ));
        }

        return $this->redirectToRoute('session_index');
    }
    #[Route('/admin/sessions/terminate-session/{sessionId}', name: 'session_terminate_single', methods: ['POST'])]
    #[IsGranted('SESSION_TERMINATE')]
    public function terminateSingle(
        string $sessionId,
        Request $request,
        SessionManager $sessionManager,
        TranslatorInterface $translator
    ): Response {
        // Verify CSRF token
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('terminate_single_' . $sessionId, $token)) {
            $this->addFlash('danger', $translator->trans('session.error.invalid_token'));
            return $this->redirectToRoute('session_index');
        }

        // Terminate specific session
        $adminEmail = $this->getUser()?->getUserIdentifier();
        $success = $sessionManager->terminateSession($sessionId, 'forced', $adminEmail);

        if ($success) {
            $this->addFlash('success', 'Session terminated successfully');
        } else {
            $this->addFlash('warning', 'Session not found or already inactive');
        }

        return $this->redirectToRoute('session_index');
    }
}
