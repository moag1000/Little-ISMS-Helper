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
 * Phase 8L.F4 — Admin-UI für Audit-Log-Retention.
 *
 * Globales Setting (kein Tenant-Override, weil ISO 27001 Clause 9.1 + NIS2
 * Art. 21.2 + DSGVO Art. 5.1(e) organisations-einheitlich gelten).
 */
#[Route('/admin/audit-log/retention')]
#[IsGranted('ADMIN_EDIT')]
class AuditRetentionController extends AbstractController
{
    private const int MIN_DAYS = 365;   // NIS2 Art. 21.2
    private const int MAX_DAYS = 3650;  // 10 Jahre — Sanity-Obergrenze
    private const int DEFAULT_DAYS = 730;

    public function __construct(
        private readonly SystemSettingsRepository $systemSettingsRepository,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    #[Route('', name: 'app_admin_audit_retention', methods: ['GET', 'POST'])]
    public function edit(Request $request): Response
    {
        $current = (int) ($this->systemSettingsRepository->getSetting('audit', 'retention_days', self::DEFAULT_DAYS));

        if ($request->isMethod('POST')) {
            $token = (string) $request->request->get('_token', '');
            if (!$this->isCsrfTokenValid('audit_retention', $token)) {
                $this->addFlash('danger', 'audit_log.retention.csrf_invalid');
                return $this->redirectToRoute('app_admin_audit_retention');
            }

            $new = (int) $request->request->get('retention_days', 0);

            if ($new < self::MIN_DAYS) {
                $this->addFlash('danger', 'audit_log.retention.error.min');
                return $this->redirectToRoute('app_admin_audit_retention');
            }
            if ($new > self::MAX_DAYS) {
                $this->addFlash('danger', 'audit_log.retention.error.max');
                return $this->redirectToRoute('app_admin_audit_retention');
            }

            $user = $this->getUser();
            $updatedBy = method_exists($user, 'getUserIdentifier') ? $user->getUserIdentifier() : null;

            $this->systemSettingsRepository->setSetting(
                category: 'audit',
                key: 'retention_days',
                value: (string) $new,
                description: 'Audit-Log Retention in Tagen. Min 365 (NIS2), Default 730 (ISO 27001 Clause 9.1).',
                updatedBy: $updatedBy,
            );

            $this->auditLogger->logUpdate(
                entityType: 'SystemSettings',
                entityId: null,
                oldValues: ['audit.retention_days' => $current],
                newValues: ['audit.retention_days' => $new],
                description: sprintf('Audit-Log Retention von %d auf %d Tage geändert', $current, $new),
            );

            $this->addFlash('success', 'audit_log.retention.saved');
            return $this->redirectToRoute('app_admin_audit_retention');
        }

        return $this->render('admin/audit_retention/edit.html.twig', [
            'retention_days' => $current,
            'min_days' => self::MIN_DAYS,
            'max_days' => self::MAX_DAYS,
            'default_days' => self::DEFAULT_DAYS,
        ]);
    }
}
