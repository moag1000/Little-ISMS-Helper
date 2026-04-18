<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\AuditLogger;
use App\Service\Export\DoraRegisterOfInformationExporter;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * DORA Register of Information CSV export endpoint.
 *
 * Emits the EBA/EIOPA/ESMA Final Draft ITS on ROI (Art. 28 DORA) conformant
 * CSV for the current tenant. Guarded by ROLE_MANAGER — export contains
 * regulated third-party data. Every download is audit-logged via
 * AuditLogger::logExport() for supervisory traceability.
 *
 * Tracks MINOR-6 in docs/DATA_REUSE_PLAN_REVIEW_ISB.md.
 */
class DoraRegisterExportController extends AbstractController
{
    public function __construct(
        private readonly DoraRegisterOfInformationExporter $exporter,
        private readonly TenantContext $tenantContext,
        private readonly AuditLogger $auditLogger,
    ) {}

    #[Route(
        path: '/dora-compliance/register-export.csv',
        name: 'app_dora_register_export_csv',
        methods: ['GET'],
    )]
    #[IsGranted('ROLE_MANAGER')]
    public function export(): Response
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null) {
            throw $this->createAccessDeniedException('No tenant context available.');
        }

        $csv = $this->exporter->export($tenant);

        $this->auditLogger->logExport(
            'DoraRegisterOfInformation',
            null,
            'DORA Register of Information CSV export',
        );

        $filename = sprintf('dora-register-of-information-%s.csv', (new \DateTimeImmutable())->format('Y-m-d'));

        $response = new Response($csv);
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="%s"', $filename));

        return $response;
    }
}
