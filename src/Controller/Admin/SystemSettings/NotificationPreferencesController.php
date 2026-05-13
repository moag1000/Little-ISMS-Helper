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
 * Tenant-level notification preference settings.
 * Stores in Tenant.notificationPreferences JSON column (global sub-key).
 */
#[Route('/admin/settings/notifications')]
#[IsGranted('ROLE_ADMIN')]
class NotificationPreferencesController extends AbstractController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    #[Route('', name: 'admin_settings_notifications', methods: ['GET', 'POST'])]
    public function edit(Request $request): Response
    {
        $tenant = $this->requireTenant();

        $prefs = $tenant->getNotificationPreferences() ?? [];
        $global = $prefs['_global'] ?? [];

        $current = [
            'digest_enabled'   => (bool) ($global['digest_enabled'] ?? false),
            'digest_frequency'  => (string) ($global['digest_frequency'] ?? 'daily'),
            'webhook_timeout'   => (int) ($global['webhook_timeout'] ?? 10),
            'email_from_name'   => (string) ($tenant->getEmailFromName() ?? ''),
        ];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_settings_notifications', (string) $request->request->get('_token', ''))) {
                $this->addFlash('danger', 'admin.notification_preferences.csrf_invalid');
                return $this->redirectToRoute('admin_settings_notifications');
            }

            $new = [
                'digest_enabled'   => $request->request->getBoolean('digest_enabled'),
                'digest_frequency'  => match ($request->request->get('digest_frequency', 'daily')) {
                    'instant', 'hourly', 'daily' => $request->request->get('digest_frequency'),
                    default => 'daily',
                },
                'webhook_timeout'   => max(1, min(120, (int) $request->request->get('webhook_timeout', 10))),
                'email_from_name'   => substr(trim((string) $request->request->get('email_from_name', '')), 0, 100),
            ];

            $updatedPrefs = $prefs;
            $updatedPrefs['_global'] = [
                'digest_enabled'  => $new['digest_enabled'],
                'digest_frequency' => $new['digest_frequency'],
                'webhook_timeout'  => $new['webhook_timeout'],
            ];
            $tenant->setNotificationPreferences($updatedPrefs);

            if ($new['email_from_name'] !== '') {
                $tenant->setEmailFromName($new['email_from_name']);
            }

            $this->em->flush();

            $this->auditLogger->logUpdate(
                entityType: 'Tenant',
                entityId: $tenant->getId(),
                oldValues: $current,
                newValues: $new,
                description: 'Notification preferences updated',
            );

            $this->addFlash('success', 'admin.notification_preferences.saved');
            return $this->redirectToRoute('admin_settings_notifications');
        }

        return $this->render('admin/system_settings/notifications.html.twig', [
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
