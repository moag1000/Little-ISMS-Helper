<?php

declare(strict_types=1);

namespace App\Controller\Export;

use App\Service\Export\EntityListExporter;
use App\Service\Export\FilterStateService;
use App\Service\ModuleConfigurationService;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * FilteredExportController
 *
 * Exports the currently-filtered entity list-view as XLSX, CSV, or JSON.
 * Filter-state is captured from the current request's query-string,
 * allowing users to export exactly what they see on screen.
 *
 * All exports are logged via AuditLogger for compliance traceability.
 */
// @no-methods-required — class-level path prefix, methods declared per action
#[Route('/export', name: 'app_filtered_export_')]
#[IsGranted('ROLE_MANAGER')]
final class FilteredExportController extends AbstractController
{
    /** Exportable entity type => module that owns it (S32: an export never outlives its module). */
    public const ENTITY_MODULES = [
        'asset' => 'assets',
        'risk' => 'risks',
        'supplier' => 'suppliers',
        'control' => 'controls',
        'business_process' => 'bcm',
        'document' => 'documents',
        'incident' => 'incidents',
        'audit_finding' => 'audits',
    ];

    public function __construct(
        private readonly EntityListExporter $exporter,
        private readonly FilterStateService $filterStateService,
        private readonly TenantContext $tenantContext,
        private readonly ModuleConfigurationService $moduleConfiguration,
    ) {
    }

    /**
     * Export a filtered entity list.
     *
     * Route: GET /{locale}/export/{entityType}.{format}
     * All active query-string params are treated as filter-state.
     *
     * Example: GET /de/export/risk.xlsx?status=open&severity=high
     */
    #[Route('/{entityType}.{format}', name: 'entity', methods: ['GET'], requirements: [
        'entityType' => 'asset|risk|supplier|control|business_process|document|incident|audit_finding',
        'format' => 'xlsx|csv|json',
    ])]
    public function exportEntity(Request $request, string $entityType, string $format): Response
    {
        if (!$this->moduleConfiguration->isModuleActive(self::ENTITY_MODULES[$entityType])) {
            throw $this->createNotFoundException('Module not active.');
        }
        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null) {
            throw $this->createNotFoundException('No tenant context available.');
        }

        $filters = $this->filterStateService->captureFilters($request, $entityType);

        try {
            $result = $this->exporter->exportFiltered($entityType, $filters, $tenant, $format);
        } catch (\InvalidArgumentException $e) {
            throw $this->createNotFoundException($e->getMessage());
        }

        if ($format === 'json') {
            /** @var array<int, array<string, mixed>> $result */
            return new JsonResponse([
                'entity_type' => $entityType,
                'filter_summary' => $this->filterStateService->serializeForExport($filters),
                'total' => count($result),
                'data' => $result,
            ]);
        }

        /** @var StreamedResponse $result */
        return $result;
    }
}
