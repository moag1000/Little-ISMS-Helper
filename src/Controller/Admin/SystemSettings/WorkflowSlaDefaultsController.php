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
 * Workflow SLA defaults settings UI.
 * Persists in Tenant.settings['workflow_sla'] JSON sub-key.
 * SLA values are in hours. Picked up by Sprint-6b SlaDeadlineMonitor (Wave 2).
 */
// @no-methods-required — class-level path prefix, methods declared per action
#[Route('/admin/settings/workflow-slas')]
#[IsGranted('ROLE_ADMIN')]
class WorkflowSlaDefaultsController extends AbstractController
{
    /** Default SLA hours per workflow event type. */
    private const DEFAULTS = [
        'data_breach'     => 72,
        'incident_high'   => 24,
        'incident_medium' => 72,
        'incident_low'    => 168,
        'risk_approval'   => 120,  // 5 days
        'policy_approval' => 240,  // 10 days
        'dpia_review'     => 336,  // 14 days
        'audit_finding'   => 720,  // 30 days
    ];

    /** Regulatory minimum SLA hours (cannot be relaxed below). */
    private const REGULATORY_MINIMUMS = [
        'data_breach'   => 72,   // GDPR Art. 33: 72h notification
        'incident_high' => 4,    // ISO 27035 best-practice
    ];

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    #[Route('', name: 'admin_settings_workflow_slas', methods: ['GET', 'POST'])]
    public function edit(Request $request): Response
    {
        $tenant = $this->requireTenant();

        $settings = $tenant->getSettings() ?? [];
        $saved = $settings['workflow_sla'] ?? [];

        $current = [];
        foreach (array_keys(self::DEFAULTS) as $eventType) {
            $current[$eventType] = (int) ($saved[$eventType] ?? self::DEFAULTS[$eventType]);
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_settings_workflow_slas', (string) $request->request->get('_token', ''))) {
                $this->addFlash('danger', 'admin.workflow_slas.csrf_invalid');
                return $this->redirectToRoute('admin_settings_workflow_slas');
            }

            $new = [];
            $regulatoryWarnings = [];
            foreach (array_keys(self::DEFAULTS) as $eventType) {
                $hours = max(1, (int) $request->request->get("sla_{$eventType}", self::DEFAULTS[$eventType]));
                $minHours = self::REGULATORY_MINIMUMS[$eventType] ?? 1;
                if ($hours < $minHours) {
                    $regulatoryWarnings[] = $eventType;
                    $hours = $minHours;
                }
                $new[$eventType] = $hours;
            }

            if ($regulatoryWarnings !== []) {
                $this->addFlash('warning', 'admin.workflow_slas.regulatory_minimum_enforced');
            }

            $updatedSettings = $settings;
            $updatedSettings['workflow_sla'] = $new;
            $tenant->setSettings($updatedSettings);

            $this->em->flush();

            $this->auditLogger->logUpdate(
                entityType: 'Tenant',
                entityId: $tenant->getId(),
                oldValues: ['sla_hours' => $current],
                newValues: ['sla_hours' => $new],
                description: 'Workflow SLA defaults updated',
            );

            $this->addFlash('success', 'admin.workflow_slas.saved');
            return $this->redirectToRoute('admin_settings_workflow_slas');
        }

        return $this->render('admin/system_settings/workflow_slas.html.twig', [
            'current'              => $current,
            'event_types'          => array_keys(self::DEFAULTS),
            'regulatory_minimums'  => self::REGULATORY_MINIMUMS,
            'defaults'             => self::DEFAULTS,
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
