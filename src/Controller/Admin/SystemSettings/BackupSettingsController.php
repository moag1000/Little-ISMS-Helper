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
 * Backup settings UI.
 * Persists in Tenant.settings['backup'] JSON sub-key.
 * Note: "Run now" button and actual Messenger dispatch are Wave 2.
 */
#[Route('/admin/settings/backups')]
#[IsGranted('ROLE_ADMIN')]
class BackupSettingsController extends AbstractController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    #[Route('', name: 'admin_settings_backups', methods: ['GET', 'POST'])]
    public function edit(Request $request): Response
    {
        $tenant = $this->requireTenant();

        $settings = $tenant->getSettings() ?? [];
        $backup = $settings['backup'] ?? [];

        $current = [
            'retention_days' => (int) ($backup['retention_days'] ?? 30),
            'schedule'       => (string) ($backup['schedule'] ?? 'off'),
            'storage_path'   => (string) ($_ENV['BACKUP_STORAGE_PATH'] ?? '/var/backups/isms'),
        ];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_settings_backups', (string) $request->request->get('_token', ''))) {
                $this->addFlash('danger', 'admin.backups.csrf_invalid');
                return $this->redirectToRoute('admin_settings_backups');
            }

            $new = [
                'retention_days' => max(1, min(3650, (int) $request->request->get('retention_days', 30))),
                'schedule'       => match ($request->request->get('schedule', 'off')) {
                    'off', 'daily', 'weekly' => $request->request->get('schedule'),
                    default => 'off',
                },
                'storage_path'   => $current['storage_path'], // read-only, not from POST
            ];

            $updatedSettings = $settings;
            $updatedSettings['backup'] = [
                'retention_days' => $new['retention_days'],
                'schedule'       => $new['schedule'],
            ];
            $tenant->setSettings($updatedSettings);

            $this->em->flush();

            $this->auditLogger->logUpdate(
                entityType: 'Tenant',
                entityId: $tenant->getId(),
                oldValues: $current,
                newValues: $new,
                description: 'Backup settings updated',
            );

            $this->addFlash('success', 'admin.backups.saved');
            return $this->redirectToRoute('admin_settings_backups');
        }

        return $this->render('admin/system_settings/backups.html.twig', [
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
