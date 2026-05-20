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
 * Data retention policy settings UI.
 * Persists per-entity-type retention in Tenant.dataRetentionPolicies JSON column.
 * NOTE: Auto-delete cron is NOT implemented here (Wave 2). UI only.
 */
// @no-methods-required — class-level path prefix, methods declared per action
#[Route('/admin/settings/data-retention')]
#[IsGranted('ROLE_ADMIN')]
class DataRetentionController extends AbstractController
{
    /** GDPR-relevant max retention per entity type (days). 0 = no enforced cap. */
    private const GDPR_MAX_DAYS = [
        'asset'              => 0,
        'risk'               => 0,
        'incident'           => 3650,  // 10 years
        'document'           => 3650,  // 10 years (HR/legal)
        'processing_activity'=> 3650,
        'audit_finding'      => 3650,
        'workflow_instance'  => 1825,  // 5 years
    ];

    /** Default retention days per entity type. */
    private const DEFAULTS = [
        'asset'              => 365,
        'risk'               => 730,
        'incident'           => 1825,
        'document'           => 2190,
        'processing_activity'=> 1825,
        'audit_finding'      => 1825,
        'workflow_instance'  => 730,
    ];

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    #[Route('', name: 'admin_settings_data_retention', methods: ['GET', 'POST'])]
    public function edit(Request $request): Response
    {
        $tenant = $this->requireTenant();

        $saved = $tenant->getDataRetentionPolicies() ?? [];

        // Build current state — merge saved with defaults
        $current = [];
        foreach (array_keys(self::DEFAULTS) as $entityType) {
            $saved_entry = $saved[$entityType] ?? [];
            $current[$entityType] = [
                'retention_days' => (int) ($saved_entry['retention_days'] ?? self::DEFAULTS[$entityType]),
                'auto_delete'    => (bool) ($saved_entry['auto_delete'] ?? false),
            ];
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_settings_data_retention', (string) $request->request->get('_token', ''))) {
                $this->addFlash('danger', 'admin.data_retention.csrf_invalid');
                return $this->redirectToRoute('admin_settings_data_retention');
            }

            $new = [];
            $errors = [];
            foreach (array_keys(self::DEFAULTS) as $entityType) {
                $days = (int) $request->request->get("retention_days_{$entityType}", self::DEFAULTS[$entityType]);
                $maxDays = self::GDPR_MAX_DAYS[$entityType];
                if ($maxDays > 0 && $days > $maxDays) {
                    $errors[] = $entityType;
                    $days = $maxDays;
                }
                $days = max(30, $days); // minimum 30 days
                $new[$entityType] = [
                    'retention_days' => $days,
                    'auto_delete'    => $request->request->getBoolean("auto_delete_{$entityType}"),
                ];
            }

            if ($errors !== []) {
                $this->addFlash('warning', 'admin.data_retention.gdpr_cap_applied');
            }

            $tenant->setDataRetentionPolicies($new);
            $this->em->flush();

            $this->auditLogger->logUpdate(
                entityType: 'Tenant',
                entityId: $tenant->getId(),
                oldValues: ['policies' => $current],
                newValues: ['policies' => $new],
                description: 'Data retention policies updated',
            );

            $this->addFlash('success', 'admin.data_retention.saved');
            return $this->redirectToRoute('admin_settings_data_retention');
        }

        return $this->render('admin/system_settings/data_retention.html.twig', [
            'current'       => $current,
            'entity_types'  => array_keys(self::DEFAULTS),
            'gdpr_max_days' => self::GDPR_MAX_DAYS,
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
