<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ReportsController extends AbstractController
{
    #[Route('/about/reports', name: 'app_reports_overview')]
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
        $securityReport2025File = $projectDir . '/docs/reports/security-audit-owasp-2025-rc1.md';
        $securityReport2021File = $projectDir . '/docs/reports/security-audit-owasp-2021.md';

        $securityStats = [
            'owasp_2025' => [
                'exists' => file_exists($securityReport2025File),
                'updated_at' => file_exists($securityReport2025File) ? date('Y-m-d', filemtime($securityReport2025File)) : null,
            ],
            'owasp_2021' => [
                'exists' => file_exists($securityReport2021File),
                'updated_at' => file_exists($securityReport2021File) ? date('Y-m-d', filemtime($securityReport2021File)) : null,
            ],
        ];

        return $this->render('reports/overview.html.twig', [
            'license_stats' => $licenseStats,
            'security_stats' => $securityStats,
        ]);
    }
}
