<?php

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Trait\ModuleGatedControllerTrait;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceRequirementFulfillmentRepository;
use App\Repository\ReuseTrendSnapshotRepository;
use App\Service\CompliancePolicyService;
use App\Service\ExcelExportService;
use App\Service\InheritanceMetricsService;
use App\Service\ModuleConfigurationService;
use App\Service\PortfolioReportService;
use App\Service\TenantContext;
use DateTimeImmutable;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Portfolio Report Controller
 *
 * WS-4 (Data-Reuse Improvement Plan v1.1): Cross-Framework Portfolio Management-Report.
 * Renders a NIST-CSF x Framework matrix to give CISO / Head of GRC a one-page
 * portfolio view of compliance coverage across all activated frameworks.
 *
 * @see docs/DATA_REUSE_IMPROVEMENT_PLAN.md WS-4
 */
// @no-methods-required — class-level path prefix, methods declared per action
#[Route('/reports/management/portfolio')]
#[IsGranted('ROLE_MANAGER')]
class PortfolioReportController extends AbstractController
{
    use ModuleGatedControllerTrait;

    public function __construct(
        private readonly PortfolioReportService $portfolioReportService,
        private readonly TenantContext $tenantContext,
        private readonly CompliancePolicyService $policy,
        private readonly ExcelExportService $excelExportService,
        private readonly InheritanceMetricsService $inheritanceMetricsService,
        private readonly ComplianceFrameworkRepository $frameworkRepository,
        private readonly ComplianceRequirementFulfillmentRepository $fulfillmentRepository,
        private readonly ModuleConfigurationService $moduleService,
        private readonly TranslatorInterface $translator,
        private readonly ?ReuseTrendSnapshotRepository $reuseTrendRepository = null,
    ) {
    }

    /**
     * @return array{green:int, yellow:int}
     */
    private function thresholds(): array
    {
        return [
            'green' => $this->policy->getInt(CompliancePolicyService::KEY_PORTFOLIO_GREEN, 80),
            'yellow' => $this->policy->getInt(CompliancePolicyService::KEY_PORTFOLIO_YELLOW, 50),
        ];
    }

    #[Route('', name: 'app_management_reports_portfolio', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if ($redirect = $this->checkModuleActive('portfolio_reports')) return $redirect;
        $tenant = $this->tenantContext->getCurrentTenant();

        if ($tenant === null) {
            $this->addFlash('warning', 'portfolio_report.flash.no_tenant');

            return $this->redirectToRoute('app_management_reports');
        }

        $stichtag = $this->parseDate($request->query->get('stichtag'), new DateTimeImmutable());
        $vorperiodeRaw = $request->query->get('vorperiode');
        $vorperiode = $vorperiodeRaw !== null && $vorperiodeRaw !== ''
            ? $this->parseDate($vorperiodeRaw, $stichtag)
            : null;

        $matrix = $this->portfolioReportService->buildMatrixWithTrend($tenant, $stichtag, $vorperiode);
        $inheritanceMetrics = $this->inheritanceMetricsService->metricsForTenant($tenant);
        $fteSaved = $this->inheritanceMetricsService->fteSavedForTenant($tenant);

        // R3 Reuse-Trend: 12-Monats-Verlauf als Chart-Daten (wenn Snapshots existieren)
        $reuseTrend = [];
        if ($this->reuseTrendRepository !== null) {
            foreach ($this->reuseTrendRepository->findRecentForTenant($tenant, 12) as $s) {
                $reuseTrend[] = [
                    'day' => $s->getCapturedDay()->format('Y-m-d'),
                    'fte_saved' => $s->getFteSavedTotal(),
                    'inheritance_pct' => $s->getInheritanceRatePct(),
                ];
            }
        }

