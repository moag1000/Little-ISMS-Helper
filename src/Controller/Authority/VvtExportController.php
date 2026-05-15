<?php

declare(strict_types=1);

namespace App\Controller\Authority;

use App\Controller\Trait\ModuleGatedControllerTrait;
use App\Repository\ProcessingActivityRepository;
use App\Service\AuditLogger;
use App\Service\Authority\VvtBfdiExporter;
use App\Service\ModuleConfigurationService;
use App\Service\TenantContext;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * VvtExportController — BfDI-conformant export of the Record of Processing Activities.
 *
 * Exports the Verfahrensverzeichnis (Art. 30 DSGVO) in three formats:
 *  - XLSX (multi-tab BfDI-Muster workbook)
 *  - CSV  (BfDI column order, UTF-8 with BOM)
 *  - PDF  (BfDI layout via DomPDF / Twig)
 *
 * All downloads are logged via AuditLogger for compliance traceability.
 * Requires module 'privacy' + ROLE_DPO (inherits ROLE_MANAGER).
 */
#[Route('/verfahrensverzeichnis/export', name: 'app_vvt_export_')]
#[IsGranted('ROLE_DPO')]
final class VvtExportController extends AbstractController
{
    use ModuleGatedControllerTrait;

    public function __construct(
        private readonly VvtBfdiExporter $exporter,
        private readonly TenantContext $tenantContext,
        private readonly ProcessingActivityRepository $processingActivityRepository,
        private readonly AuditLogger $auditLogger,
        private readonly ModuleConfigurationService $moduleService,
        private readonly TranslatorInterface $translator,
    ) {
    }

    // ─── XLSX Download ────────────────────────────────────────────────────────

    #[Route('/xlsx', name: 'xlsx', methods: ['GET'])]
    public function xlsx(): Response
    {
        if ($redirect = $this->checkModuleActive('privacy')) {
            return $redirect;
        }

        $tenant = $this->tenantContext->getCurrentTenant();

        if ($tenant === null) {
            throw $this->createNotFoundException('No tenant context available.');
        }

        if ($this->processingActivityRepository->findByTenant($tenant) === []) {
            return $this->renderNoActivities();
        }

        $spreadsheet = $this->exporter->exportXlsx($tenant);

        $this->auditLogger->logExport(
            'VVT-BfDI',
            null,
            sprintf('Verfahrensverzeichnis exported as XLSX by %s', $this->getUser()?->getUserIdentifier() ?? 'unknown'),
        );

        $filename = sprintf(
            'VVT-BfDI-%s-%s.xlsx',
            preg_replace('/[^a-z0-9]+/i', '-', $tenant->getName() ?? 'export'),
            (new \DateTimeImmutable())->format('Ymd'),
        );

        $response = new StreamedResponse(function () use ($spreadsheet): void {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        });

        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="%s"', $filename));
        $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }

    // ─── CSV Download ─────────────────────────────────────────────────────────

    #[Route('/csv', name: 'csv', methods: ['GET'])]
    public function csv(): Response
    {
        if ($redirect = $this->checkModuleActive('privacy')) {
            return $redirect;
        }

        $tenant = $this->tenantContext->getCurrentTenant();

        if ($tenant === null) {
            throw $this->createNotFoundException('No tenant context available.');
        }

        if ($this->processingActivityRepository->findByTenant($tenant) === []) {
            return $this->renderNoActivities();
        }

        $csvContent = $this->exporter->exportCsv($tenant);

        $this->auditLogger->logExport(
            'VVT-BfDI',
            null,
            sprintf('Verfahrensverzeichnis exported as CSV by %s', $this->getUser()?->getUserIdentifier() ?? 'unknown'),
        );

        $filename = sprintf(
            'VVT-BfDI-%s-%s.csv',
            preg_replace('/[^a-z0-9]+/i', '-', $tenant->getName() ?? 'export'),
            (new \DateTimeImmutable())->format('Ymd'),
        );

        $response = new Response($csvContent);
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="%s"', $filename));
        $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');

        return $response;
    }

    // ─── PDF Download ─────────────────────────────────────────────────────────

    #[Route('/pdf', name: 'pdf', methods: ['GET'])]
    public function pdf(): Response
    {
        if ($redirect = $this->checkModuleActive('privacy')) {
            return $redirect;
        }

        $tenant = $this->tenantContext->getCurrentTenant();

        if ($tenant === null) {
            throw $this->createNotFoundException('No tenant context available.');
        }

        if ($this->processingActivityRepository->findByTenant($tenant) === []) {
            return $this->renderNoActivities();
        }

        $pdfContent = $this->exporter->exportPdf($tenant);

        $this->auditLogger->logExport(
            'VVT-BfDI',
            null,
            sprintf('Verfahrensverzeichnis exported as PDF by %s', $this->getUser()?->getUserIdentifier() ?? 'unknown'),
        );

        $filename = sprintf(
            'VVT-BfDI-%s-%s.pdf',
            preg_replace('/[^a-z0-9]+/i', '-', $tenant->getName() ?? 'export'),
            (new \DateTimeImmutable())->format('Ymd'),
        );

        $response = new Response($pdfContent);
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="%s"', $filename));
        $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');

        return $response;
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    /**
     * Render a friendly 404 page when no ProcessingActivities are recorded.
     * Links to the creation form.
     */
    private function renderNoActivities(): Response
    {
        return $this->render('authority/vvt_export_no_activities.html.twig', [], new Response('', Response::HTTP_NOT_FOUND));
    }
}
