<?php

namespace App\Controller;

use Symfony\Component\Security\Core\User\UserInterface;
use App\Entity\Tenant;
use App\Repository\DashboardLayoutRepository;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class DashboardLayoutController extends AbstractController
{
    public function __construct(
        private readonly DashboardLayoutRepository $dashboardLayoutRepository,
        private readonly TenantContext $tenantContext
    ) {}

    /**
     * Get current user's dashboard layout configuration
     */
    #[Route('/dashboard-layout/config', name: 'app_dashboard_layout_get', methods: ['GET'])]
    public function getLayout(): JsonResponse
    {
        $user = $this->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();

        if (!$user instanceof UserInterface || !$tenant instanceof Tenant) {
            return $this->json(['error' => 'User or tenant not found'], Response::HTTP_UNAUTHORIZED);
        }

        $dashboardLayout = $this->dashboardLayoutRepository->findOrCreateForUser($user, $tenant);

        return $this->json([
            'success' => true,
            'layout' => $dashboardLayout->getLayoutConfig(),
            'updated_at' => $dashboardLayout->getUpdatedAt()->format('c'),
        ]);
    }

    /**
     * Save dashboard layout configuration
     */
    #[Route('/dashboard-layout/config', name: 'app_dashboard_layout_save', methods: ['POST'])]
    public function saveLayout(Request $request): JsonResponse
    {
        $user = $this->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();

        if (!$user instanceof UserInterface || !$tenant instanceof Tenant) {
            return $this->json(['error' => 'User or tenant not found'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->json(['error' => 'Invalid JSON data'], Response::HTTP_BAD_REQUEST);
        }

        $dashboardLayout = $this->dashboardLayoutRepository->findOrCreateForUser($user, $tenant);
        $dashboardLayout->setLayoutConfig($data);

        $this->dashboardLayoutRepository->saveLayout($dashboardLayout);

        return $this->json([
            'success' => true,
            'message' => 'Dashboard layout saved successfully',
            'updated_at' => $dashboardLayout->getUpdatedAt()->format('c'),
        ]);
    }

    /**
     * Reset dashboard to defaults
     */
    #[Route('/dashboard-layout/reset', name: 'app_dashboard_layout_reset', methods: ['POST'])]
    public function resetLayout(): JsonResponse
    {
        $user = $this->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();

        if (!$user instanceof UserInterface || !$tenant instanceof Tenant) {
            return $this->json(['error' => 'User or tenant not found'], Response::HTTP_UNAUTHORIZED);
        }

        $dashboardLayout = $this->dashboardLayoutRepository->resetToDefaults($user, $tenant);

        return $this->json([
            'success' => true,
            'message' => 'Dashboard reset to defaults',
            'layout' => $dashboardLayout->getLayoutConfig(),
        ]);
    }

    /**
     * Update single widget configuration
     */
    #[Route('/dashboard-layout/widget/{widgetId}', name: 'app_dashboard_widget_update', methods: ['PATCH'])]
    public function updateWidget(string $widgetId, Request $request): JsonResponse
    {
        $user = $this->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();

        if (!$user instanceof UserInterface || !$tenant instanceof Tenant) {
            return $this->json(['error' => 'User or tenant not found'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->json(['error' => 'Invalid JSON data'], Response::HTTP_BAD_REQUEST);
        }

        $dashboardLayout = $this->dashboardLayoutRepository->findOrCreateForUser($user, $tenant);
        $dashboardLayout->updateWidgetConfig($widgetId, $data);

        $this->dashboardLayoutRepository->saveLayout($dashboardLayout);

        return $this->json([
            'success' => true,
            'message' => 'Widget configuration updated',
            'widget' => $dashboardLayout->getWidgetConfig($widgetId),
        ]);
    }
}
