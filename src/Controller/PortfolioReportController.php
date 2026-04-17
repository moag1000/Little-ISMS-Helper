<?php

namespace App\Controller;

use App\Service\CompliancePolicyService;
use App\Service\PortfolioReportService;
use App\Service\TenantContext;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Portfolio Report Controller
 *
 * WS-4 (Data-Reuse Improvement Plan v1.1): Cross-Framework Portfolio Management-Report.
 * Renders a NIST-CSF x Framework matrix to give CISO / Head of GRC a one-page
 * portfolio view of compliance coverage across all activated frameworks.
 *
 * @see docs/DATA_REUSE_IMPROVEMENT_PLAN.md WS-4
 */
#[Route('/reports/management/portfolio')]
#[IsGranted('ROLE_MANAGER')]
class PortfolioReportController extends AbstractController
{
    public function __construct(
        private readonly PortfolioReportService $portfolioReportService,
        private readonly TenantContext $tenantContext,
        private readonly CompliancePolicyService $policy,
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

        return $this->render('portfolio_report/index.html.twig', [
            'matrix' => $matrix,
            'tenant' => $tenant,
            'stichtag' => $stichtag,
            'vorperiode' => $vorperiode,
            'thresholds' => $this->thresholds(),
        ]);
    }

    #[Route('/pdf', name: 'app_management_reports_portfolio_pdf', methods: ['GET'])]
    public function pdf(Request $request): Response
    {
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

        return $this->render('portfolio_report/pdf.html.twig', [
            'matrix' => $matrix,
            'tenant' => $tenant,
            'stichtag' => $stichtag,
            'vorperiode' => $vorperiode,
            'generated_at' => new DateTimeImmutable(),
            'thresholds' => $this->thresholds(),
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
