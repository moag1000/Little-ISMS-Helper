<?php

namespace App\Controller;

use App\Repository\DashboardLayoutRepository;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/dashboard-layout')]
#[IsGranted('ROLE_USER')]
class DashboardLayoutController extends AbstractController
{
    public function __construct(
        private DashboardLayoutRepository $dashboardLayoutRepository,
        private TenantContext $tenantContext
    ) {}

    /**
     * Get current user's dashboard layout configuration
     */
    #[Route('/config', name: 'app_dashboard_layout_get', methods: ['GET'])]
    public function getLayout(): JsonResponse
    {
        $user = $this->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();

        if (!$user || !$tenant) {
            return $this->json(['error' => 'User or tenant not found'], Response::HTTP_UNAUTHORIZED);
        }

        $layout = $this->dashboardLayoutRepository->findOrCreateForUser($user, $tenant);

        return $this->json([
            'success' => true,
            'layout' => $layout->getLayoutConfig(),
            'updated_at' => $layout->getUpdatedAt()->format('c'),
        ]);
    }

    /**
     * Save dashboard layout configuration
     */
    #[Route('/config', name: 'app_dashboard_layout_save', methods: ['POST'])]
    public function saveLayout(Request $request): JsonResponse
    {
        $user = $this->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();

        if (!$user || !$tenant) {
            return $this->json(['error' => 'User or tenant not found'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->json(['error' => 'Invalid JSON data'], Response::HTTP_BAD_REQUEST);
        }

        $layout = $this->dashboardLayoutRepository->findOrCreateForUser($user, $tenant);
        $layout->setLayoutConfig($data);

        $this->dashboardLayoutRepository->saveLayout($layout);

        return $this->json([
            'success' => true,
            'message' => 'Dashboard layout saved successfully',
            'updated_at' => $layout->getUpdatedAt()->format('c'),
        ]);
    }

    /**
     * Reset dashboard to defaults
     */
    #[Route('/reset', name: 'app_dashboard_layout_reset', methods: ['POST'])]
    public function resetLayout(): JsonResponse
    {
        $user = $this->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();

        if (!$user || !$tenant) {
            return $this->json(['error' => 'User or tenant not found'], Response::HTTP_UNAUTHORIZED);
        }

        $layout = $this->dashboardLayoutRepository->resetToDefaults($user, $tenant);

        return $this->json([
            'success' => true,
            'message' => 'Dashboard reset to defaults',
            'layout' => $layout->getLayoutConfig(),
        ]);
    }

    /**
     * Update single widget configuration
     */
    #[Route('/widget/{widgetId}', name: 'app_dashboard_widget_update', methods: ['PATCH'])]
    public function updateWidget(string $widgetId, Request $request): JsonResponse
    {
        $user = $this->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();

        if (!$user || !$tenant) {
            return $this->json(['error' => 'User or tenant not found'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->json(['error' => 'Invalid JSON data'], Response::HTTP_BAD_REQUEST);
        }

        $layout = $this->dashboardLayoutRepository->findOrCreateForUser($user, $tenant);
        $layout->updateWidgetConfig($widgetId, $data);

        $this->dashboardLayoutRepository->saveLayout($layout);

        return $this->json([
            'success' => true,
            'message' => 'Widget configuration updated',
            'widget' => $layout->getWidgetConfig($widgetId),
        ]);
    }
}
