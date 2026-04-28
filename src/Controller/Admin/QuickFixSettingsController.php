<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Repository\SystemSettingsRepository;
use App\Service\AuditLogger;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Admin-UI for the four `quick_fix.*` settings that govern the public
 * fallback page shown on schema-mismatch errors.
 *
 * Defaults match Docker self-hosting (open access, fallback enabled).
 * Operators of audit-critical Composer-deployments tighten via this UI:
 * require installer-token / dev-only / IP-allowlist.
 */
#[Route('/admin/quick-fix-settings')]
#[IsGranted('ROLE_ADMIN')]
class QuickFixSettingsController extends AbstractController
{
    private const string CATEGORY = 'quick_fix';

    public function __construct(
        private readonly SystemSettingsRepository $systemSettings,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    #[Route('', name: 'app_admin_quick_fix_settings', methods: ['GET', 'POST'])]
    public function edit(Request $request): Response
    {
        $current = [
            'fallback_ui_enabled' => (bool) $this->systemSettings->getSetting(self::CATEGORY, 'fallback_ui_enabled', true),
            'require_installer_token' => (bool) $this->systemSettings->getSetting(self::CATEGORY, 'require_installer_token', false),
            'allow_in_dev_only' => (bool) $this->systemSettings->getSetting(self::CATEGORY, 'allow_in_dev_only', false),
            'ip_allowlist' => (string) $this->systemSettings->getSetting(self::CATEGORY, 'ip_allowlist', ''),
        ];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('quick_fix_settings', (string) $request->request->get('_token', ''))) {
                $this->addFlash('danger', 'quick_fix.settings.csrf_invalid');
                return $this->redirectToRoute('app_admin_quick_fix_settings');
            }

            $new = [
                'fallback_ui_enabled' => $request->request->getBoolean('fallback_ui_enabled'),
                'require_installer_token' => $request->request->getBoolean('require_installer_token'),
                'allow_in_dev_only' => $request->request->getBoolean('allow_in_dev_only'),
                'ip_allowlist' => trim((string) $request->request->get('ip_allowlist', '')),
            ];

            $user = $this->getUser();
            $updatedBy = method_exists($user, 'getUserIdentifier') ? $user->getUserIdentifier() : null;

            foreach ($new as $key => $value) {
                $this->systemSettings->setSetting(
                    category: self::CATEGORY,
                    key: $key,
                    value: $value,
                    updatedBy: $updatedBy,
                );
            }

            $this->auditLogger->logUpdate(
                entityType: 'SystemSettings',
                entityId: null,
                oldValues: $current,
                newValues: $new,
                description: 'Quick-Fix Fallback-UI Settings aktualisiert',
            );

            $this->addFlash('success', 'quick_fix.settings.saved');
            return $this->redirectToRoute('app_admin_quick_fix_settings');
        }

        return $this->render('admin/quick_fix_settings/edit.html.twig', [
            'current' => $current,
        ]);
    }
}
