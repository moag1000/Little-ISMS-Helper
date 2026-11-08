<?php

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Admin\SystemSettings\DataRetentionController;
use App\Entity\Tenant;
use App\Service\Deadline\Source\RetentionDeletionSource;
use App\Service\Deadline\Source\UpcomingDeletion;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * What the retention sweep deletes next (DSGVO Art. 5 (1) e), for the data
 * protection officer: the reminder "Löschung nach Aufbewahrungsfrist" links
 * here. Shows the organisation's policies and the records due for deletion
 * per type, with the list to keep one of them. Changing the policy stays an
 * admin setting (Admin > Einstellungen > Datenhaltung).
 */
final class DataRetentionOverviewController extends AbstractController
{
    /** Record type → its list page. */
    private const array LIST_ROUTES = [
        'asset' => 'app_asset_index',
        'risk' => 'app_risk_index',
        'incident' => 'app_incident_index',
        'document' => 'app_document_index',
        'processing_activity' => 'app_processing_activity_index',
        'audit_finding' => 'app_audit_finding_index',
        'workflow_instance' => 'app_workflow_index',
    ];

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly RetentionDeletionSource $upcoming,
    ) {
    }

    #[Route('/data-retention', name: 'app_data_retention_overview', methods: ['GET'])]
    #[IsGranted('ROLE_DPO')]
    public function overview(): Response
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$tenant instanceof Tenant) {
            throw $this->createNotFoundException();
        }

        $saved = $tenant->getDataRetentionPolicies() ?? [];
        $upcoming = [];
        foreach ($this->upcoming->findOpen($tenant) as $deletion) {
            assert($deletion instanceof UpcomingDeletion);
            $upcoming[$deletion->type] = $deletion;
        }

        $rows = [];
        foreach (DataRetentionController::DEFAULTS as $type => $defaultDays) {
            $rows[] = [
                'type' => $type,
                'days' => (int) ($saved[$type]['retention_days'] ?? $defaultDays),
                'auto_delete' => (bool) ($saved[$type]['auto_delete'] ?? false),
                'upcoming' => $upcoming[$type] ?? null,
                'list_route' => self::LIST_ROUTES[$type] ?? null,
            ];
        }

        return $this->render('data_retention/overview.html.twig', ['rows' => $rows]);
    }
}
