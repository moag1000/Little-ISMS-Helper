<?php

declare(strict_types=1);

namespace App\Controller\Admin\SystemSettings;

use App\Entity\Tenant;
use App\Service\AuditLogger;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * API rate-limit settings page.
 * Base limit stored in Tenant.apiRateLimitPerMinute (int).
 * Extended settings (burst, exclude-routes) in Tenant.settings['api'] JSON sub-key.
 */
#[Route('/admin/settings/api-rate-limits')]
#[IsGranted('ROLE_ADMIN')]
class ApiRateLimitsController extends AbstractController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    #[Route('', name: 'admin_settings_api_rate_limits', methods: ['GET', 'POST'])]
    public function edit(Request $request): Response
    {
        $tenant = $this->requireTenant();

        $settings = $tenant->getSettings() ?? [];
        $api = $settings['api'] ?? [];

        $current = [
            'rate_limit_per_minute' => $tenant->getApiRateLimitPerMinute() ?? 600,
            'burst_limit'           => (int) ($api['burst_limit'] ?? 50),
            'exclude_routes_regex'  => (string) ($api['exclude_routes_regex'] ?? ''),
        ];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_settings_api_rate_limits', (string) $request->request->get('_token', ''))) {
                $this->addFlash('danger', 'admin.api_rate_limits.csrf_invalid');
                return $this->redirectToRoute('admin_settings_api_rate_limits');
            }

            $rawLimit = (int) $request->request->get('rate_limit_per_minute', 600);
            $rawBurst = (int) $request->request->get('burst_limit', 50);
            $excludeRegex = substr(trim((string) $request->request->get('exclude_routes_regex', '')), 0, 500);

            $new = [
                'rate_limit_per_minute' => max(0, min(100_000, $rawLimit)),
                'burst_limit'           => max(0, min(10_000, $rawBurst)),
                'exclude_routes_regex'  => $excludeRegex,
            ];

            $tenant->setApiRateLimitPerMinute($new['rate_limit_per_minute']);

            $updatedSettings = $settings;
            $updatedSettings['api'] = [
                'burst_limit'          => $new['burst_limit'],
                'exclude_routes_regex' => $new['exclude_routes_regex'],
            ];
            $tenant->setSettings($updatedSettings);

            $this->em->flush();

            $this->auditLogger->logUpdate(
                entityType: 'Tenant',
                entityId: $tenant->getId(),
                oldValues: $current,
                newValues: $new,
                description: 'API rate limit settings updated',
            );

            $this->addFlash('success', 'admin.api_rate_limits.saved');
            return $this->redirectToRoute('admin_settings_api_rate_limits');
        }

        return $this->render('admin/system_settings/api_rate_limits.html.twig', [
            'current' => $current,
        ]);
    }

    private function requireTenant(): Tenant
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$tenant instanceof Tenant) {
            throw $this->createNotFoundException('No tenant context.');
        }
        return $tenant;
    }
}
