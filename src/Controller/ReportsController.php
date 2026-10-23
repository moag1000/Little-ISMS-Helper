<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\SecurityAuditService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ReportsController extends AbstractController
{
    public function __construct(private readonly SecurityAuditService $securityAuditService)
    {
    }

    #[Route('/about/reports', name: 'app_reports_overview', methods: ['GET'])]
    public function overview(): Response
    {
        $projectDir = $this->getParameter('kernel.project_dir');

        // Check for license report
        $licenseReportFile = $projectDir . '/docs/reports/license-report.md';
        $licenseStats = [
            'exists' => file_exists($licenseReportFile),
            'updated_at' => file_exists($licenseReportFile) ? date('Y-m-d', filemtime($licenseReportFile)) : null,
        ];

        // Check for security reports
        $securityStats = [];
        foreach (['owasp_2025' => SecurityAuditService::EDITION_2025, 'owasp_2021' => SecurityAuditService::EDITION_2021] as $key => $edition) {
            $file = $this->securityAuditService->reportPath($edition);
            $securityStats[$key] = [
                'exists' => $file !== null,
                'updated_at' => $file !== null ? date('Y-m-d', (int) filemtime($file)) : null,
            ];
        }

        return $this->render('reports/overview.html.twig', [
            'license_stats' => $licenseStats,
            'security_stats' => $securityStats,
        ]);
    }
}
