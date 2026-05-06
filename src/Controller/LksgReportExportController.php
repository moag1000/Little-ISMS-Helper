<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\AuditLogger;
use App\Service\Export\LksgAnnualReportExporter;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * BAFA LkSG annual-report CSV export endpoint.
 *
 * Returns the LkSG-relevant supplier inventory for the current tenant.
 * Optional ?min_risk=low|medium|high|critical narrows to suppliers at or
 * above the chosen severity. Guarded by ROLE_MANAGER and audit-logged.
 */
class LksgReportExportController extends AbstractController
{
    public function __construct(
        private readonly LksgAnnualReportExporter $exporter,
        private readonly TenantContext $tenantContext,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    #[Route(
        path: '/supplier/lksg-annual-report.csv',
        name: 'app_supplier_lksg_annual_report_csv',
        methods: ['GET'],
    )]
    #[IsGranted('ROLE_MANAGER')]
    public function export(Request $request): Response
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null) {
            throw $this->createAccessDeniedException('No tenant context available.');
        }

        $minRisk = $request->query->get('min_risk');
        $minRisk = is_string($minRisk) && in_array($minRisk, ['low', 'medium', 'high', 'critical'], true)
            ? $minRisk
            : null;

        $csv = $this->exporter->export($tenant, $minRisk);

        $this->auditLogger->logExport(
            'LksgAnnualReport',
            null,
            'LkSG annual-report CSV export' . ($minRisk !== null ? sprintf(' (min_risk=%s)', $minRisk) : ''),
        );

        $filename = sprintf(
            'lksg-annual-report-%s%s.csv',
            (new \DateTimeImmutable())->format('Y-m-d'),
            $minRisk !== null ? '-' . $minRisk : '',
        );

        $response = new Response($csv);
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="%s"', $filename));

        return $response;
    }
}
