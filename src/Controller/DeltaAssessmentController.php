<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ComplianceFramework;
use App\Repository\ComplianceFrameworkRepository;
use App\Service\AuditLogger;
use App\Service\ExcelExportService;
use App\Service\Export\DeltaAssessmentExcelExporter;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Delta-Assessment Excel Exporter controller (CM-2).
 *
 * Produces a management-review-ready workbook that shows how an existing
 * baseline framework (e.g. ISO 27001) pre-fills a newly activated target
 * framework (e.g. NIS2) — gaps + source mappings + FTE-savings on one page.
 *
 * @see docs/CM_JUNIOR_RESPONSE.md CM-2
 */
#[Route('/delta-assessment')]
#[IsGranted('ROLE_MANAGER')]
final class DeltaAssessmentController extends AbstractController
{
    public function __construct(
        private readonly DeltaAssessmentExcelExporter $exporter,
        private readonly ComplianceFrameworkRepository $frameworkRepository,
        private readonly TenantContext $tenantContext,
        private readonly ExcelExportService $excelExportService,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    #[Route('/{targetFrameworkCode}/excel', name: 'app_delta_assessment_excel', methods: ['GET'])]
    public function excel(string $targetFrameworkCode, Request $request): Response
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null) {
            throw $this->createAccessDeniedException('Tenant context missing.');
        }

        $targetFramework = $this->frameworkRepository->findOneBy(['code' => strtoupper($targetFrameworkCode)]);
        if (!$targetFramework instanceof ComplianceFramework) {
            throw $this->createNotFoundException(sprintf('Framework "%s" not found.', $targetFrameworkCode));
        }

        $baselineCode = $request->query->get('baseline');
        $baselineFramework = null;
        if (is_string($baselineCode) && $baselineCode !== '') {
            $baselineFramework = $this->frameworkRepository->findOneBy(['code' => strtoupper($baselineCode)]);
            if (!$baselineFramework instanceof ComplianceFramework) {
                throw $this->createNotFoundException(sprintf('Baseline framework "%s" not found.', $baselineCode));
            }
        }

        $spreadsheet = $this->exporter->export($tenant, $targetFramework, $baselineFramework);

        $this->auditLogger->logExport(
            'ComplianceFramework',
            $targetFramework->id,
            sprintf(
                'Delta-Assessment export: target=%s, baseline=%s',
                (string) $targetFramework->getCode(),
                $baselineFramework?->getCode() ?? 'none',
            ),
        );

        $filename = sprintf(
            'delta-assessment_%s%s_%s.xlsx',
            strtolower((string) $targetFramework->getCode()),
            $baselineFramework !== null
                ? '_vs_' . strtolower((string) $baselineFramework->getCode())
                : '',
            (new \DateTimeImmutable())->format('Ymd'),
        );

        $response = new StreamedResponse(function () use ($spreadsheet): void {
            echo $this->excelExportService->generateExcel($spreadsheet);
        });

        $safeFilename = preg_replace('/[^\w\s\.\-]/', '', $filename) ?? 'delta-assessment.xlsx';
        $response->headers->set(
            'Content-Type',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        );
        $response->headers->set(
            'Content-Disposition',
            sprintf('attachment; filename="%s"', $safeFilename),
        );

        return $response;
    }
}
