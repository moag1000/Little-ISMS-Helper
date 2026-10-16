<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\HealthAutoFixService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * One-click fixes offered on the system health page (MonitoringController::health).
 *
 * Every action is a state-changing POST sent by autofix_controller.js with the
 * AUTOFIX_CSRF_ID token in the X-CSRF-Token header; both checks apply to all
 * actions of this class.
 */
#[IsGranted('MONITORING_MANAGE')]
#[IsCsrfTokenValid(self::AUTOFIX_CSRF_ID, tokenKey: 'X-CSRF-Token', tokenSource: IsCsrfTokenValid::SOURCE_HEADER)]
final class MonitoringAutoFixController extends AbstractController
{
    /** CSRF id for the health auto-fix POSTs (autofix_controller.js sends it as X-CSRF-Token). */
    public const string AUTOFIX_CSRF_ID = 'monitoring_autofix';

    #[Route('/admin/monitoring/health/fix/cache', name: 'monitoring_health_fix_cache', methods: ['POST'])]
    public function fixCache(HealthAutoFixService $healthAutoFixService): JsonResponse
    {
        $result = $healthAutoFixService->fixCachePermissions();
        return $this->json($result);
    }

    #[Route('/admin/monitoring/health/fix/logs', name: 'monitoring_health_fix_logs', methods: ['POST'])]
    public function fixLogs(HealthAutoFixService $healthAutoFixService): JsonResponse
    {
        $result = $healthAutoFixService->fixLogPermissions();
        return $this->json($result);
    }

    #[Route('/admin/monitoring/health/fix/clear-cache', name: 'monitoring_health_clear_cache', methods: ['POST'])]
    public function clearCache(HealthAutoFixService $healthAutoFixService): JsonResponse
    {
        $result = $healthAutoFixService->clearCache();
        return $this->json($result);
    }

    #[Route('/admin/monitoring/health/fix/clean-logs', name: 'monitoring_health_clean_logs', methods: ['POST'])]
    public function cleanLogs(HealthAutoFixService $healthAutoFixService, Request $request): JsonResponse
    {
        $days = (int) $request->request->get('days', 30);
        $result = $healthAutoFixService->cleanOldLogs($days);
        return $this->json($result);
    }

    #[Route('/admin/monitoring/health/fix/rotate-logs', name: 'monitoring_health_rotate_logs', methods: ['POST'])]
    public function rotateLogs(HealthAutoFixService $healthAutoFixService): JsonResponse
    {
        $result = $healthAutoFixService->rotateLogs();
        return $this->json($result);
    }

    #[Route('/admin/monitoring/health/fix/optimize-disk', name: 'monitoring_health_optimize_disk', methods: ['POST'])]
    public function optimizeDisk(HealthAutoFixService $healthAutoFixService): JsonResponse
    {
        $result = $healthAutoFixService->optimizeDiskSpace();
        return $this->json($result);
    }

    #[Route('/admin/monitoring/health/fix/var-permissions', name: 'monitoring_health_fix_var', methods: ['POST'])]
    public function fixVarPermissions(HealthAutoFixService $healthAutoFixService): JsonResponse
    {
        $result = $healthAutoFixService->fixVarPermissions();
        return $this->json($result);
    }

    #[Route('/admin/monitoring/health/fix/uploads-permissions', name: 'monitoring_health_fix_uploads', methods: ['POST'])]
    public function fixUploadsPermissions(HealthAutoFixService $healthAutoFixService): JsonResponse
    {
        $result = $healthAutoFixService->fixUploadsPermissions();
        return $this->json($result);
    }

    #[Route('/admin/monitoring/health/fix/session-permissions', name: 'monitoring_health_fix_sessions', methods: ['POST'])]
    public function fixSessionPermissions(HealthAutoFixService $healthAutoFixService): JsonResponse
    {
        $result = $healthAutoFixService->fixSessionPermissions();
        return $this->json($result);
    }

    #[Route('/admin/monitoring/health/fix/clean-uploads', name: 'monitoring_health_clean_uploads', methods: ['POST'])]
    public function cleanUploads(HealthAutoFixService $healthAutoFixService, Request $request): JsonResponse
    {
        $days = (int) $request->request->get('days', 90);
        $result = $healthAutoFixService->clearOldUploads($days);
        return $this->json($result);
    }

    #[Route('/admin/monitoring/health/fix/composer-install', name: 'monitoring_health_composer_install', methods: ['POST'])]
    public function composerInstall(HealthAutoFixService $healthAutoFixService): JsonResponse
    {
        $result = $healthAutoFixService->runComposerInstall();
        return $this->json($result);
    }
}