        return $this->render('portfolio_report/index.html.twig', [
            'matrix' => $matrix,
            'tenant' => $tenant,
            'stichtag' => $stichtag,
            'vorperiode' => $vorperiode,
            'thresholds' => $this->thresholds(),
            'inheritance_metrics' => $inheritanceMetrics,
            'fte_saved' => $fteSaved,
            'reuse_trend' => $reuseTrend,
        ]);
    }

    #[Route('/pdf', name: 'app_management_reports_portfolio_pdf', methods: ['GET'])]
    public function pdf(Request $request): Response
    {
        if ($redirect = $this->checkModuleActive('portfolio_reports')) return $redirect;
        $tenant = $this->tenantContext->getCurrentTenant();

        if ($tenant === null) {
            $this->addFlash('warning', 'portfolio_report.flash.no_tenant');

            return $this->redirectToRoute('app_management_reports');
        }

        $stichtag = $this->parseDate($request->query->get('stichtag'), new DateTimeImmutable());
        $vorperiodeRaw = $request->query->get('vorperiode');
        $vorperiode = $vorperiodeRaw !== null && $vorperiodeRaw !== ''
            ? $this->parseDate($vorperiodeRaw, $stichtag)
            : null;

        $matrix = $this->portfolioReportService->buildMatrixWithTrend($tenant, $stichtag, $vorperiode);

        return $this->render('portfolio_report/pdf.html.twig', [
            'matrix' => $matrix,
            'tenant' => $tenant,
            'stichtag' => $stichtag,
            'vorperiode' => $vorperiode,
            'generated_at' => new DateTimeImmutable(),
            'thresholds' => $this->thresholds(),
        ]);
    }

    #[Route('/excel', name: 'app_management_reports_portfolio_excel', methods: ['GET'])]
    public function excel(Request $request): Response
    {
        if ($redirect = $this->checkModuleActive('portfolio_reports')) return $redirect;
        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null) {
            $this->addFlash('warning', 'portfolio_report.flash.no_tenant');
            return $this->redirectToRoute('app_management_reports');
        }

        $stichtag = $this->parseDate($request->query->get('stichtag'), new DateTimeImmutable());
        $vorperiodeRaw = $request->query->get('vorperiode');
        $vorperiode = $vorperiodeRaw !== null && $vorperiodeRaw !== ''
            ? $this->parseDate($vorperiodeRaw, $stichtag)
            : null;

        $matrix = $this->portfolioReportService->buildMatrix($tenant, $stichtag, $vorperiode);
        $spreadsheet = $this->buildWorkbook($matrix, (string) $tenant->getName(), $stichtag, $vorperiode);

        $filename = sprintf('portfolio-report-%s.xlsx', $stichtag->format('Y-m-d'));
        $response = new StreamedResponse(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
        });
        $response->headers->set(
            'Content-Type',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        );
        $response->headers->set(
            'Content-Disposition',
            sprintf('attachment; filename="%s"', $filename)
        );
        $response->headers->set('Cache-Control', 'max-age=0');
        return $response;
    }

    /**
     * @param array{frameworks: list<array{code: string, name: string}>, rows: list<array{category: string, cells: array<string, array{pct: int|float, count: int}>}>} $matrix
     */
    private function buildWorkbook(
        array $matrix,
        string $tenantName,
        \DateTimeInterface $stichtag,
        ?\DateTimeInterface $vorperiode,
    ): Spreadsheet {
        $thresholds = $this->thresholds();

        $spreadsheet = $this->excelExportService->createSpreadsheet('Portfolio Compliance Report');
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Portfolio');

        // Header block
        $sheet->setCellValue('A1', 'Cross-Framework Portfolio Report');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->setCellValue('A2', sprintf('Tenant: %s', $tenantName));
        $sheet->setCellValue('A3', sprintf('Stichtag: %s', $stichtag->format('Y-m-d')));
        if ($vorperiode !== null) {
            $sheet->setCellValue('A4', sprintf('Vorperiode: %s', $vorperiode->format('Y-m-d')));
        }

        // Column headers: Kategorie + per-framework columns
        $headerRow = 6;
        $sheet->setCellValue('A' . $headerRow, 'Kategorie');
        // Column A = Kategorie, one column per framework after it. `--$col`
        // on a letter string is a no-op in PHP, so derive the last column
        // from its index instead of stepping the letter back.
        $lastCol = Coordinate::stringFromColumnIndex(1 + count($matrix['frameworks']));
        foreach ($matrix['frameworks'] as $i => $framework) {
            $col = Coordinate::stringFromColumnIndex(2 + $i);
            $sheet->setCellValue($col . $headerRow, $framework['name']);
            $sheet->getStyle($col . $headerRow)->getFont()->setBold(true);
        }
        $sheet->getStyle('A' . $headerRow . ':' . $lastCol . $headerRow)->getFill()
            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('0D6EFD');
        $sheet->getStyle('A' . $headerRow . ':' . $lastCol . $headerRow)->getFont()
            ->getColor()->setRGB('FFFFFF');

        // Data rows
        $row = $headerRow + 1;
        foreach ($matrix['rows'] as $dataRow) {
            $sheet->setCellValue('A' . $row, $dataRow['category']);
            foreach ($matrix['frameworks'] as $i => $framework) {
                $col = Coordinate::stringFromColumnIndex(2 + $i);
                $cell = $dataRow['cells'][$framework['code']] ?? ['pct' => 0, 'count' => 0];
                $label = $cell['count'] === 0
                    ? 'n/a'
                    : sprintf('%d%% (%d req)', $cell['pct'], $cell['count']);
                $sheet->setCellValue($col . $row, $label);
                if ($cell['count'] > 0) {
                    $rgb = $cell['pct'] >= $thresholds['green'] ? '198754'
                        : ($cell['pct'] >= $thresholds['yellow'] ? 'FFC107' : 'DC3545');
                    $sheet->getStyle($col . $row)->getFill()
                        ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($rgb);
                    if ($rgb !== 'FFC107') {
                        $sheet->getStyle($col . $row)->getFont()->getColor()->setRGB('FFFFFF');
                    }
                }
            }
            $row++;
        }

        // Auto-size columns (range('A', 'AA') would stop at 'A', so iterate indices)
        for ($i = 1, $last = Coordinate::columnIndexFromString($lastCol); $i <= $last; $i++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setAutoSize(true);
        }
        $sheet->getStyle('A' . $headerRow . ':' . $lastCol . max($headerRow, $row - 1))
            ->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);

        return $spreadsheet;
    }

    /**
     * Drill-down on a single matrix cell.
     *
     * Lists every ComplianceRequirement of $frameworkCode that maps to the
     * given NIST CSF $category, along with the tenant's fulfillment record
     * (if any) so the manager can click through to the specific requirement.
     *
     * @see docs/CM_JUNIOR_RESPONSE.md CM-3
     */
    #[Route(
        '/drill/{frameworkCode}/{category}',
        name: 'app_management_reports_portfolio_drill',
        requirements: [
            'frameworkCode' => '[A-Za-z0-9\-_]+',
            'category' => 'Govern|Identify|Protect|Detect|Respond|Recover',
        ],
        methods: ['GET'],
    )]
    public function drill(Request $request, string $frameworkCode, string $category): Response
    {
        if ($redirect = $this->checkModuleActive('portfolio_reports')) return $redirect;
        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null) {
            $this->addFlash('warning', 'portfolio_report.flash.no_tenant');
            return $this->redirectToRoute('app_management_reports');
        }

        $stichtag = $this->parseDate($request->query->get('stichtag'), new DateTimeImmutable());

        $framework = $this->frameworkRepository->findOneBy(['code' => $frameworkCode]);
        if ($framework === null) {
            throw $this->createNotFoundException(sprintf('Framework "%s" not found.', $frameworkCode));
        }

        $requirements = $this->portfolioReportService->findRequirementsForCell($framework, $category);

        // Pre-index tenant fulfillments for O(1) lookup by requirement id.
        $fulfillmentsByRequirementId = [];
        foreach ($this->fulfillmentRepository->findByFrameworkAndTenant($framework, $tenant) as $fulfillment) {
            $req = $fulfillment->getRequirement();
            if ($req !== null && $req->getId() !== null) {
                $fulfillmentsByRequirementId[$req->getId()] = $fulfillment;
            }
        }

        $rows = [];
        foreach ($requirements as $requirement) {
            $fulfillment = $requirement->getId() !== null
                ? ($fulfillmentsByRequirementId[$requirement->getId()] ?? null)
                : null;
            $rows[] = [
                'requirement' => $requirement,
                'fulfillment' => $fulfillment,
                'pct' => $fulfillment?->getFulfillmentPercentage() ?? 0,
                'applicable' => $fulfillment?->isApplicable() ?? true,
            ];
        }

        return $this->render('portfolio_report/drill.html.twig', [
            'framework' => $framework,
            'category' => $category,
            'rows' => $rows,
            'stichtag' => $stichtag,
            'tenant' => $tenant,
        ]);
    }

    /**
     * Parse a YYYY-MM-DD date string; fall back to $default on invalid input.
     */
    private function parseDate(mixed $raw, \DateTimeInterface $default): \DateTimeInterface
    {
        if (!is_string($raw) || $raw === '') {
            return $default;
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $raw);

        return $parsed !== false ? $parsed : $default;
    }
}
